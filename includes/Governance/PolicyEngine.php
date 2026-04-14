<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Governance;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Bridge\CapabilityGate;
use RJV_AGI_Bridge\Bridge\TenantIsolation;

/**
 * Runtime governance policy engine for API guardrails and approval routing.
 */
final class PolicyEngine {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function defaults(): array {
        return [
            'enforcement_enabled' => true,
            'rule_resolution' => 'priority_then_restrictiveness',
            'deny_routes' => [],
            'approval_routes' => [
                '/rjv-agi/v1/plugins',
                '/rjv-agi/v1/themes',
                '/rjv-agi/v1/filesystem',
                '/rjv-agi/v1/database',
            ],
            'approval_methods' => ['DELETE'],
            'bypass_routes' => [
                '/rjv-agi/v1/approvals',
                '/rjv-agi/v1/health',
            ],
            'rules' => [],
        ];
    }

    public function get_policies(): array {
        $stored = TenantIsolation::instance()->get_option('rjv_agi_policy_rules', []);
        return is_array($stored) ? array_merge($this->defaults(), $stored) : $this->defaults();
    }

    public function update_policies(array $policies): array {
        $defaults = $this->defaults();
        $validated = $defaults;
        $validated['enforcement_enabled'] = (bool) ($policies['enforcement_enabled'] ?? $defaults['enforcement_enabled']);

        foreach (['deny_routes', 'approval_routes', 'approval_methods', 'bypass_routes'] as $listKey) {
            $value = $policies[$listKey] ?? $defaults[$listKey];
            if (!is_array($value)) {
                return ['success' => false, 'error' => "Policy {$listKey} must be an array"];
            }
            $validated[$listKey] = array_values(array_filter(array_map(
                static fn($v) => sanitize_text_field((string) $v),
                $value
            )));
        }

        $validated['rule_resolution'] = sanitize_key((string) ($policies['rule_resolution'] ?? $defaults['rule_resolution']));
        if (!in_array($validated['rule_resolution'], ['priority_then_restrictiveness', 'most_restrictive'], true)) {
            $validated['rule_resolution'] = 'priority_then_restrictiveness';
        }

        $validated['rules'] = $this->sanitize_rules((array) ($policies['rules'] ?? []));

        TenantIsolation::instance()->set_option('rjv_agi_policy_rules', $validated);
        return ['success' => true, 'policies' => $validated];
    }

    public function evaluate(\WP_REST_Request $request): array {
        $route = $request->get_route();
        $method = strtoupper($request->get_method());
        if (strpos($route, '/rjv-agi/v1/') !== 0) {
            return ['allowed' => true, 'requires_approval' => false];
        }

        $policies = $this->get_policies();
        if (($policies['enforcement_enabled'] ?? true) !== true) {
            return ['allowed' => true, 'requires_approval' => false, 'policy' => 'disabled'];
        }

        if ($this->matches_any($route, $policies['bypass_routes'] ?? [])) {
            return ['allowed' => true, 'requires_approval' => false, 'policy' => 'bypass'];
        }

        $capability_action = $this->map_request_to_capability_action($route, $method);
        if ($capability_action !== null && !CapabilityGate::instance()->can($capability_action)) {
            return [
                'allowed' => false,
                'requires_approval' => false,
                'reason' => 'Route blocked by capability gate',
                'policy' => 'capability_gate',
            ];
        }

        $typedResult = $this->evaluate_typed_rules($route, $method, $policies);
        if ($typedResult !== null) {
            $this->audit_decision($route, $method, $typedResult);
            return $typedResult;
        }

        if ($this->matches_any($route, $policies['deny_routes'] ?? [])) {
            $result = [
                'allowed' => false,
                'requires_approval' => false,
                'reason' => 'Route denied by policy',
                'policy' => 'deny_routes',
            ];
            $this->audit_decision($route, $method, $result);
            return $result;
        }

        $approvalMethods = array_map('strtoupper', $policies['approval_methods'] ?? []);
        $requiresApprovalByMethod = in_array($method, $approvalMethods, true);
        $requiresApprovalByRoute = $this->matches_any($route, $policies['approval_routes'] ?? []);
        $isReadMethod = in_array($method, ['GET', 'HEAD'], true);

        $result = ($requiresApprovalByMethod || ($requiresApprovalByRoute && !$isReadMethod))
            ? [
                'allowed' => true,
                'requires_approval' => true,
                'reason' => 'Approval required by policy',
                'policy' => $requiresApprovalByMethod ? 'approval_methods' : 'approval_routes',
            ]
            : ['allowed' => true, 'requires_approval' => false];

        $this->audit_decision($route, $method, $result);
        return $result;
    }

    private function evaluate_typed_rules(string $route, string $method, array $policies): ?array {
        $rules = $this->sanitize_rules((array) ($policies['rules'] ?? []));
        if (empty($rules)) {
            return null;
        }

        $matched = array_values(array_filter($rules, fn($rule) => $this->rule_matches($route, $method, $rule)));
        if (empty($matched)) {
            return null;
        }

        usort($matched, static function (array $a, array $b): int {
            $prio = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
            if ($prio !== 0) {
                return $prio;
            }
            $order = ['deny' => 4, 'escalate' => 3, 'approve' => 2, 'allow' => 1];
            return (($order[$b['type']] ?? 0) <=> ($order[$a['type']] ?? 0));
        });

        $winner = $matched[0];
        $type = (string) $winner['type'];

        $base = [
            'policy' => 'typed_rule',
            'rule_id' => $winner['id'],
            'rule_type' => $type,
            'matched_rule_ids' => array_values(array_map(static fn($r) => $r['id'], $matched)),
            'reason' => (string) ($winner['reason'] ?? 'Policy rule applied'),
        ];

        return match ($type) {
            'deny' => $base + ['allowed' => false, 'requires_approval' => false],
            'approve' => $base + ['allowed' => true, 'requires_approval' => true, 'escalated' => false],
            'escalate' => $base + ['allowed' => true, 'requires_approval' => true, 'escalated' => true, 'requires_role' => $winner['requires_role'] ?? 'administrator'],
            default => $base + ['allowed' => true, 'requires_approval' => false],
        };
    }

    private function sanitize_rules(array $rules): array {
        $clean = [];
        foreach ($rules as $idx => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $type = sanitize_key((string) ($rule['type'] ?? ''));
            if (!in_array($type, ['allow', 'deny', 'approve', 'escalate'], true)) {
                continue;
            }
            $pattern = sanitize_text_field((string) ($rule['route_pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $clean[] = [
                'id' => sanitize_key((string) ($rule['id'] ?? "rule_{$idx}")),
                'type' => $type,
                'priority' => (int) ($rule['priority'] ?? 50),
                'route_pattern' => $pattern,
                'methods' => array_values(array_filter(array_map(
                    static fn($m) => strtoupper(sanitize_text_field((string) $m)),
                    (array) ($rule['methods'] ?? [])
                ))),
                'requires_role' => sanitize_key((string) ($rule['requires_role'] ?? 'administrator')),
                'reason' => sanitize_text_field((string) ($rule['reason'] ?? '')),
            ];
        }
        return $clean;
    }

    private function rule_matches(string $route, string $method, array $rule): bool {
        if (!$this->matches_any($route, [(string) ($rule['route_pattern'] ?? '')])) {
            return false;
        }
        $methods = (array) ($rule['methods'] ?? []);
        if (empty($methods)) {
            return true;
        }
        return in_array($method, $methods, true);
    }

    private function audit_decision(string $route, string $method, array $decision): void {
        AuditLog::log('policy_evaluated', 'governance', 0, [
            'route' => $route,
            'method' => $method,
            'allowed' => (bool) ($decision['allowed'] ?? true),
            'requires_approval' => (bool) ($decision['requires_approval'] ?? false),
            'policy' => (string) ($decision['policy'] ?? 'unknown'),
            'rule_id' => (string) ($decision['rule_id'] ?? ''),
            'matched_rule_ids' => (array) ($decision['matched_rule_ids'] ?? []),
        ], 2);
    }

    private function matches_any(string $route, array $patterns): bool {
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            // Wildcard route support, e.g. /rjv-agi/v1/plugins*
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                if (preg_match($regex, $route) === 1) {
                    return true;
                }
                continue;
            }

            if (str_starts_with($route, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function map_request_to_capability_action(string $route, string $method): ?string {
        $map = [
            '/rjv-agi/v1/posts' => ['POST' => 'create_post', 'PUT' => 'update_post', 'DELETE' => 'delete_post'],
            '/rjv-agi/v1/pages' => ['POST' => 'create_page', 'PUT' => 'update_page', 'DELETE' => 'delete_page'],
            '/rjv-agi/v1/media' => ['POST' => 'upload_media', 'DELETE' => 'delete_media'],
            '/rjv-agi/v1/agents' => ['POST' => 'deploy_agent', 'DELETE' => 'stop_agent'],
            '/rjv-agi/v1/integrations' => ['POST' => 'integration_connect'],
            '/rjv-agi/v1/design' => ['PUT' => 'update_design_system'],
            '/rjv-agi/v1/security/scan' => ['POST' => 'vulnerability_scan'],
            '/rjv-agi/v1/database' => ['POST' => 'db_query'],
            '/rjv-agi/v1/filesystem' => ['POST' => 'file_write'],
        ];

        foreach ($map as $prefix => $actions) {
            if (str_starts_with($route, $prefix)) {
                return $actions[$method] ?? null;
            }
        }
        return null;
    }
}
