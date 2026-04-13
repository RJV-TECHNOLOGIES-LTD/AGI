<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Integration;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Bridge\CapabilityGate;

/**
 * External Integration Manager
 *
 * Manages connections with external systems: APIs, CRMs, payment gateways,
 * communication systems. All integrations are controlled, permissioned, and observable.
 */
final class IntegrationManager {
    private static ?self $instance = null;
    private array $integrations = [];
    private string $table_name;
    private CapabilityGate $gate;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rjv_agi_integrations';
        $this->gate = CapabilityGate::instance();
        $this->load_integrations();
    }

    /**
     * Create integrations table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rjv_agi_integrations';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            integration_id VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(200) NOT NULL,
            type VARCHAR(50) NOT NULL,
            config LONGTEXT NOT NULL,
            credentials LONGTEXT NULL,
            status ENUM('active', 'inactive', 'error', 'pending') NOT NULL DEFAULT 'inactive',
            last_sync DATETIME NULL,
            last_error VARCHAR(500) NULL,
            sync_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_type (type)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Load integrations from database
     */
    private function load_integrations(): void {
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A) ?: [];

        foreach ($results as $row) {
            $this->integrations[$row['integration_id']] = $this->hydrate($row);
        }
    }

    /**
     * Register a new integration
     */
    public function register(array $config): array {
        if (!$this->gate->can('integration_connect', $config)) {
            return ['success' => false, 'error' => 'Integration registration not permitted'];
        }

        $validation = $this->validate_config($config);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $integration_id = 'int_' . wp_generate_uuid4();

        // Encrypt credentials if present
        $credentials = null;
        if (!empty($config['credentials'])) {
            $credentials = $this->encrypt_credentials($config['credentials']);
        }

        global $wpdb;
        $wpdb->insert($this->table_name, [
            'integration_id' => $integration_id,
            'name' => sanitize_text_field($config['name']),
            'type' => sanitize_key($config['type']),
            'config' => wp_json_encode($config['config'] ?? []),
            'credentials' => $credentials,
            'status' => 'inactive',
        ]);

        $integration = $this->get($integration_id);
        $this->integrations[$integration_id] = $integration;

        AuditLog::log('integration_registered', 'integration', 0, [
            'integration_id' => $integration_id,
            'name' => $config['name'],
            'type' => $config['type'],
        ], 2);

        return [
            'success' => true,
            'integration_id' => $integration_id,
            'integration' => $integration,
        ];
    }

    /**
     * Activate an integration
     */
    public function activate(string $integration_id): array {
        $integration = $this->get($integration_id);
        if (!$integration) {
            return ['success' => false, 'error' => 'Integration not found'];
        }

        // Test connection
        $test = $this->test_connection($integration);
        if (!$test['success']) {
            global $wpdb;
            $wpdb->update($this->table_name, [
                'status' => 'error',
                'last_error' => $test['error'],
                'error_count' => $integration['error_count'] + 1,
            ], ['integration_id' => $integration_id]);

            return $test;
        }

        global $wpdb;
        $wpdb->update($this->table_name, [
            'status' => 'active',
            'last_error' => null,
            'updated_at' => current_time('mysql', true),
        ], ['integration_id' => $integration_id]);

        $this->integrations[$integration_id] = $this->get($integration_id);

        AuditLog::log('integration_activated', 'integration', 0, [
            'integration_id' => $integration_id,
        ], 2);

        return ['success' => true];
    }

    /**
     * Deactivate an integration
     */
    public function deactivate(string $integration_id): array {
        global $wpdb;
        $wpdb->update($this->table_name, [
            'status' => 'inactive',
            'updated_at' => current_time('mysql', true),
        ], ['integration_id' => $integration_id]);

        if (isset($this->integrations[$integration_id])) {
            $this->integrations[$integration_id]['status'] = 'inactive';
        }

        AuditLog::log('integration_deactivated', 'integration', 0, [
            'integration_id' => $integration_id,
        ], 2);

        return ['success' => true];
    }

    /**
     * Execute integration action
     */
    public function execute(string $integration_id, string $action, array $data = []): array {
        $integration = $this->get($integration_id);
        if (!$integration) {
            return ['success' => false, 'error' => 'Integration not found'];
        }

        if ($integration['status'] !== 'active') {
            return ['success' => false, 'error' => 'Integration not active'];
        }

        $start = microtime(true);

        try {
            $result = $this->execute_action($integration, $action, $data);
            $duration = (int) ((microtime(true) - $start) * 1000);

            // Update sync count
            global $wpdb;
            $wpdb->update($this->table_name, [
                'last_sync' => current_time('mysql', true),
                'sync_count' => $integration['sync_count'] + 1,
            ], ['integration_id' => $integration_id]);

            AuditLog::log('integration_action', 'integration', 0, [
                'integration_id' => $integration_id,
                'action' => $action,
                'duration_ms' => $duration,
            ], 1);

            return [
                'success' => true,
                'result' => $result,
                'duration_ms' => $duration,
            ];

        } catch (\Throwable $e) {
            global $wpdb;
            $wpdb->update($this->table_name, [
                'last_error' => $e->getMessage(),
                'error_count' => $integration['error_count'] + 1,
            ], ['integration_id' => $integration_id]);

            AuditLog::log('integration_error', 'integration', 0, [
                'integration_id' => $integration_id,
                'action' => $action,
                'error' => $e->getMessage(),
            ], 1, 'error');

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get integration by ID
     */
    public function get(string $integration_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE integration_id = %s",
            $integration_id
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * List all integrations
     */
    public function list_all(array $filters = []): array {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $where[] = 'type = %s';
            $params[] = $filters['type'];
        }

        $sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $results = $wpdb->get_results($sql, ARRAY_A) ?: [];

        return array_map([$this, 'hydrate'], $results);
    }

    /**
     * Hydrate integration row
     */
    private function hydrate(array $row): array {
        return [
            'id' => (int) $row['id'],
            'integration_id' => $row['integration_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'config' => json_decode($row['config'], true) ?: [],
            'status' => $row['status'],
            'last_sync' => $row['last_sync'],
            'last_error' => $row['last_error'],
            'sync_count' => (int) $row['sync_count'],
            'error_count' => (int) $row['error_count'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Validate integration config
     */
    private function validate_config(array $config): array {
        $errors = [];

        if (empty($config['name'])) {
            $errors[] = 'Name is required';
        }

        $valid_types = ['api', 'webhook', 'crm', 'payment', 'email', 'analytics', 'custom'];
        if (empty($config['type']) || !in_array($config['type'], $valid_types, true)) {
            $errors[] = 'Invalid integration type';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Encrypt credentials for storage
     */
    private function encrypt_credentials(array $credentials): string {
        $key = wp_salt('auth');
        $data = wp_json_encode($credentials);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt credentials
     */
    private function decrypt_credentials(string $encrypted): array {
        $key = wp_salt('auth');
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $encrypted_data = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted_data, 'AES-256-CBC', $key, 0, $iv);
        return json_decode($decrypted, true) ?: [];
    }

    /**
     * Test integration connection
     */
    private function test_connection(array $integration): array {
        $type = $integration['type'];
        $config = $integration['config'];

        switch ($type) {
            case 'api':
                return $this->test_api_connection($config);
            case 'webhook':
                return ['success' => true]; // Webhooks are outbound
            default:
                return ['success' => true];
        }
    }

    /**
     * Test API connection
     */
    private function test_api_connection(array $config): array {
        $url = $config['base_url'] ?? '';
        if (empty($url)) {
            return ['success' => false, 'error' => 'Base URL not configured'];
        }

        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            return ['success' => false, 'error' => "HTTP {$code}"];
        }

        return ['success' => true];
    }

    /**
     * Execute integration action
     */
    private function execute_action(array $integration, string $action, array $data): array {
        $type = $integration['type'];

        return match ($type) {
            'api' => $this->execute_api_action($integration, $action, $data),
            'webhook' => $this->execute_webhook($integration, $action, $data),
            default => ['error' => 'Unsupported integration type'],
        };
    }

    /**
     * Execute API action
     */
    private function execute_api_action(array $integration, string $action, array $data): array {
        $config = $integration['config'];
        $base_url = rtrim($config['base_url'] ?? '', '/');
        $endpoint = $data['endpoint'] ?? '';
        $method = strtoupper($data['method'] ?? 'GET');

        $url = $base_url . '/' . ltrim($endpoint, '/');

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        // Add auth headers
        if (!empty($config['auth_header'])) {
            $args['headers'][$config['auth_header']] = $config['auth_value'] ?? '';
        }

        if ($method !== 'GET' && !empty($data['body'])) {
            $args['body'] = wp_json_encode($data['body']);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        return [
            'status' => wp_remote_retrieve_response_code($response),
            'body' => json_decode(wp_remote_retrieve_body($response), true),
        ];
    }

    /**
     * Execute webhook
     */
    private function execute_webhook(array $integration, string $action, array $data): array {
        $config = $integration['config'];
        $url = $config['webhook_url'] ?? '';

        if (empty($url)) {
            throw new \RuntimeException('Webhook URL not configured');
        }

        $payload = [
            'action' => $action,
            'timestamp' => gmdate('c'),
            'data' => $data,
        ];

        // Add signature
        $secret = $config['secret'] ?? '';
        if ($secret) {
            $payload['signature'] = hash_hmac('sha256', wp_json_encode($payload), $secret);
        }

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        return [
            'status' => wp_remote_retrieve_response_code($response),
            'delivered' => true,
        ];
    }

    /**
     * Get available integration types
     */
    public function get_types(): array {
        return [
            'api' => 'External API',
            'webhook' => 'Webhook',
            'crm' => 'CRM System',
            'payment' => 'Payment Gateway',
            'email' => 'Email Service',
            'analytics' => 'Analytics Platform',
            'custom' => 'Custom Integration',
        ];
    }
}
