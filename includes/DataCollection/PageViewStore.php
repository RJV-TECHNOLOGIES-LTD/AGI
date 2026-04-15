<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

/**
 * Page View Store
 *
 * Records every page view with full engagement and Core Web Vitals data.
 * Pure capture — the plugin writes, the AGI reads and acts.
 */
final class PageViewStore {

    private static ?self $instance = null;
    private string $table;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rjv_agi_dc_pageviews';
    }

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'rjv_agi_dc_pageviews';
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pageview_id         VARCHAR(36)   NOT NULL,
            session_id          VARCHAR(36)   NOT NULL DEFAULT '',
            subject_id          VARCHAR(120)  NOT NULL DEFAULT '',
            url                 VARCHAR(2048) NOT NULL DEFAULT '',
            url_path            VARCHAR(500)  NOT NULL DEFAULT '',
            referrer            VARCHAR(2048) NOT NULL DEFAULT '',
            title               VARCHAR(500)  NOT NULL DEFAULT '',
            post_id             BIGINT UNSIGNED NULL,
            post_type           VARCHAR(60)   NOT NULL DEFAULT '',
            time_on_page_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            scroll_depth_pct    TINYINT UNSIGNED  NOT NULL DEFAULT 0,
            engaged             TINYINT(1)    NOT NULL DEFAULT 0,
            lcp_ms              SMALLINT UNSIGNED NULL,
            fid_ms              SMALLINT UNSIGNED NULL,
            cls_score           DECIMAL(6,4)  NULL,
            ttfb_ms             SMALLINT UNSIGNED NULL,
            inp_ms              SMALLINT UNSIGNED NULL,
            occurred_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tenant_id           VARCHAR(100)  NOT NULL DEFAULT '',
            UNIQUE INDEX idx_pageview_id (pageview_id),
            INDEX idx_session  (session_id),
            INDEX idx_subject  (subject_id),
            INDEX idx_path     (url_path(400)),
            INDEX idx_post     (post_id),
            INDEX idx_occurred (occurred_at),
            INDEX idx_tenant   (tenant_id)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Record a page view.  Returns inserted row ID on success, 0 on failure.
     *
     * @param array{
     *   pageview_id?:           string,
     *   session_id?:            string,
     *   subject_id?:            string,
     *   url?:                   string,
     *   referrer?:              string,
     *   title?:                 string,
     *   post_id?:               int|null,
     *   post_type?:             string,
     *   time_on_page_seconds?:  int,
     *   scroll_depth_pct?:      int,
     *   engaged?:               bool,
     *   lcp_ms?:                int|null,
     *   fid_ms?:                int|null,
     *   cls_score?:             float|null,
     *   ttfb_ms?:               int|null,
     *   inp_ms?:                int|null,
     *   occurred_at?:           string,
     *   tenant_id?:             string,
     * } $data
     */
    public function record(array $data): int {
        global $wpdb;

        $url      = esc_url_raw((string) ($data['url'] ?? ''));
        $url_path = $url !== '' ? substr(wp_parse_url($url, PHP_URL_PATH) ?? '', 0, 500) : '';

        $row = [
            'pageview_id'          => sanitize_text_field((string) ($data['pageview_id'] ?? wp_generate_uuid4())),
            'session_id'           => sanitize_text_field((string) ($data['session_id'] ?? '')),
            'subject_id'           => sanitize_text_field((string) ($data['subject_id'] ?? '')),
            'url'                  => $url,
            'url_path'             => $url_path,
            'referrer'             => esc_url_raw((string) ($data['referrer'] ?? '')),
            'title'                => sanitize_text_field((string) ($data['title'] ?? '')),
            'post_id'              => isset($data['post_id']) && (int) $data['post_id'] > 0 ? (int) $data['post_id'] : null,
            'post_type'            => sanitize_key((string) ($data['post_type'] ?? '')),
            'time_on_page_seconds' => max(0, (int) ($data['time_on_page_seconds'] ?? 0)),
            'scroll_depth_pct'     => min(100, max(0, (int) ($data['scroll_depth_pct'] ?? 0))),
            'engaged'              => (int) (bool) ($data['engaged'] ?? false),
            'lcp_ms'               => isset($data['lcp_ms']) ? max(0, (int) $data['lcp_ms']) : null,
            'fid_ms'               => isset($data['fid_ms']) ? max(0, (int) $data['fid_ms']) : null,
            'cls_score'            => isset($data['cls_score']) ? round((float) $data['cls_score'], 4) : null,
            'ttfb_ms'              => isset($data['ttfb_ms']) ? max(0, (int) $data['ttfb_ms']) : null,
            'inp_ms'               => isset($data['inp_ms']) ? max(0, (int) $data['inp_ms']) : null,
            'occurred_at'          => $this->normalise_datetime((string) ($data['occurred_at'] ?? '')),
            'tenant_id'            => sanitize_text_field((string) ($data['tenant_id'] ?? '')),
        ];

        $fmt = ['%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%d','%d','%d','%d','%f','%d','%d','%s','%s'];
        $result = $wpdb->insert($this->table, $row, $fmt);
        return $result !== false ? (int) $wpdb->insert_id : 0;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Paginated query of page views.
     *
     * Filters: session_id, subject_id, post_id, url_path, tenant_id, since, until, page, per_page
     */
    public function query(array $filters = []): array {
        global $wpdb;

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['session_id'])) {
            $where[]  = 'session_id = %s';
            $params[] = sanitize_text_field($filters['session_id']);
        }
        if (!empty($filters['subject_id'])) {
            $where[]  = 'subject_id = %s';
            $params[] = sanitize_text_field($filters['subject_id']);
        }
        if (!empty($filters['post_id'])) {
            $where[]  = 'post_id = %d';
            $params[] = (int) $filters['post_id'];
        }
        if (!empty($filters['url_path'])) {
            $where[]  = 'url_path LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($filters['url_path'])) . '%';
        }
        if (!empty($filters['tenant_id'])) {
            $where[]  = 'tenant_id = %s';
            $params[] = sanitize_text_field($filters['tenant_id']);
        }
        if (!empty($filters['since'])) {
            $where[]  = 'occurred_at >= %s';
            $params[] = sanitize_text_field($filters['since']);
        }
        if (!empty($filters['until'])) {
            $where[]  = 'occurred_at <= %s';
            $params[] = sanitize_text_field($filters['until']);
        }

        $where_sql = implode(' AND ', $where);
        $per_page  = max(1, min((int) ($filters['per_page'] ?? 100), 1000));
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
            "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY occurred_at DESC LIMIT %d OFFSET %d",
            $limit_params
        ), ARRAY_A);

        return [
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
        ];
    }

    /**
     * Delete all page views for a subject (GDPR erasure).
     */
    public function delete_by_subject(string $subject_id): int {
        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['subject_id' => $subject_id], ['%s']);
        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Export all page views for a subject (GDPR portability).
     *
     * @return list<array<string,mixed>>
     */
    public function export_by_subject(string $subject_id): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE subject_id = %s ORDER BY occurred_at ASC",
                $subject_id
            ),
            ARRAY_A
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalise_datetime(string $dt): string {
        if ($dt === '') {
            return current_time('mysql', true);
        }
        $ts = strtotime($dt);
        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : current_time('mysql', true);
    }
}
