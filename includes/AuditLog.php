<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

/**
 * Audit Log
 *
 * Centralised, append-only audit log for all AGI operations.
 * Provides structured querying, per-action statistics, CSV export,
 * and automated log-rotation.
 */
final class AuditLog {

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Append one audit entry.
     *
     * @param string      $action       Machine-readable action identifier.
     * @param string      $resource_type Resource category (post, media, user …)
     * @param int         $resource_id  Primary resource ID (0 = not applicable).
     * @param array       $details      Arbitrary context data (JSON-encoded).
     * @param int         $tier         Auth tier that performed the action.
     * @param string      $status       'success' | 'error' | 'warning'
     * @param int|null    $ms           Execution time in milliseconds.
     * @param int|null    $tokens       AI tokens consumed.
     * @param string|null $model        AI model identifier.
     */
    public static function log(
        string  $action,
        string  $resource_type = '',
        int     $resource_id   = 0,
        array   $details       = [],
        int     $tier          = 1,
        string  $status        = 'success',
        ?int    $ms            = null,
        ?int    $tokens        = null,
        ?string $model         = null
    ): void {
        if (get_option('rjv_agi_audit_enabled', '1') !== '1') {
            return;
        }

        global $wpdb;

        $ip = self::client_ip();

        $wpdb->insert(
            $wpdb->prefix . RJV_AGI_LOG_TABLE,
            [
                'timestamp'        => current_time('mysql', true),
                'agent_id'         => sanitize_text_field((string) ($details['agent_id'] ?? 'system')),
                'action'           => sanitize_text_field($action),
                'resource_type'    => sanitize_text_field($resource_type),
                'resource_id'      => $resource_id,
                'details'          => wp_json_encode($details),
                'ip_address'       => $ip,
                'tier'             => $tier,
                'status'           => in_array($status, ['success', 'error', 'warning'], true) ? $status : 'success',
                'execution_time_ms'=> $ms,
                'tokens_used'      => $tokens,
                'model_used'       => $model ? sanitize_text_field($model) : null,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    /**
     * Query audit log entries with filtering and pagination.
     *
     * @param array $args {
     *   @type string $action        Filter by exact action name.
     *   @type string $action_like   Filter by action LIKE pattern.
     *   @type string $agent_id      Filter by agent ID.
     *   @type int    $tier          Filter by tier (1, 2, or 3).
     *   @type string $status        Filter by status ('success'|'error'|'warning').
     *   @type string $resource_type Filter by resource type.
     *   @type int    $resource_id   Filter by resource ID.
     *   @type string $since         ISO-8601 / MySQL datetime lower bound.
     *   @type string $until         ISO-8601 / MySQL datetime upper bound.
     *   @type string $ip_address    Filter by IP address.
     *   @type string $model         Filter by model_used.
     *   @type int    $per_page      Results per page (max 500, default 50).
     *   @type int    $page          Page number (default 1).
     *   @type string $order         'ASC' or 'DESC' (default 'DESC').
     * }
     * @return array{entries: array, total: int, pages: int, page: int, per_page: int}
     */
    public static function query(array $args = []): array {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;

        $where  = ['1=1'];
        $params = [];

        if (!empty($args['action'])) {
            $where[]  = 'action = %s';
            $params[] = $args['action'];
        }

        if (!empty($args['action_like'])) {
            $where[]  = 'action LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $args['action_like']) . '%';
        }

        if (!empty($args['agent_id'])) {
            $where[]  = 'agent_id = %s';
            $params[] = $args['agent_id'];
        }

        if (!empty($args['tier'])) {
            $where[]  = 'tier = %d';
            $params[] = (int) $args['tier'];
        }

        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['resource_type'])) {
            $where[]  = 'resource_type = %s';
            $params[] = $args['resource_type'];
        }

        if (!empty($args['resource_id'])) {
            $where[]  = 'resource_id = %d';
            $params[] = (int) $args['resource_id'];
        }

        if (!empty($args['since'])) {
            $where[]  = 'timestamp >= %s';
            $params[] = $args['since'];
        }

        if (!empty($args['until'])) {
            $where[]  = 'timestamp <= %s';
            $params[] = $args['until'];
        }

        if (!empty($args['ip_address'])) {
            $where[]  = 'ip_address = %s';
            $params[] = $args['ip_address'];
        }

        if (!empty($args['model'])) {
            $where[]  = 'model_used = %s';
            $params[] = $args['model'];
        }

        $per_page  = max(1, min((int) ($args['per_page'] ?? 50), 500));
        $page      = max(1, (int) ($args['page'] ?? 1));
        $offset    = ($page - 1) * $per_page;
        $order     = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $ws        = implode(' AND ', $where);

        // Total count
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$ws}";
        $total     = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
            : $wpdb->get_var($count_sql));

        // Paginated rows
        $data_sql = "SELECT * FROM {$table} WHERE {$ws} ORDER BY id {$order} LIMIT %d OFFSET %d";
        $rows     = $params
            ? ($wpdb->get_results($wpdb->prepare($data_sql, ...array_merge($params, [$per_page, $offset])), ARRAY_A) ?: [])
            : ($wpdb->get_results($wpdb->prepare($data_sql, $per_page, $offset), ARRAY_A) ?: []);

        return [
            'entries'  => $rows,
            'total'    => $total,
            'pages'    => (int) ceil($total / $per_page),
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    // -------------------------------------------------------------------------
    // Statistics
    // -------------------------------------------------------------------------

    /**
     * Return aggregate statistics for the given time window.
     *
     * @param string $window '24h' | '7d' | '30d' | 'all' (default '24h')
     * @return array<string, mixed>
     */
    public static function stats(string $window = '24h'): array {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;

        $since = match ($window) {
            '7d'  => gmdate('Y-m-d H:i:s', strtotime('-7 days')),
            '30d' => gmdate('Y-m-d H:i:s', strtotime('-30 days')),
            'all' => '1970-01-01 00:00:00',
            default => gmdate('Y-m-d 00:00:00'),   // '24h' / today
        };

        $total  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s", $since));
        $errors = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s AND status = 'error'", $since));

        // AI usage
        $ai_calls  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s AND action LIKE 'ai_%'", $since));
        $ai_tokens = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(tokens_used),0) FROM {$table} WHERE timestamp >= %s", $since));

        // P95 latency
        $latencies = $wpdb->get_col($wpdb->prepare(
            "SELECT execution_time_ms FROM {$table} WHERE timestamp >= %s AND execution_time_ms IS NOT NULL ORDER BY execution_time_ms ASC",
            $since
        )) ?: [];
        $latencies = array_values(array_filter(array_map('intval', $latencies), fn($v) => $v >= 0));
        $p95       = self::percentile($latencies, 95);

        // Top actions (top 10)
        $top_actions = $wpdb->get_results($wpdb->prepare(
            "SELECT action, COUNT(*) AS count FROM {$table} WHERE timestamp >= %s GROUP BY action ORDER BY count DESC LIMIT 10",
            $since
        ), ARRAY_A) ?: [];

        // By tier
        $by_tier = $wpdb->get_results($wpdb->prepare(
            "SELECT tier, COUNT(*) AS count FROM {$table} WHERE timestamp >= %s GROUP BY tier ORDER BY tier",
            $since
        ), ARRAY_A) ?: [];

        // Model usage
        $by_model = $wpdb->get_results($wpdb->prepare(
            "SELECT model_used, COUNT(*) AS calls, COALESCE(SUM(tokens_used),0) AS tokens FROM {$table} WHERE timestamp >= %s AND model_used IS NOT NULL GROUP BY model_used ORDER BY calls DESC",
            $since
        ), ARRAY_A) ?: [];

        return [
            'window'       => $window,
            'since'        => $since,
            'total'        => $total,
            'errors'       => $errors,
            'error_rate'   => $total > 0 ? round(($errors / $total) * 100, 2) : 0.0,
            'ai_calls'     => $ai_calls,
            'ai_tokens'    => $ai_tokens,
            'p95_latency_ms' => $p95,
            'top_actions'  => $top_actions,
            'by_tier'      => $by_tier,
            'by_model'     => $by_model,
        ];
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    /**
     * Export audit log entries to CSV (written to uploads/rjv-agi-exports/).
     *
     * @param  array  $filters  Same filter keys as query().
     * @return array{success: bool, path?: string, url?: string, rows?: int, error?: string}
     */
    public static function export_csv(array $filters = []): array {
        $filters['per_page'] = 10000;
        $filters['page']     = 1;
        $result              = self::query($filters);

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return ['success' => false, 'error' => $upload['error']];
        }

        $dir = trailingslashit((string) $upload['basedir']) . 'rjv-agi-exports';
        if (!wp_mkdir_p($dir)) {
            return ['success' => false, 'error' => 'Cannot create export directory'];
        }

        $filename = 'audit-log-' . gmdate('Y-m-d-His') . '.csv';
        $path     = $dir . '/' . $filename;
        $url      = trailingslashit((string) $upload['baseurl']) . 'rjv-agi-exports/' . $filename;

        $fh = fopen($path, 'w');
        if ($fh === false) {
            return ['success' => false, 'error' => 'Cannot open file for writing'];
        }

        $columns = ['id', 'timestamp', 'agent_id', 'action', 'resource_type', 'resource_id', 'status', 'tier', 'ip_address', 'execution_time_ms', 'tokens_used', 'model_used', 'details'];
        fputcsv($fh, $columns);

        foreach ($result['entries'] as $row) {
            fputcsv($fh, array_map(fn($col) => $row[$col] ?? '', $columns));
        }

        fclose($fh);

        return [
            'success' => true,
            'path'    => $path,
            'url'     => $url,
            'rows'    => count($result['entries']),
        ];
    }

    // -------------------------------------------------------------------------
    // Maintenance
    // -------------------------------------------------------------------------

    /**
     * Delete audit entries older than the configured retention period.
     *
     * @return int Number of rows deleted.
     */
    public static function cleanup(): int {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $days  = (int) get_option('rjv_agi_log_retention_days', 90);

        if ($days < 1) {
            return 0;
        }

        $cutoff  = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE timestamp < %s LIMIT 5000", $cutoff));

        if ($deleted > 0) {
            self::log('log_cleanup', 'audit', 0, ['deleted' => $deleted, 'cutoff' => $cutoff, 'retention_days' => $days]);
        }

        return $deleted;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', sanitize_text_field(wp_unslash((string) $_SERVER[$h])))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /** Calculate the Nth percentile of a sorted integer array. */
    private static function percentile(array $sorted, int $p): int {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        $idx = (int) ceil($p / 100 * $n) - 1;
        return (int) ($sorted[max(0, min($idx, $n - 1))] ?? 0);
    }
}
