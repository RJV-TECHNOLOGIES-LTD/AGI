<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

/**
 * Event Store
 *
 * Append-only, tamper-evident storage for every discrete event captured by the
 * data-collection layer.  The plugin only writes and exposes; the AGI reads and acts.
 *
 * Integrity model
 * ───────────────
 * Each event row carries a record_hash = HMAC-SHA256(prev_hash ‖ canonical_fields).
 * The chain is anchored per-session so that session-level replay attacks are
 * detectable by the AGI.  A global "genesis" sentinel is used when no prior
 * event exists for the session.
 */
final class EventStore {

    private static ?self $instance = null;
    private string $table;

    // Maximum events accepted in a single batch call
    public const BATCH_LIMIT = 500;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rjv_agi_dc_events';
    }

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'rjv_agi_dc_events';
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id       VARCHAR(36)  NOT NULL,
            event_type     VARCHAR(100) NOT NULL,
            event_category VARCHAR(60)  NOT NULL DEFAULT '',
            industry       VARCHAR(60)  NOT NULL DEFAULT 'general',
            subject_id     VARCHAR(120) NOT NULL DEFAULT '',
            subject_type   ENUM('user','visitor','agent','system') NOT NULL DEFAULT 'visitor',
            session_id     VARCHAR(36)  NOT NULL DEFAULT '',
            page_url       VARCHAR(2048) NOT NULL DEFAULT '',
            referrer       VARCHAR(2048) NOT NULL DEFAULT '',
            properties     LONGTEXT NULL,
            context        LONGTEXT NULL,
            occurred_at    DATETIME(3)  NOT NULL,
            received_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sequence_no    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tenant_id      VARCHAR(100) NOT NULL DEFAULT '',
            prev_hash      CHAR(64)     NOT NULL DEFAULT '',
            record_hash    CHAR(64)     NOT NULL DEFAULT '',
            UNIQUE INDEX idx_event_id (event_id),
            INDEX idx_type    (event_type),
            INDEX idx_subject (subject_id),
            INDEX idx_session (session_id),
            INDEX idx_occurred (occurred_at),
            INDEX idx_industry (industry),
            INDEX idx_category (event_category),
            INDEX idx_tenant   (tenant_id)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Append a single event.  Returns the inserted row ID on success, 0 on failure.
     *
     * @param array{
     *   event_id?:       string,
     *   event_type:      string,
     *   event_category?: string,
     *   industry?:       string,
     *   subject_id?:     string,
     *   subject_type?:   string,
     *   session_id?:     string,
     *   page_url?:       string,
     *   referrer?:       string,
     *   properties?:     array<string,mixed>,
     *   context?:        array<string,mixed>,
     *   occurred_at?:    string,
     *   tenant_id?:      string,
     * } $data
     */
    public function append(array $data): int {
        global $wpdb;

        $event_id  = sanitize_text_field((string) ($data['event_id'] ?? wp_generate_uuid4()));
        $session_id = sanitize_text_field((string) ($data['session_id'] ?? ''));

        $prev_hash  = $this->last_hash_for_session($session_id);
        $seq        = $this->next_sequence($session_id);

        $occurred_at = $this->normalise_datetime((string) ($data['occurred_at'] ?? ''));

        $properties_json = isset($data['properties']) && is_array($data['properties'])
            ? (wp_json_encode($data['properties']) ?: null)
            : null;

        $context_json = isset($data['context']) && is_array($data['context'])
            ? (wp_json_encode($data['context']) ?: null)
            : null;

        $row = [
            'event_id'       => $event_id,
            'event_type'     => sanitize_text_field((string) ($data['event_type'] ?? 'unknown')),
            'event_category' => sanitize_text_field((string) ($data['event_category'] ?? '')),
            'industry'       => sanitize_key((string) ($data['industry'] ?? 'general')),
            'subject_id'     => sanitize_text_field((string) ($data['subject_id'] ?? '')),
            'subject_type'   => $this->valid_subject_type((string) ($data['subject_type'] ?? 'visitor')),
            'session_id'     => $session_id,
            'page_url'       => esc_url_raw((string) ($data['page_url'] ?? '')),
            'referrer'       => esc_url_raw((string) ($data['referrer'] ?? '')),
            'properties'     => $properties_json,
            'context'        => $context_json,
            'occurred_at'    => $occurred_at,
            'sequence_no'    => $seq,
            'tenant_id'      => sanitize_text_field((string) ($data['tenant_id'] ?? '')),
            'prev_hash'      => $prev_hash,
            'record_hash'    => '',
        ];

        $row['record_hash'] = $this->compute_hash($row, $prev_hash);

        $fmt = ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s'];
        $wpdb->insert($this->table, $row, $fmt);

        return (int) $wpdb->insert_id;
    }

    /**
     * Append a batch of events atomically (single INSERT … VALUES).
     * Returns number of rows inserted or -1 on failure.
     *
     * @param list<array<string,mixed>> $events
     */
    public function batch_append(array $events): int {
        if (empty($events)) {
            return 0;
        }
        if (count($events) > self::BATCH_LIMIT) {
            $events = array_slice($events, 0, self::BATCH_LIMIT);
        }

        global $wpdb;

        $placeholders = [];
        $values       = [];

        foreach ($events as $data) {
            $event_id   = sanitize_text_field((string) ($data['event_id'] ?? wp_generate_uuid4()));
            $session_id = sanitize_text_field((string) ($data['session_id'] ?? ''));
            $prev_hash  = $this->last_hash_for_session($session_id);
            $seq        = $this->next_sequence($session_id);
            $occurred_at = $this->normalise_datetime((string) ($data['occurred_at'] ?? ''));

            $properties_json = isset($data['properties']) && is_array($data['properties'])
                ? (wp_json_encode($data['properties']) ?: null)
                : null;

            $context_json = isset($data['context']) && is_array($data['context'])
                ? (wp_json_encode($data['context']) ?: null)
                : null;

            $row = [
                'event_id'       => $event_id,
                'event_type'     => sanitize_text_field((string) ($data['event_type'] ?? 'unknown')),
                'event_category' => sanitize_text_field((string) ($data['event_category'] ?? '')),
                'industry'       => sanitize_key((string) ($data['industry'] ?? 'general')),
                'subject_id'     => sanitize_text_field((string) ($data['subject_id'] ?? '')),
                'subject_type'   => $this->valid_subject_type((string) ($data['subject_type'] ?? 'visitor')),
                'session_id'     => $session_id,
                'page_url'       => esc_url_raw((string) ($data['page_url'] ?? '')),
                'referrer'       => esc_url_raw((string) ($data['referrer'] ?? '')),
                'properties'     => $properties_json,
                'context'        => $context_json,
                'occurred_at'    => $occurred_at,
                'sequence_no'    => $seq,
                'tenant_id'      => sanitize_text_field((string) ($data['tenant_id'] ?? '')),
                'prev_hash'      => $prev_hash,
            ];
            $row['record_hash'] = $this->compute_hash($row, $prev_hash);

            $placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)';
            array_push(
                $values,
                $row['event_id'], $row['event_type'], $row['event_category'],
                $row['industry'], $row['subject_id'], $row['subject_type'],
                $row['session_id'], $row['page_url'], $row['referrer'],
                $row['properties'], $row['context'], $row['occurred_at'],
                $row['sequence_no'], $row['tenant_id'],
                $row['prev_hash'], $row['record_hash']
            );
        }

        $cols = 'event_id,event_type,event_category,industry,subject_id,subject_type,'
              . 'session_id,page_url,referrer,properties,context,occurred_at,'
              . 'sequence_no,tenant_id,prev_hash,record_hash';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            'INSERT INTO `' . $this->table . "` ({$cols}) VALUES " . implode(',', $placeholders),
            $values
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query($sql);
        return $result !== false ? (int) $result : -1;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Query events with filters.
     *
     * Supported filters:
     *   subject_id, session_id, event_type, event_category, industry,
     *   tenant_id, since (datetime), until (datetime), page, per_page
     *
     * Returns [ 'items' => array, 'total' => int, 'page' => int, 'per_page' => int, 'pages' => int ]
     */
    public function query(array $filters = []): array {
        global $wpdb;

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['subject_id'])) {
            $where[]  = 'subject_id = %s';
            $params[] = sanitize_text_field($filters['subject_id']);
        }
        if (!empty($filters['session_id'])) {
            $where[]  = 'session_id = %s';
            $params[] = sanitize_text_field($filters['session_id']);
        }
        if (!empty($filters['event_type'])) {
            $where[]  = 'event_type = %s';
            $params[] = sanitize_text_field($filters['event_type']);
        }
        if (!empty($filters['event_category'])) {
            $where[]  = 'event_category = %s';
            $params[] = sanitize_text_field($filters['event_category']);
        }
        if (!empty($filters['industry'])) {
            $where[]  = 'industry = %s';
            $params[] = sanitize_key($filters['industry']);
        }
        if (!empty($filters['tenant_id'])) {
            $where[]  = 'tenant_id = %s';
            $params[] = sanitize_text_field($filters['tenant_id']);
        }
        if (!empty($filters['since'])) {
            $where[]  = 'occurred_at >= %s';
            $params[] = $this->normalise_datetime((string) $filters['since']);
        }
        if (!empty($filters['until'])) {
            $where[]  = 'occurred_at <= %s';
            $params[] = $this->normalise_datetime((string) $filters['until']);
        }

        $where_sql = implode(' AND ', $where);

        $per_page = max(1, min((int) ($filters['per_page'] ?? 100), 1000));
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        // Total count
        if (empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}");
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}",
                $params
            ));
        }

        // Rows
        $limit_params = array_merge($params, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY occurred_at ASC, id ASC LIMIT %d OFFSET %d",
            $limit_params
        ), ARRAY_A);

        return [
            'items'    => array_map([$this, 'decode_row'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
        ];
    }

    /**
     * Delete all events for a subject (GDPR erasure).
     */
    public function delete_by_subject(string $subject_id): int {
        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['subject_id' => $subject_id], ['%s']);
        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Get all raw events for a subject (GDPR portability export).
     *
     * @return list<array<string,mixed>>
     */
    public function export_by_subject(string $subject_id): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE subject_id = %s ORDER BY occurred_at ASC",
                $subject_id
            ),
            ARRAY_A
        );
        return array_map([$this, 'decode_row'], $rows);
    }

    // -------------------------------------------------------------------------
    // HMAC helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the HMAC signing key for the data-collection chain.
     * Key = HMAC-SHA256( AUTH_KEY, "rjv-dc-chain-v1:{SITEURL}" )
     */
    private static function chain_key(): string {
        $root = defined('AUTH_KEY') ? AUTH_KEY : wp_generate_password(64, true, true);
        return hash_hmac('sha256', 'rjv-dc-chain-v1:' . (string) get_option('siteurl', ''), $root);
    }

    private function compute_hash(array $row, string $prev_hash): string {
        $payload = implode('|', [
            $row['event_id'],
            $row['event_type'],
            $row['subject_id'],
            $row['session_id'],
            $row['occurred_at'],
            $row['sequence_no'],
            $prev_hash,
        ]);
        return hash_hmac('sha256', $payload, self::chain_key());
    }

    private function last_hash_for_session(string $session_id): string {
        if ($session_id === '') {
            return 'genesis';
        }
        global $wpdb;
        $hash = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT record_hash FROM {$this->table} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
                $session_id
            )
        );
        return $hash ?: 'genesis';
    }

    private function next_sequence(string $session_id): int {
        if ($session_id === '') {
            return 0;
        }
        global $wpdb;
        $max = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(sequence_no) FROM {$this->table} WHERE session_id = %s",
                $session_id
            )
        );
        return $max !== null ? ((int) $max + 1) : 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalise_datetime(string $dt): string {
        if ($dt === '') {
            return gmdate('Y-m-d H:i:s.') . str_pad((string) (int) (microtime(true) * 1000 % 1000), 3, '0', STR_PAD_LEFT);
        }
        // Accept ISO 8601 with or without ms
        $ts = strtotime($dt);
        if ($ts === false) {
            return gmdate('Y-m-d H:i:s.000');
        }
        return gmdate('Y-m-d H:i:s', $ts) . '.000';
    }

    private function valid_subject_type(string $t): string {
        return in_array($t, ['user', 'visitor', 'agent', 'system'], true) ? $t : 'visitor';
    }

    /**
     * Decode a DB row: JSON-decode properties and context fields.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode_row(array $row): array {
        foreach (['properties', 'context'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            } else {
                $row[$field] = [];
            }
        }
        return $row;
    }
}
