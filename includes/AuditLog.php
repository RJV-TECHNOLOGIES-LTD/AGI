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

        // Stamp HMAC chain on the inserted entry
        $inserted_id = (int) $wpdb->insert_id;
        if ($inserted_id > 0) {
            self::stamp_hmac($inserted_id);
        }
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
    // HMAC tamper-evident chain
    // -------------------------------------------------------------------------

    /**
     * Derive the HMAC-SHA256 signing key for the audit chain.
     *
     * Key = HMAC-SHA256( AUTH_KEY, "rjv-audit-chain-v1:{SITEURL}" )
     * This binds the key to the specific WordPress installation.
     */
    private static function chain_key(): string {
        $root = defined('AUTH_KEY') ? AUTH_KEY : (string) get_option('rjv_agi_chain_key_fallback', '');
        if ($root === '') {
            // Generate and persist a fallback key (only used when AUTH_KEY is unavailable)
            $root = get_option('rjv_agi_chain_key_fallback', '');
            if ($root === '') {
                $root = bin2hex(random_bytes(32));
                update_option('rjv_agi_chain_key_fallback', $root, false);
            }
        }
        return hash_hmac('sha256', 'rjv-audit-chain-v1:' . (string) get_option('siteurl', ''), $root);
    }

    /**
     * Compute the HMAC for a single entry row.
     *
     * Covers: id, timestamp, action, resource_type, resource_id, details,
     *          ip_address, tier, status, tokens_used, model_used, prev_hmac
     */
    private static function compute_entry_hmac(array $row, string $prev_hmac): string {
        $payload = implode('|', [
            (string) ($row['id']            ?? ''),
            (string) ($row['timestamp']     ?? ''),
            (string) ($row['action']        ?? ''),
            (string) ($row['resource_type'] ?? ''),
            (string) ($row['resource_id']   ?? ''),
            (string) ($row['details']       ?? ''),
            (string) ($row['ip_address']    ?? ''),
            (string) ($row['tier']          ?? ''),
            (string) ($row['status']        ?? ''),
            (string) ($row['tokens_used']   ?? ''),
            (string) ($row['model_used']    ?? ''),
            $prev_hmac,
        ]);
        return hash_hmac('sha256', $payload, self::chain_key());
    }

    /**
     * Stamp the most recent audit entry with a chain HMAC.
     * Called immediately after the INSERT in log().
     *
     * @param int $inserted_id  The auto-increment ID just inserted.
     */
    private static function stamp_hmac(int $inserted_id): void {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;

        // Fetch the row we just inserted
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $inserted_id
        ), ARRAY_A);

        if (!$row) {
            return;
        }

        // Get the immediately preceding entry's HMAC
        $prev = $wpdb->get_var($wpdb->prepare(
            "SELECT entry_hmac FROM {$table} WHERE id < %d ORDER BY id DESC LIMIT 1",
            $inserted_id
        ));
        $prev_hmac = $prev ?: 'genesis';

        $hmac = self::compute_entry_hmac($row, $prev_hmac);

        $wpdb->update($table, ['entry_hmac' => $hmac], ['id' => $inserted_id], ['%s'], ['%d']);
    }

    /**
     * Verify the integrity of the entire audit chain.
     *
     * Iterates every entry in ascending id order, recomputes its HMAC, and
     * compares against the stored value.  Returns immediately on the first
     * discrepancy.
     *
     * @return array{valid: bool, entries_checked: int, first_violation?: int, error?: string}
     */
    public static function verify_chain(): array {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;

        $prev_hmac = 'genesis';
        $checked   = 0;

        // Process in pages of 500 to keep memory bounded
        $page = 0;
        while (true) {
            $offset = $page * 500;
            $rows   = $wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY id ASC LIMIT 500 OFFSET {$offset}",
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!isset($row['entry_hmac']) || $row['entry_hmac'] === null) {
                    // Entry predates chain support – skip but note
                    $prev_hmac = 'genesis'; // treat as break in the old chain
                    $checked++;
                    continue;
                }

                $expected = self::compute_entry_hmac($row, $prev_hmac);
                if (!hash_equals($expected, (string) $row['entry_hmac'])) {
                    return [
                        'valid'           => false,
                        'entries_checked' => $checked,
                        'first_violation' => (int) $row['id'],
                        'error'           => 'HMAC mismatch – possible tampering detected at entry id ' . $row['id'],
                    ];
                }

                $prev_hmac = (string) $row['entry_hmac'];
                $checked++;
            }

            $page++;
        }

        return ['valid' => true, 'entries_checked' => $checked];
    }

    /**
     * Export audit entries in JSON Lines format (one JSON object per line).
     * This format is directly consumable by most SIEMs (Elastic, Splunk, etc.).
     *
     * @param array  $filters  Same filter keys accepted by query().
     * @param string $dest     'file' (default) or 'stream'.
     * @return array{success: bool, path?: string, url?: string, lines?: int, error?: string}
     */
    public static function export_jsonl(array $filters = [], string $dest = 'file'): array {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return ['success' => false, 'error' => $upload['error']];
        }

        $dir = trailingslashit((string) $upload['basedir']) . 'rjv-agi-exports';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return ['success' => false, 'error' => 'Cannot create export directory'];
        }

        // Place an index.php guard in the exports folder
        $guard = $dir . '/index.php';
        if (!file_exists($guard)) {
            file_put_contents($guard, '<?php // silence');
        }

        $filename = 'audit-' . gmdate('Ymd-His') . '-' . substr(wp_generate_uuid4(), 0, 8) . '.jsonl';
        $path     = $dir . '/' . $filename;
        $url      = trailingslashit((string) $upload['baseurl']) . 'rjv-agi-exports/' . $filename;

        $fh = fopen($path, 'wb');
        if ($fh === false) {
            return ['success' => false, 'error' => 'Cannot open export file for writing'];
        }

        // Paginate to avoid memory exhaustion on large logs
        $page    = 1;
        $written = 0;
        $filters['per_page'] = 500;

        do {
            $filters['page'] = $page;
            $result          = self::query($filters);
            $entries         = $result['entries'] ?? [];

            foreach ($entries as $entry) {
                // Remove any internal column that the caller may not want
                unset($entry['details_raw']);
                fwrite($fh, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n");
                $written++;
            }

            $page++;
        } while ($page <= (int) ($result['pages'] ?? 1));

        fclose($fh);

        AuditLog::log('audit_export_jsonl', 'audit', 0, [
            'filename' => $filename,
            'lines'    => $written,
            'filters'  => $filters,
        ], 2);

        return ['success' => true, 'path' => $path, 'url' => $url, 'lines' => $written];
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
