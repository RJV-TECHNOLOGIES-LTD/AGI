<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Integration;

use RJV_AGI_Bridge\AuditLog;

/**
 * Webhook Manager
 *
 * Manages incoming and outgoing webhooks for automation and external triggers.
 */
final class WebhookManager {
    private static ?self $instance = null;
    private string $table_name;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rjv_agi_webhooks';
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
    }

    /**
     * Create webhooks table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rjv_agi_webhooks';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            webhook_id VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(200) NOT NULL,
            type ENUM('incoming', 'outgoing') NOT NULL,
            event VARCHAR(100) NULL,
            url VARCHAR(500) NULL,
            secret VARCHAR(100) NOT NULL,
            headers LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            last_triggered DATETIME NULL,
            trigger_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_response LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type (type),
            INDEX idx_event (event),
            INDEX idx_active (active)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Register REST endpoint for incoming webhooks
     */
    public function register_webhook_endpoint(): void {
        register_rest_route('rjv-agi/v1', '/webhooks/incoming/(?P<webhook_id>[a-zA-Z0-9_-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_incoming'],
            'permission_callback' => '__return_true',
    ]);
    }

    /**
     * Create a new webhook
     */
    public function create(array $config): array {
        $webhook_id = 'webhook_' . wp_generate_uuid4();
        $secret = wp_generate_password(32, false);

        global $wpdb;
        $wpdb->insert($this->table_name, [
            'webhook_id' => $webhook_id,
            'name' => sanitize_text_field($config['name'] ?? 'Unnamed Webhook'),
            'type' => $config['type'] === 'outgoing' ? 'outgoing' : 'incoming',
            'event' => sanitize_text_field($config['event'] ?? ''),
            'url' => $config['type'] === 'outgoing' ? esc_url_raw($config['url'] ?? '') : null,
            'secret' => $secret,
            'headers' => !empty($config['headers']) ? wp_json_encode($config['headers']) : null,
            'active' => 1,
        ]);

        $webhook = $this->get($webhook_id);

        AuditLog::log('webhook_created', 'webhook', 0, [
            'webhook_id' => $webhook_id,
            'type' => $config['type'],
            'event' => $config['event'] ?? '',
        ], 2);

        return [
            'success' => true,
            'webhook_id' => $webhook_id,
            'secret' => $secret,
            'webhook' => $webhook,
            'endpoint' => $config['type'] === 'incoming'
                ? rest_url("rjv-agi/v1/webhooks/incoming/{$webhook_id}")
                : null,
        ];
    }

    /**
     * Handle incoming webhook
     */
    public function handle_incoming(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $webhook_id = $request->get_param('webhook_id');
        $webhook = $this->get($webhook_id);

        if (!$webhook) {
            return new \WP_Error('not_found', 'Webhook not found', ['status' => 404]);
        }

        if (!$webhook['active']) {
            return new \WP_Error('inactive', 'Webhook is inactive', ['status' => 403]);
        }

        // Verify signature
        $signature = $request->get_header('X-Webhook-Signature');
        $body = $request->get_body();

        if (!$this->verify_signature($body, $signature, $webhook['secret'])) {
            AuditLog::log('webhook_signature_failed', 'webhook', 0, [
                'webhook_id' => $webhook_id,
            ], 1, 'error');

            return new \WP_Error('invalid_signature', 'Invalid signature', ['status' => 401]);
        }

        $payload = json_decode($body, true);

        // Process the webhook
        $result = $this->process_incoming($webhook, $payload);

        // Update stats
        global $wpdb;
        $wpdb->update($this->table_name, [
            'last_triggered' => current_time('mysql', true),
            'trigger_count' => $webhook['trigger_count'] + 1,
            'last_response' => wp_json_encode($result),
        ], ['webhook_id' => $webhook_id]);

        AuditLog::log('webhook_received', 'webhook', 0, [
            'webhook_id' => $webhook_id,
            'success' => $result['success'] ?? false,
        ], 1);

        return new \WP_REST_Response($result, 200);
    }

    /**
     * Trigger an outgoing webhook
     */
    public function trigger(string $webhook_id, array $payload): array {
        $webhook = $this->get($webhook_id);

        if (!$webhook) {
            return ['success' => false, 'error' => 'Webhook not found'];
        }

        if ($webhook['type'] !== 'outgoing') {
            return ['success' => false, 'error' => 'Not an outgoing webhook'];
        }

        if (!$webhook['active']) {
            return ['success' => false, 'error' => 'Webhook is inactive'];
        }

        // Sign payload
        $body = wp_json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhook['secret']);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-ID' => $webhook_id,
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => (string) time(),
        ];

        // Add custom headers
        if (!empty($webhook['headers'])) {
            $custom_headers = json_decode($webhook['headers'], true) ?: [];
            $headers = array_merge($headers, $custom_headers);
        }

        $response = wp_remote_post($webhook['url'], [
            'timeout' => 30,
            'headers' => $headers,
            'body' => $body,
        ]);

        $result = [
            'success' => !is_wp_error($response),
            'status' => is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response),
            'error' => is_wp_error($response) ? $response->get_error_message() : null,
        ];

        // Update stats
        global $wpdb;
        $wpdb->update($this->table_name, [
            'last_triggered' => current_time('mysql', true),
            'trigger_count' => $webhook['trigger_count'] + 1,
            'last_response' => wp_json_encode($result),
        ], ['webhook_id' => $webhook_id]);

        AuditLog::log('webhook_triggered', 'webhook', 0, [
            'webhook_id' => $webhook_id,
            'success' => $result['success'],
            'status' => $result['status'],
        ], 1);

        return $result;
    }

    /**
     * Trigger webhooks by event
     */
    public function trigger_event(string $event, array $payload): array {
        global $wpdb;

        $webhooks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE type = 'outgoing' AND event = %s AND active = 1",
            $event
        ), ARRAY_A) ?: [];

        $results = [];
        foreach ($webhooks as $row) {
            $webhook = $this->hydrate($row);
            $results[$webhook['webhook_id']] = $this->trigger($webhook['webhook_id'], $payload);
        }

        return $results;
    }

    /**
     * Get webhook by ID
     */
    public function get(string $webhook_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE webhook_id = %s",
            $webhook_id
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * List webhooks
     */
    public function list_all(array $filters = []): array {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'type = %s';
            $params[] = $filters['type'];
        }

        if (isset($filters['active'])) {
            $where[] = 'active = %d';
            $params[] = $filters['active'] ? 1 : 0;
        }

        $sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $results = $wpdb->get_results($sql, ARRAY_A) ?: [];

        return array_map([$this, 'hydrate'], $results);
    }

    /**
     * Update webhook
     */
    public function update(string $webhook_id, array $data): array {
        $webhook = $this->get($webhook_id);
        if (!$webhook) {
            return ['success' => false, 'error' => 'Webhook not found'];
        }

        $update = [];
        if (isset($data['name'])) {
            $update['name'] = sanitize_text_field($data['name']);
        }
        if (isset($data['url']) && $webhook['type'] === 'outgoing') {
            $update['url'] = esc_url_raw($data['url']);
        }
        if (isset($data['event'])) {
            $update['event'] = sanitize_text_field($data['event']);
        }
        if (isset($data['active'])) {
            $update['active'] = $data['active'] ? 1 : 0;
        }
        if (isset($data['headers'])) {
            $update['headers'] = wp_json_encode($data['headers']);
        }

        if (!empty($update)) {
            global $wpdb;
            $wpdb->update($this->table_name, $update, ['webhook_id' => $webhook_id]);
        }

        return ['success' => true, 'webhook' => $this->get($webhook_id)];
    }

    /**
     * Delete webhook
     */
    public function delete(string $webhook_id): array {
        global $wpdb;
        $deleted = $wpdb->delete($this->table_name, ['webhook_id' => $webhook_id]);

        AuditLog::log('webhook_deleted', 'webhook', 0, [
            'webhook_id' => $webhook_id,
        ], 2);

        return ['success' => $deleted > 0];
    }

    /**
     * Regenerate webhook secret
     */
    public function regenerate_secret(string $webhook_id): array {
        $new_secret = wp_generate_password(32, false);

        global $wpdb;
        $wpdb->update($this->table_name, [
            'secret' => $new_secret,
        ], ['webhook_id' => $webhook_id]);

        return ['success' => true, 'secret' => $new_secret];
    }

    /**
     * Hydrate webhook row
     */
    private function hydrate(array $row): array {
        return [
            'id' => (int) $row['id'],
            'webhook_id' => $row['webhook_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'event' => $row['event'],
            'url' => $row['url'],
            'secret' => $row['secret'],
            'headers' => $row['headers'] ? json_decode($row['headers'], true) : [],
            'active' => (bool) $row['active'],
            'last_triggered' => $row['last_triggered'],
            'trigger_count' => (int) $row['trigger_count'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Verify webhook signature
     */
    private function verify_signature(string $payload, ?string $signature, string $secret): bool {
        if (empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Process incoming webhook payload
     */
    private function process_incoming(array $webhook, array $payload): array {
        // Fire action for custom processing
        do_action('rjv_agi_webhook_received', $webhook, $payload);

        // Fire event-specific action
        if (!empty($webhook['event'])) {
            do_action("rjv_agi_webhook_{$webhook['event']}", $payload);
        }

        return [
            'success' => true,
            'message' => 'Webhook processed',
            'webhook_id' => $webhook['webhook_id'],
        ];
    }
}
