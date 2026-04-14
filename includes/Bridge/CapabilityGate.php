<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Bridge;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Settings;

/**
 * Capability Gating System
 *
 * Controls access to plugin features based on subscription plan and tenant configuration.
 * Ensures no action exceeds the granted capabilities.
 */
final class CapabilityGate {
    private static ?self $instance = null;
    private PlatformConnector $connector;
    private array $capability_map;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->connector = PlatformConnector::instance();
        $this->capability_map = $this->build_capability_map();
    }

    /**
     * Check if an action is allowed based on current capabilities
     */
    public function can(string $action, array $context = []): bool {
        $capability = $this->map_action_to_capability($action);
        if ($capability === null) {
            return true; // Unknown actions are allowed by default
        }

        if (!$this->has_capability($capability)) {
            AuditLog::log('capability_denied', 'gate', 0, [
                'action' => $action,
                'capability' => $capability,
            ], 1, 'error');
            return false;
        }

        // Check usage limits if applicable
        $limit_check = $this->check_limits($action, $context);
        if (!$limit_check['allowed']) {
            AuditLog::log('limit_exceeded', 'gate', 0, [
                'action' => $action,
                'limit' => $limit_check['limit'],
                'current' => $limit_check['current'],
            ], 1, 'error');
            return false;
        }

        return true;
    }

    /**
     * Execute action if allowed, with pre/post hooks
     */
    public function execute(string $action, callable $callback, array $context = []): array {
        if (!$this->can($action, $context)) {
            return [
                'success' => false,
                'error' => 'Action not permitted by current plan',
                'action' => $action,
            ];
        }

        $start = microtime(true);

        try {
            $result = $callback();
            $duration = (int) ((microtime(true) - $start) * 1000);

            AuditLog::log("action_{$action}", 'gate', 0, [
                'context' => $context,
                'duration_ms' => $duration,
            ], 1);

            return [
                'success' => true,
                'result' => $result,
                'duration_ms' => $duration,
            ];
        } catch (\Throwable $e) {
            AuditLog::log("action_{$action}_failed", 'gate', 0, [
                'error' => $e->getMessage(),
            ], 1, 'error');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all available capabilities for current tenant
     */
    public function get_available(): array {
        return $this->connector->get_capabilities();
    }

    /**
     * Get effective capabilities with environment-level overrides.
     */
    public function get_effective_capabilities(?string $environment = null): array {
        $environment = $environment ?: (function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production');
        $base = $this->get_available();
        $overrides = $this->get_environment_overrides();
        $envOverride = is_array($overrides[$environment] ?? null) ? $overrides[$environment] : [];

        if (!empty($envOverride['enabled']) && is_array($envOverride['enabled'])) {
            $base['enabled'] = array_values(array_unique(array_merge(
                (array) ($base['enabled'] ?? []),
                array_map(static fn($v) => sanitize_key((string) $v), $envOverride['enabled'])
            )));
        }

        if (!empty($envOverride['disabled']) && is_array($envOverride['disabled'])) {
            $disabled = array_map(static fn($v) => sanitize_key((string) $v), $envOverride['disabled']);
            $base['enabled'] = array_values(array_filter(
                (array) ($base['enabled'] ?? []),
                static fn($cap) => !in_array($cap, $disabled, true)
            ));
        }

        if (!empty($envOverride['limits']) && is_array($envOverride['limits'])) {
            $base['limits'] = array_merge((array) ($base['limits'] ?? []), $envOverride['limits']);
        }

        if (!empty($envOverride['features']) && is_array($envOverride['features'])) {
            $base['features'] = array_merge((array) ($base['features'] ?? []), $envOverride['features']);
        }

        return $base;
    }

    /**
     * Check if specific capability is enabled in effective capability set.
     */
    public function has_capability(string $capability, ?string $environment = null): bool {
        $capabilities = $this->get_effective_capabilities($environment);
        return in_array($capability, (array) ($capabilities['enabled'] ?? []), true);
    }

    /**
     * Get capability overrides per environment.
     */
    public function get_environment_overrides(): array {
        $value = get_option('rjv_agi_capability_overrides', []);
        return is_array($value) ? $value : [];
    }

    /**
     * Update capability overrides per environment.
     */
    public function update_environment_overrides(array $overrides): array {
        $validated = [];
        foreach ($overrides as $environment => $rules) {
            $environment = sanitize_key((string) $environment);
            if ($environment === '' || !is_array($rules)) {
                continue;
            }

            $validated[$environment] = [
                'enabled' => array_values(array_filter(array_map(
                    static fn($v) => sanitize_key((string) $v),
                    (array) ($rules['enabled'] ?? [])
                ))),
                'disabled' => array_values(array_filter(array_map(
                    static fn($v) => sanitize_key((string) $v),
                    (array) ($rules['disabled'] ?? [])
                ))),
                'limits' => is_array($rules['limits'] ?? null) ? $rules['limits'] : [],
                'features' => is_array($rules['features'] ?? null) ? $rules['features'] : [],
            ];
        }

        update_option('rjv_agi_capability_overrides', $validated);
        return $validated;
    }

    /**
     * Get current usage statistics
     */
    public function get_usage(): array {
        global $wpdb;
        $today = gmdate('Y-m-d 00:00:00');
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;

        return [
            'ai_requests_today' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE action LIKE %s AND timestamp >= %s",
                'ai_%',
                $today
            )),
            'content_changes_today' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE (action LIKE %s OR action LIKE %s) AND timestamp >= %s",
                'create_%',
                'update_%',
                $today
            )),
            'agents_active' => $this->count_active_agents(),
            'integrations_active' => $this->count_active_integrations(),
        ];
    }

    /**
     * Map action string to required capability
     */
    private function map_action_to_capability(string $action): ?string {
        foreach ($this->capability_map as $capability => $actions) {
            if (in_array($action, $actions, true)) {
                return $capability;
            }
        }
        return null;
    }

    /**
     * Build capability to action mapping
     */
    private function build_capability_map(): array {
        return [
            'content_management' => [
                'create_post', 'update_post', 'delete_post',
                'create_page', 'update_page', 'delete_page',
                'bulk_posts', 'bulk_pages',
            ],
            'media_management' => [
                'upload_media', 'sideload_media', 'delete_media',
                'optimize_media',
            ],
            'basic_ai' => [
                'ai_complete', 'ai_rewrite',
            ],
            'advanced_ai' => [
                'ai_generate_post', 'ai_generate_seo',
                'ai_analyze', 'ai_workflow',
            ],
            'design_system' => [
                'apply_design_tokens', 'update_design_system',
                'validate_accessibility', 'enforce_styles',
            ],
            'agent_execution' => [
                'deploy_agent', 'stop_agent',
                'agent_task', 'agent_workflow',
            ],
            'advanced_agents' => [
                'create_agent', 'multi_agent',
                'agent_orchestration',
            ],
            'real_time_events' => [
                'event_stream', 'event_subscribe',
                'webhook_trigger',
            ],
            'database_access' => [
                'db_query', 'db_optimize',
            ],
            'file_system' => [
                'file_read', 'file_write',
            ],
            'integrations' => [
                'integration_connect', 'integration_sync',
                'webhook_create',
            ],
            'security_monitoring' => [
                'vulnerability_scan', 'integrity_check',
                'anomaly_detection',
            ],
            'multi_tenant' => [
                'tenant_switch', 'tenant_isolate',
            ],
        ];
    }

    /**
     * Check usage limits for an action
     */
    private function check_limits(string $action, array $context): array {
        $capabilities = $this->get_effective_capabilities();
        $limits = $capabilities['limits'] ?? [];
        $usage = $this->get_usage();

        // Check AI request limits
        if (str_starts_with($action, 'ai_')) {
            $limit = $limits['ai_requests_daily'] ?? PHP_INT_MAX;
            if ($limit !== -1 && $usage['ai_requests_today'] >= $limit) {
                return [
                    'allowed' => false,
                    'limit' => $limit,
                    'current' => $usage['ai_requests_today'],
                ];
            }
        }

        // Check agent limits
        if (str_starts_with($action, 'deploy_agent') || str_starts_with($action, 'create_agent')) {
            $limit = $limits['agents_concurrent'] ?? PHP_INT_MAX;
            if ($limit !== -1 && $usage['agents_active'] >= $limit) {
                return [
                    'allowed' => false,
                    'limit' => $limit,
                    'current' => $usage['agents_active'],
                ];
            }
        }

        // Check integration limits
        if (str_starts_with($action, 'integration_')) {
            $limit = $limits['integrations'] ?? PHP_INT_MAX;
            if ($limit !== -1 && $usage['integrations_active'] >= $limit) {
                return [
                    'allowed' => false,
                    'limit' => $limit,
                    'current' => $usage['integrations_active'],
                ];
            }
        }

        return ['allowed' => true];
    }

    /**
     * Count currently active agents
     */
    private function count_active_agents(): int {
        $agents = get_option('rjv_agi_active_agents', []);
        return count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'running'));
    }

    /**
     * Count active integrations
     */
    private function count_active_integrations(): int {
        $integrations = get_option('rjv_agi_integrations', []);
        return count(array_filter($integrations, fn($i) => ($i['active'] ?? false)));
    }
}
