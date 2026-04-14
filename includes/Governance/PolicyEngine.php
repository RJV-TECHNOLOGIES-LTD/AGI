<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Governance;

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

        if ($this->matches_any($route, $policies['deny_routes'] ?? [])) {
            return [
                'allowed' => false,
                'requires_approval' => false,
                'reason' => 'Route denied by policy',
                'policy' => 'deny_routes',
            ];
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

        $approvalMethods = array_map('strtoupper', $policies['approval_methods'] ?? []);
        $requiresApprovalByMethod = in_array($method, $approvalMethods, true);
        $requiresApprovalByRoute = $this->matches_any($route, $policies['approval_routes'] ?? []);
        $isReadMethod = in_array($method, ['GET', 'HEAD'], true);

        if (($requiresApprovalByMethod || ($requiresApprovalByRoute && !$isReadMethod))) {
            return [
                'allowed' => true,
                'requires_approval' => true,
                'reason' => 'Approval required by policy',
                'policy' => $requiresApprovalByMethod ? 'approval_methods' : 'approval_routes',
            ];
        }

        return ['allowed' => true, 'requires_approval' => false];
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
