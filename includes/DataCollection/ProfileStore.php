<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

/**
 * Profile Store
 *
 * Maintains a 360° subject profile for every known user or anonymous visitor.
 * The plugin writes raw observed attributes; the AGI reads the profile and
 * writes back computed traits via the REST API.
 *
 * A tamper-evident record_hash seals each profile version so the AGI can
 * detect out-of-band modifications.
 */
final class ProfileStore {

    private static ?self $instance = null;
    private string $table;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rjv_agi_dc_profiles';
    }

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'rjv_agi_dc_profiles';
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject_id          VARCHAR(120)  NOT NULL,
            subject_type        ENUM('user','visitor') NOT NULL DEFAULT 'visitor',
            wp_user_id          BIGINT UNSIGNED NULL,
            email               VARCHAR(255)  NOT NULL DEFAULT '',
            display_name        VARCHAR(200)  NOT NULL DEFAULT '',
            first_seen_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            session_count       INT UNSIGNED  NOT NULL DEFAULT 0,
            event_count         INT UNSIGNED  NOT NULL DEFAULT 0,
            page_view_count     INT UNSIGNED  NOT NULL DEFAULT 0,
            total_time_seconds  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            industry            VARCHAR(60)   NOT NULL DEFAULT 'general',
            lifecycle_stage     VARCHAR(40)   NOT NULL DEFAULT 'unknown',
            acquisition_source  VARCHAR(200)  NOT NULL DEFAULT '',
            acquisition_medium  VARCHAR(200)  NOT NULL DEFAULT '',
            acquisition_campaign VARCHAR(200) NOT NULL DEFAULT '',
            traits              LONGTEXT      NULL,
            device_fingerprint  VARCHAR(64)   NOT NULL DEFAULT '',
            consent_status      VARCHAR(20)   NOT NULL DEFAULT 'unknown',
            tenant_id           VARCHAR(100)  NOT NULL DEFAULT '',
            version             INT UNSIGNED  NOT NULL DEFAULT 1,
            record_hash         CHAR(64)      NOT NULL DEFAULT '',
            updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_subject_id (subject_id),
            INDEX idx_wp_user    (wp_user_id),
            INDEX idx_stage      (lifecycle_stage),
            INDEX idx_industry   (industry),
            INDEX idx_tenant     (tenant_id),
            INDEX idx_updated    (updated_at)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert or update a subject profile.
     * On conflict the counters are incremented and mutable fields overwritten.
     *
     * Returns the internal row ID on success, 0 on failure.
     *
     * @param array{
     *   subject_id:          string,
     *   subject_type?:       string,
     *   wp_user_id?:         int|null,
     *   email?:              string,
     *   display_name?:       string,
     *   session_count?:      int,
     *   event_count?:        int,
     *   page_view_count?:    int,
     *   total_time_seconds?: int,
     *   industry?:           string,
     *   lifecycle_stage?:    string,
     *   acquisition_source?: string,
     *   acquisition_medium?: string,
     *   acquisition_campaign?: string,
     *   traits?:             array<string,mixed>,
     *   device_fingerprint?: string,
     *   consent_status?:     string,
     *   tenant_id?:          string,
     * } $data
     */
    public function upsert(array $data): int {
        global $wpdb;

        $subject_id = sanitize_text_field((string) ($data['subject_id'] ?? ''));
        if ($subject_id === '') {
            return 0;
        }

        $existing = $this->get($subject_id);

        if ($existing === null) {
            return $this->insert($data);
        }

        return $this->update_existing($existing, $data);
    }

    /**
     * Write AGI-computed traits back onto a profile.
     * This is the only field the AGI is expected to write.
     *
     * @param array<string,mixed> $traits
     */
    public function update_traits(string $subject_id, array $traits): bool {
        global $wpdb;

        $existing = $this->get($subject_id);
        if ($existing === null) {
            return false;
        }

        $merged = array_merge(
            is_array($existing['traits'] ?? null) ? $existing['traits'] : [],
            $traits
        );
        $traits_json  = wp_json_encode($merged) ?: '{}';
        $new_version  = ((int) $existing['version']) + 1;
        $new_hash     = $this->compute_hash($subject_id, $traits_json, $new_version);

        $result = $wpdb->update(
            $this->table,
            [
                'traits'      => $traits_json,
                'version'     => $new_version,
                'record_hash' => $new_hash,
            ],
            ['subject_id' => $subject_id],
            ['%s', '%d', '%s'],
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Atomically increment engagement counters.
     *
     * @param array{session_count?: int, event_count?: int, page_view_count?: int, total_time_seconds?: int} $counters
     */
    public function increment_counters(string $subject_id, array $counters): bool {
        global $wpdb;

        $parts  = [];
        $params = [];

        if (!empty($counters['session_count'])) {
            $parts[]  = 'session_count = session_count + %d';
            $params[] = max(0, (int) $counters['session_count']);
        }
        if (!empty($counters['event_count'])) {
            $parts[]  = 'event_count = event_count + %d';
            $params[] = max(0, (int) $counters['event_count']);
        }
        if (!empty($counters['page_view_count'])) {
            $parts[]  = 'page_view_count = page_view_count + %d';
            $params[] = max(0, (int) $counters['page_view_count']);
        }
        if (!empty($counters['total_time_seconds'])) {
            $parts[]  = 'total_time_seconds = total_time_seconds + %d';
            $params[] = max(0, (int) $counters['total_time_seconds']);
        }

        if (empty($parts)) {
            return true;
        }

        $parts[]  = 'last_seen_at = %s';
        $params[] = current_time('mysql', true);
        $params[] = $subject_id;

        $set_sql = implode(', ', $parts);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET {$set_sql} WHERE subject_id = %s",
                $params
            )
        );

        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get a single profile by subject_id.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $subject_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE subject_id = %s LIMIT 1", $subject_id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return $this->decode_row($row);
    }

    /**
     * Get profile by WordPress user ID.
     *
     * @return array<string,mixed>|null
     */
    public function get_by_wp_user(int $user_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE wp_user_id = %d LIMIT 1", $user_id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return $this->decode_row($row);
    }

    /**
     * Paginated list of profiles.
     *
     * Filters: industry, lifecycle_stage, tenant_id, consent_status, since, until, page, per_page
     */
    public function list(array $filters = []): array {
        global $wpdb;

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['industry'])) {
            $where[]  = 'industry = %s';
            $params[] = sanitize_key($filters['industry']);
        }
        if (!empty($filters['lifecycle_stage'])) {
            $where[]  = 'lifecycle_stage = %s';
            $params[] = sanitize_text_field($filters['lifecycle_stage']);
        }
        if (!empty($filters['tenant_id'])) {
            $where[]  = 'tenant_id = %s';
            $params[] = sanitize_text_field($filters['tenant_id']);
        }
        if (!empty($filters['consent_status'])) {
            $where[]  = 'consent_status = %s';
            $params[] = sanitize_text_field($filters['consent_status']);
        }
        if (!empty($filters['since'])) {
            $where[]  = 'last_seen_at >= %s';
            $params[] = sanitize_text_field($filters['since']);
        }
        if (!empty($filters['until'])) {
            $where[]  = 'last_seen_at <= %s';
            $params[] = sanitize_text_field($filters['until']);
        }

        $where_sql = implode(' AND ', $where);
        $per_page  = max(1, min((int) ($filters['per_page'] ?? 50), 500));
        $page      = max(1, (int) ($filters['page'] ?? 1));
        $offset    = ($page - 1) * $per_page;

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

        $limit_params = array_merge($params, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY last_seen_at DESC LIMIT %d OFFSET %d",
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
     * Delete a subject profile (GDPR erasure).
     */
    public function delete(string $subject_id): bool {
        global $wpdb;
        $result = $wpdb->delete($this->table, ['subject_id' => $subject_id], ['%s']);
        return $result !== false;
    }

    /**
     * Export full profile for portability (GDPR Art.20).
     *
     * @return array<string,mixed>|null
     */
    public function export(string $subject_id): ?array {
        return $this->get($subject_id);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function insert(array $data): int {
        global $wpdb;

        $subject_id   = sanitize_text_field((string) ($data['subject_id'] ?? ''));
        $traits_json  = isset($data['traits']) && is_array($data['traits'])
            ? (wp_json_encode($data['traits']) ?: null)
            : null;

        $row = [
            'subject_id'           => $subject_id,
            'subject_type'         => $this->valid_subject_type((string) ($data['subject_type'] ?? 'visitor')),
            'wp_user_id'           => isset($data['wp_user_id']) ? (int) $data['wp_user_id'] : null,
            'email'                => sanitize_email((string) ($data['email'] ?? '')),
            'display_name'         => sanitize_text_field((string) ($data['display_name'] ?? '')),
            'industry'             => sanitize_key((string) ($data['industry'] ?? 'general')),
            'lifecycle_stage'      => sanitize_text_field((string) ($data['lifecycle_stage'] ?? 'unknown')),
            'acquisition_source'   => sanitize_text_field((string) ($data['acquisition_source'] ?? '')),
            'acquisition_medium'   => sanitize_text_field((string) ($data['acquisition_medium'] ?? '')),
            'acquisition_campaign' => sanitize_text_field((string) ($data['acquisition_campaign'] ?? '')),
            'traits'               => $traits_json,
            'device_fingerprint'   => sanitize_text_field((string) ($data['device_fingerprint'] ?? '')),
            'consent_status'       => sanitize_text_field((string) ($data['consent_status'] ?? 'unknown')),
            'tenant_id'            => sanitize_text_field((string) ($data['tenant_id'] ?? '')),
            'version'              => 1,
        ];
        $row['record_hash'] = $this->compute_hash($subject_id, (string) ($row['traits'] ?? ''), 1);

        $fmt = ['%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s'];
        $result = $wpdb->insert($this->table, $row, $fmt);
        return $result !== false ? (int) $wpdb->insert_id : 0;
    }

    private function update_existing(array $existing, array $data): int {
        global $wpdb;

        $subject_id  = (string) $existing['subject_id'];
        $new_version = ((int) $existing['version']) + 1;

        $traits_json = isset($data['traits']) && is_array($data['traits'])
            ? (wp_json_encode(array_merge(
                is_array($existing['traits'] ?? null) ? $existing['traits'] : [],
                $data['traits']
              )) ?: null)
            : ($existing['traits'] !== [] ? wp_json_encode($existing['traits']) : null);

        $set = [
            'last_seen_at' => current_time('mysql', true),
            'version'      => $new_version,
        ];

        // Overwrite mutable fields if provided
        $mutable = ['email', 'display_name', 'lifecycle_stage', 'acquisition_source',
                    'acquisition_medium', 'acquisition_campaign', 'device_fingerprint',
                    'consent_status', 'industry'];
        foreach ($mutable as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $set[$field] = $field === 'email'
                    ? sanitize_email((string) $data[$field])
                    : sanitize_text_field((string) $data[$field]);
            }
        }
        if (isset($data['wp_user_id']) && (int) $data['wp_user_id'] > 0) {
            $set['wp_user_id'] = (int) $data['wp_user_id'];
        }
        if ($traits_json !== null) {
            $set['traits'] = $traits_json;
        }

        $set['record_hash'] = $this->compute_hash($subject_id, (string) ($set['traits'] ?? ''), $new_version);

        $fmt = array_fill(0, count($set), '%s');
        // version is int
        foreach (array_keys($set) as $i => $k) {
            if (in_array($k, ['version', 'wp_user_id'], true)) {
                $fmt[$i] = '%d';
            }
        }

        $result = $wpdb->update($this->table, $set, ['subject_id' => $subject_id], $fmt, ['%s']);
        return $result !== false ? (int) ($existing['id'] ?? 0) : 0;
    }

    // -------------------------------------------------------------------------
    // HMAC
    // -------------------------------------------------------------------------

    private static function chain_key(): string {
        $root = defined('AUTH_KEY') ? AUTH_KEY : wp_generate_password(64, true, true);
        return hash_hmac('sha256', 'rjv-dc-profile-v1:' . (string) get_option('siteurl', ''), $root);
    }

    private function compute_hash(string $subject_id, string $traits_json, int $version): string {
        $payload = implode('|', [$subject_id, $traits_json, (string) $version]);
        return hash_hmac('sha256', $payload, self::chain_key());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function valid_subject_type(string $t): string {
        return in_array($t, ['user', 'visitor'], true) ? $t : 'visitor';
    }

    /**
     * Decode traits field from JSON.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode_row(array $row): array {
        if (isset($row['traits']) && is_string($row['traits']) && $row['traits'] !== '') {
            $decoded      = json_decode($row['traits'], true);
            $row['traits'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['traits'] = [];
        }
        return $row;
    }
}
