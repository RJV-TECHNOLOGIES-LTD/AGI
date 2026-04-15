<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

/**
 * Session Manager
 *
 * Manages the full lifecycle of visitor/user sessions captured by the
 * data-collection layer.  Each session carries device, browser, OS, UTM
 * attribution, geo hints (from IP), and engagement counters.
 *
 * The plugin captures and stores — the AGI reads and acts.
 */
final class SessionManager {

    private static ?self $instance = null;
    private string $table;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rjv_agi_dc_sessions';
    }

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'rjv_agi_dc_sessions';
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id         VARCHAR(36)   NOT NULL,
            subject_id         VARCHAR(120)  NOT NULL DEFAULT '',
            subject_type       ENUM('user','visitor') NOT NULL DEFAULT 'visitor',
            started_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at           DATETIME      NULL,
            duration_seconds   INT UNSIGNED  NULL,
            page_count         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            event_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            entry_url          VARCHAR(2048) NOT NULL DEFAULT '',
            exit_url           VARCHAR(2048) NOT NULL DEFAULT '',
            referrer           VARCHAR(2048) NOT NULL DEFAULT '',
            utm_source         VARCHAR(200)  NOT NULL DEFAULT '',
            utm_medium         VARCHAR(200)  NOT NULL DEFAULT '',
            utm_campaign       VARCHAR(200)  NOT NULL DEFAULT '',
            utm_term           VARCHAR(200)  NOT NULL DEFAULT '',
            utm_content        VARCHAR(200)  NOT NULL DEFAULT '',
            device_type        VARCHAR(30)   NOT NULL DEFAULT '',
            device_os          VARCHAR(80)   NOT NULL DEFAULT '',
            browser            VARCHAR(80)   NOT NULL DEFAULT '',
            browser_version    VARCHAR(30)   NOT NULL DEFAULT '',
            screen_resolution  VARCHAR(20)   NOT NULL DEFAULT '',
            viewport           VARCHAR(20)   NOT NULL DEFAULT '',
            language           VARCHAR(10)   NOT NULL DEFAULT '',
            timezone           VARCHAR(60)   NOT NULL DEFAULT '',
            ip_address         VARCHAR(45)   NOT NULL DEFAULT '',
            country_code       CHAR(2)       NOT NULL DEFAULT '',
            region             VARCHAR(80)   NOT NULL DEFAULT '',
            city               VARCHAR(80)   NOT NULL DEFAULT '',
            industry           VARCHAR(60)   NOT NULL DEFAULT 'general',
            tenant_id          VARCHAR(100)  NOT NULL DEFAULT '',
            extra              LONGTEXT      NULL,
            UNIQUE INDEX idx_session_id (session_id),
            INDEX idx_subject    (subject_id),
            INDEX idx_started    (started_at),
            INDEX idx_industry   (industry),
            INDEX idx_tenant     (tenant_id)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Create a new session record.  Returns the session_id on success, '' on failure.
     *
     * @param array{
     *   session_id?:        string,
     *   subject_id?:        string,
     *   subject_type?:      string,
     *   entry_url?:         string,
     *   referrer?:          string,
     *   utm_source?:        string,
     *   utm_medium?:        string,
     *   utm_campaign?:      string,
     *   utm_term?:          string,
     *   utm_content?:       string,
     *   user_agent?:        string,
     *   screen_resolution?: string,
     *   viewport?:          string,
     *   language?:          string,
     *   timezone?:          string,
     *   ip_address?:        string,
     *   country_code?:      string,
     *   region?:            string,
     *   city?:              string,
     *   industry?:          string,
     *   tenant_id?:         string,
     *   extra?:             array<string,mixed>,
     * } $data
     */
    public function start(array $data): string {
        global $wpdb;

        $session_id = sanitize_text_field((string) ($data['session_id'] ?? wp_generate_uuid4()));
        $ua         = (string) ($data['user_agent'] ?? '');
        $device     = $this->parse_device($ua);

        $extra_json = isset($data['extra']) && is_array($data['extra'])
            ? (wp_json_encode($data['extra']) ?: null)
            : null;

        $row = [
            'session_id'        => $session_id,
            'subject_id'        => sanitize_text_field((string) ($data['subject_id'] ?? '')),
            'subject_type'      => $this->valid_subject_type((string) ($data['subject_type'] ?? 'visitor')),
            'entry_url'         => esc_url_raw((string) ($data['entry_url'] ?? '')),
            'referrer'          => esc_url_raw((string) ($data['referrer'] ?? '')),
            'utm_source'        => sanitize_text_field((string) ($data['utm_source'] ?? '')),
            'utm_medium'        => sanitize_text_field((string) ($data['utm_medium'] ?? '')),
            'utm_campaign'      => sanitize_text_field((string) ($data['utm_campaign'] ?? '')),
            'utm_term'          => sanitize_text_field((string) ($data['utm_term'] ?? '')),
            'utm_content'       => sanitize_text_field((string) ($data['utm_content'] ?? '')),
            'device_type'       => $device['type'],
            'device_os'         => $device['os'],
            'browser'           => $device['browser'],
            'browser_version'   => $device['browser_version'],
            'screen_resolution' => sanitize_text_field((string) ($data['screen_resolution'] ?? '')),
            'viewport'          => sanitize_text_field((string) ($data['viewport'] ?? '')),
            'language'          => sanitize_text_field(substr((string) ($data['language'] ?? ''), 0, 10)),
            'timezone'          => sanitize_text_field((string) ($data['timezone'] ?? '')),
            'ip_address'        => $this->sanitize_ip((string) ($data['ip_address'] ?? '')),
            'country_code'      => strtoupper(sanitize_text_field(substr((string) ($data['country_code'] ?? ''), 0, 2))),
            'region'            => sanitize_text_field((string) ($data['region'] ?? '')),
            'city'              => sanitize_text_field((string) ($data['city'] ?? '')),
            'industry'          => sanitize_key((string) ($data['industry'] ?? 'general')),
            'tenant_id'         => sanitize_text_field((string) ($data['tenant_id'] ?? '')),
            'extra'             => $extra_json,
        ];

        $fmt = ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];
        $result = $wpdb->insert($this->table, $row, $fmt);

        return $result !== false ? $session_id : '';
    }

    /**
     * Update session heartbeat, page_count, event_count.
     */
    public function touch(string $session_id, array $updates = []): bool {
        global $wpdb;

        $set = ['last_seen_at' => current_time('mysql', true)];

        if (isset($updates['exit_url'])) {
            $set['exit_url'] = esc_url_raw((string) $updates['exit_url']);
        }
        if (isset($updates['page_count'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->table} SET page_count = page_count + %d, last_seen_at = %s WHERE session_id = %s",
                    max(0, (int) $updates['page_count']),
                    current_time('mysql', true),
                    $session_id
                )
            );
            unset($set['last_seen_at']); // already handled above
        }
        if (isset($updates['event_count'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->table} SET event_count = event_count + %d WHERE session_id = %s",
                    max(0, (int) $updates['event_count']),
                    $session_id
                )
            );
        }

        if (count($set) === 0) {
            return true;
        }

        $result = $wpdb->update($this->table, $set, ['session_id' => $session_id], array_fill(0, count($set), '%s'), ['%s']);
        return $result !== false;
    }

    /**
     * Close a session, recording exit URL and computing duration.
     */
    public function close(string $session_id, string $exit_url = ''): bool {
        global $wpdb;

        $session = $this->get($session_id);
        if (!$session) {
            return false;
        }

        $started  = strtotime((string) ($session['started_at'] ?? 'now'));
        $duration = max(0, (int) (time() - ($started ?: time())));

        $set = [
            'ended_at'         => current_time('mysql', true),
            'duration_seconds' => $duration,
            'last_seen_at'     => current_time('mysql', true),
        ];
        if ($exit_url !== '') {
            $set['exit_url'] = esc_url_raw($exit_url);
        }

        $fmt = array_fill(0, count($set), '%s');
        $fmt[1] = '%d'; // duration_seconds

        $result = $wpdb->update($this->table, $set, ['session_id' => $session_id], $fmt, ['%s']);
        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get a single session by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $session_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE session_id = %s LIMIT 1", $session_id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return $this->decode_row($row);
    }

    /**
     * Paginated list of sessions.
     *
     * Filters: subject_id, industry, tenant_id, since, until, page, per_page
     */
    public function list(array $filters = []): array {
        global $wpdb;

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['subject_id'])) {
            $where[]  = 'subject_id = %s';
            $params[] = sanitize_text_field($filters['subject_id']);
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
            $where[]  = 'started_at >= %s';
            $params[] = sanitize_text_field($filters['since']);
        }
        if (!empty($filters['until'])) {
            $where[]  = 'started_at <= %s';
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
            "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY started_at DESC LIMIT %d OFFSET %d",
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
     * Delete all sessions for a subject (GDPR erasure).
     */
    public function delete_by_subject(string $subject_id): int {
        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['subject_id' => $subject_id], ['%s']);
        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Export all sessions for a subject (GDPR portability).
     *
     * @return list<array<string,mixed>>
     */
    public function export_by_subject(string $subject_id): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE subject_id = %s ORDER BY started_at ASC", $subject_id),
            ARRAY_A
        );
        return array_map([$this, 'decode_row'], $rows);
    }

    // -------------------------------------------------------------------------
    // Device / UA parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a User-Agent string into device type, OS, browser, and version.
     * Lightweight regex-based; covers > 95 % of real-world traffic.
     *
     * @return array{type: string, os: string, browser: string, browser_version: string}
     */
    public function parse_device(string $ua): array {
        $ua_lower = strtolower($ua);

        // ── Device type ───────────────────────────────────────────────────────
        if (preg_match('/tablet|ipad|kindle|playbook|silk|(android(?!.*mobile))/i', $ua)) {
            $type = 'tablet';
        } elseif (preg_match('/mobile|android.*mobile|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop/i', $ua)) {
            $type = 'mobile';
        } else {
            $type = 'desktop';
        }

        // ── OS ────────────────────────────────────────────────────────────────
        $os = 'unknown';
        if (str_contains($ua_lower, 'windows nt')) {
            $os = 'Windows';
        } elseif (str_contains($ua_lower, 'mac os x')) {
            $os = 'macOS';
        } elseif (str_contains($ua_lower, 'android')) {
            $os = 'Android';
        } elseif (str_contains($ua_lower, 'iphone os') || str_contains($ua_lower, 'ipad')) {
            $os = 'iOS';
        } elseif (str_contains($ua_lower, 'linux')) {
            $os = 'Linux';
        } elseif (str_contains($ua_lower, 'cros')) {
            $os = 'ChromeOS';
        }

        // ── Browser ───────────────────────────────────────────────────────────
        $browser = 'unknown';
        $version = '';

        if (preg_match('/edg\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Edge';
            $version = $m[1];
        } elseif (preg_match('/opr\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Opera';
            $version = $m[1];
        } elseif (preg_match('/chrome\/([\d.]+)/i', $ua, $m) && !str_contains($ua_lower, 'chromium')) {
            $browser = 'Chrome';
            $version = $m[1];
        } elseif (preg_match('/firefox\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Firefox';
            $version = $m[1];
        } elseif (preg_match('/safari\/([\d.]+)/i', $ua, $m) && !str_contains($ua_lower, 'chrome')) {
            $browser = 'Safari';
            if (preg_match('/version\/([\d.]+)/i', $ua, $mv)) {
                $version = $mv[1];
            }
        } elseif (preg_match('/msie ([\d.]+)/i', $ua, $m) || preg_match('/trident.*rv:([\d.]+)/i', $ua, $m)) {
            $browser = 'IE';
            $version = $m[1];
        }

        return [
            'type'            => $type,
            'os'              => $os,
            'browser'         => $browser,
            'browser_version' => $version,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function valid_subject_type(string $t): string {
        return in_array($t, ['user', 'visitor'], true) ? $t : 'visitor';
    }

    private function sanitize_ip(string $ip): string {
        // Accept IPv4 and IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '';
    }

    /**
     * Decode extra field from JSON.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode_row(array $row): array {
        if (isset($row['extra']) && is_string($row['extra']) && $row['extra'] !== '') {
            $decoded   = json_decode($row['extra'], true);
            $row['extra'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['extra'] = [];
        }
        return $row;
    }
}
