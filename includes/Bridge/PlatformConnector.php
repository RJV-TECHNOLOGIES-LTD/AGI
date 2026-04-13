<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Bridge;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Settings;

/**
 * Central AGI Platform Connector
 *
 * Establishes secure, bidirectional communication with the RJV AGI central platform.
 * Handles tenant identification, subscription validation, and capability gating.
 */
final class PlatformConnector {
    private static ?self $instance = null;
    private string $platform_url;
    private string $tenant_id;
    private string $tenant_secret;
    private ?array $cached_capabilities = null;
    private int $capabilities_ttl = 300; // 5 minutes cache

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->platform_url = (string) Settings::get('platform_url', 'https://platform.rjvtechnologies.com/api/v1');
        $this->tenant_id = (string) Settings::get('tenant_id', '');
        $this->tenant_secret = (string) Settings::get('tenant_secret', '');
    }

    /**
     * Check if platform connection is configured
     */
    public function is_configured(): bool {
        return !empty($this->tenant_id) && !empty($this->tenant_secret);
    }

    /**
     * Validate tenant subscription and retrieve capabilities
     */
    public function validate_subscription(): array {
        if (!$this->is_configured()) {
            return ['valid' => false, 'error' => 'Platform not configured'];
        }

        $cache_key = 'rjv_agi_subscription_' . md5($this->tenant_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $response = $this->request('GET', '/subscriptions/validate');
        if (isset($response['error'])) {
            AuditLog::log('subscription_validation_failed', 'bridge', 0, ['error' => $response['error']], 1, 'error');
            return ['valid' => false, 'error' => $response['error']];
        }

        $result = [
            'valid' => $response['data']['active'] ?? false,
            'plan' => $response['data']['plan'] ?? 'free',
            'expires' => $response['data']['expires'] ?? null,
            'features' => $response['data']['features'] ?? [],
            'limits' => $response['data']['limits'] ?? [],
        ];

        set_transient($cache_key, $result, $this->capabilities_ttl);
        AuditLog::log('subscription_validated', 'bridge', 0, ['plan' => $result['plan']], 1);
        return $result;
    }

    /**
     * Get tenant capabilities based on subscription plan
     */
    public function get_capabilities(): array {
        if ($this->cached_capabilities !== null) {
            return $this->cached_capabilities;
        }

        $cache_key = 'rjv_agi_capabilities_' . md5($this->tenant_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            $this->cached_capabilities = $cached;
            return $cached;
        }

        if (!$this->is_configured()) {
            return $this->get_default_capabilities();
        }

        $response = $this->request('GET', '/capabilities');
        if (isset($response['error'])) {
            return $this->get_default_capabilities();
        }

        $this->cached_capabilities = $response['data'] ?? $this->get_default_capabilities();
        set_transient($cache_key, $this->cached_capabilities, $this->capabilities_ttl);
        return $this->cached_capabilities;
    }

    /**
     * Check if a specific capability is enabled
     */
    public function has_capability(string $capability): bool {
        $capabilities = $this->get_capabilities();
        return in_array($capability, $capabilities['enabled'] ?? [], true);
    }

    /**
     * Check if a specific feature is within plan limits
     */
    public function within_limit(string $feature, int $current): bool {
        $capabilities = $this->get_capabilities();
        $limit = $capabilities['limits'][$feature] ?? PHP_INT_MAX;
        return $limit === -1 || $current < $limit;
    }

    /**
     * Sync state with central platform
     */
    public function sync_state(array $state): array {
        return $this->request('POST', '/sites/sync', $state);
    }

    /**
     * Report event to central platform
     */
    public function report_event(string $event_type, array $data): array {
        return $this->request('POST', '/events', [
            'event_type' => $event_type,
            'timestamp' => gmdate('c'),
            'site_url' => get_site_url(),
            'data' => $data,
        ]);
    }

    /**
     * Fetch pending commands from central platform
     */
    public function fetch_commands(): array {
        $response = $this->request('GET', '/commands/pending');
        if (isset($response['error'])) {
            return [];
        }
        return $response['data']['commands'] ?? [];
    }

    /**
     * Acknowledge command execution
     */
    public function acknowledge_command(string $command_id, string $status, array $result = []): array {
        return $this->request('POST', "/commands/{$command_id}/ack", [
            'status' => $status,
            'result' => $result,
            'executed_at' => gmdate('c'),
        ]);
    }

    /**
     * Register heartbeat with platform
     */
    public function heartbeat(): array {
        return $this->request('POST', '/heartbeat', [
            'site_url' => get_site_url(),
            'plugin_version' => RJV_AGI_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'timestamp' => gmdate('c'),
        ]);
    }

    /**
     * Get architecture definition from platform
     */
    public function get_architecture(): array {
        $cache_key = 'rjv_agi_architecture_' . md5($this->tenant_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $response = $this->request('GET', '/architecture');
        if (isset($response['error'])) {
            return [];
        }

        $result = $response['data'] ?? [];
        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        return $result;
    }

    /**
     * Get workflow definitions from platform
     */
    public function get_workflows(): array {
        $response = $this->request('GET', '/workflows');
        return $response['data'] ?? [];
    }

    /**
     * Get agent configurations from platform
     */
    public function get_agent_configs(): array {
        $response = $this->request('GET', '/agents/configs');
        return $response['data'] ?? [];
    }

    /**
     * Default capabilities when platform is not connected
     */
    private function get_default_capabilities(): array {
        return [
            'enabled' => [
                'content_management',
                'media_management',
                'basic_ai',
            ],
            'limits' => [
                'ai_requests_daily' => 100,
                'content_revisions' => 10,
                'agents_concurrent' => 1,
                'integrations' => 2,
            ],
            'features' => [
                'design_system' => false,
                'multi_tenant' => false,
                'advanced_agents' => false,
                'real_time_events' => false,
            ],
        ];
    }

    /**
     * Make authenticated request to platform
     */
    private function request(string $method, string $endpoint, array $body = []): array {
        if (!$this->is_configured()) {
            return ['error' => 'Platform not configured'];
        }

        $url = rtrim($this->platform_url, '/') . '/' . ltrim($endpoint, '/');
        $timestamp = (string) time();
        $signature = $this->generate_signature($method, $endpoint, $timestamp, $body);

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Tenant-ID' => $this->tenant_id,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'X-Plugin-Version' => RJV_AGI_VERSION,
            ],
        ];

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return ['error' => $data['message'] ?? "HTTP {$code}"];
        }

        return $data ?? [];
    }

    /**
     * Generate HMAC signature for request authentication
     */
    private function generate_signature(string $method, string $endpoint, string $timestamp, array $body): string {
        $payload = implode("\n", [
            $method,
            $endpoint,
            $timestamp,
            !empty($body) ? wp_json_encode($body) : '',
        ]);

        return hash_hmac('sha256', $payload, $this->tenant_secret);
    }
}
