<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Governance;

use RJV_AGI_Bridge\Bridge\TenantIsolation;

/**
 * API contract/version governance and deprecation envelope manager.
 */
final class ContractManager {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function get_contract(): array {
        $defaults = [
            'contract_id' => 'rjv-agi-v1',
            'api_version' => 'v1',
            'compatibility_policy' => 'Backward-compatible additive changes only within major version',
            'envelope' => [
                'required_keys' => ['success', 'data'],
                'error_shape' => ['code', 'message', 'status'],
            ],
            'deprecation_policy' => [
                'notice_days' => 90,
                'sunset_header' => true,
                'replacement_required' => true,
            ],
        ];

        $stored = TenantIsolation::instance()->get_option('rjv_agi_api_contract', []);
        return is_array($stored) ? array_replace_recursive($defaults, $stored) : $defaults;
    }

    public function update_contract(array $contract): array {
        $existing = $this->get_contract();
        $updated = array_replace_recursive($existing, $contract);
        TenantIsolation::instance()->set_option('rjv_agi_api_contract', $updated);
        return ['success' => true, 'contract' => $updated];
    }

    public function list_deprecations(): array {
        $items = TenantIsolation::instance()->get_option('rjv_agi_api_deprecations', []);
        if (!is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $route = sanitize_text_field((string) ($row['route_pattern'] ?? ''));
            if ($route === '') {
                continue;
            }
            $methods = array_values(array_filter(array_map(static fn($m) => strtoupper(sanitize_text_field((string) $m)), (array) ($row['methods'] ?? []))));
            $clean[] = [
                'id' => sanitize_key((string) ($row['id'] ?? 'dep_' . md5($route . wp_json_encode($methods)))),
                'route_pattern' => $route,
                'methods' => !empty($methods) ? $methods : ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'replacement' => sanitize_text_field((string) ($row['replacement'] ?? '')),
                'deprecated_at' => sanitize_text_field((string) ($row['deprecated_at'] ?? '')),
                'sunset_at' => sanitize_text_field((string) ($row['sunset_at'] ?? '')),
                'docs_url' => esc_url_raw((string) ($row['docs_url'] ?? '')),
            ];
        }
        return $clean;
    }

    public function replace_deprecations(array $deprecations): array {
        $clean = [];
        foreach ($deprecations as $item) {
            if (!is_array($item)) {
                continue;
            }
            $route = sanitize_text_field((string) ($item['route_pattern'] ?? ''));
            if ($route === '') {
                continue;
            }
            $clean[] = [
                'id' => sanitize_key((string) ($item['id'] ?? 'dep_' . md5($route . wp_json_encode($item)))),
                'route_pattern' => $route,
                'methods' => array_values(array_filter(array_map(static fn($m) => strtoupper(sanitize_text_field((string) $m)), (array) ($item['methods'] ?? [])))),
                'replacement' => sanitize_text_field((string) ($item['replacement'] ?? '')),
                'deprecated_at' => sanitize_text_field((string) ($item['deprecated_at'] ?? '')),
                'sunset_at' => sanitize_text_field((string) ($item['sunset_at'] ?? '')),
                'docs_url' => esc_url_raw((string) ($item['docs_url'] ?? '')),
            ];
        }

        TenantIsolation::instance()->set_option('rjv_agi_api_deprecations', $clean);
        return ['success' => true, 'deprecations' => $clean];
    }

    public function evaluate_deprecation(string $route, string $method): ?array {
        $method = strtoupper($method);
        foreach ($this->list_deprecations() as $rule) {
            if (!$this->route_matches($route, (string) $rule['route_pattern'])) {
                continue;
            }
            if (!in_array($method, (array) $rule['methods'], true)) {
                continue;
            }
            return $rule;
        }
        return null;
    }

    public function attach_headers($response, string $route, string $method) {
        if (!method_exists($response, 'header')) {
            return $response;
        }

        $contract = $this->get_contract();
        $response->header('X-RJV-API-Version', (string) ($contract['api_version'] ?? 'v1'));
        $response->header('X-RJV-Contract-ID', (string) ($contract['contract_id'] ?? 'rjv-agi-v1'));

        $deprecation = $this->evaluate_deprecation($route, $method);
        if ($deprecation === null) {
            return $response;
        }

        $response->header('Deprecation', 'true');
        if (!empty($deprecation['sunset_at'])) {
            $response->header('Sunset', (string) $deprecation['sunset_at']);
        }
        if (!empty($deprecation['replacement'])) {
            $response->header('X-RJV-Replacement-Route', (string) $deprecation['replacement']);
        }
        if (!empty($deprecation['docs_url'])) {
            $response->header('Link', '<' . (string) $deprecation['docs_url'] . '>; rel="deprecation"');
        }

        return $response;
    }

    private function route_matches(string $route, string $pattern): bool {
        if ($pattern === '') {
            return false;
        }
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
            return preg_match($regex, $route) === 1;
        }
        return str_starts_with($route, $pattern);
    }
}

