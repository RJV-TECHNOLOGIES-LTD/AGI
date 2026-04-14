<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Database Management API
 *
 * Provides safe, read-heavy database introspection plus controlled
 * write operations (optimize, repair, check) to the AGI orchestrator.
 * SELECT-only queries are allowed with a strict keyword blocklist.
 */
class Database extends Base {

    /** Keywords that must not appear anywhere in an allowed SELECT query. */
    private const BLOCKED_KEYWORDS = [
        'DROP', 'DELETE', 'UPDATE', 'INSERT', 'ALTER', 'TRUNCATE',
        'GRANT', 'REVOKE', 'CREATE', 'RENAME', 'REPLACE', 'CALL',
        'EXEC', 'EXECUTE', 'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE',
        'BENCHMARK', 'SLEEP', 'INFORMATION_SCHEMA', 'MYSQL.',
    ];

    public function register_routes(): void {
        register_rest_route($this->namespace, '/database/tables', [
            ['methods' => 'GET', 'callback' => [$this, 'tables'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/database/tables/(?P<name>[a-zA-Z0-9_]+)', [
            ['methods' => 'GET', 'callback' => [$this, 'table_detail'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/database/query', [
            ['methods' => 'POST', 'callback' => [$this, 'query'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/database/explain', [
            ['methods' => 'POST', 'callback' => [$this, 'explain'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/database/optimize', [
            ['methods' => 'POST', 'callback' => [$this, 'optimize'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/database/repair', [
            ['methods' => 'POST', 'callback' => [$this, 'repair'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/database/check', [
            ['methods' => 'POST', 'callback' => [$this, 'check'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/database/slow-queries', [
            ['methods' => 'GET', 'callback' => [$this, 'slow_queries'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/database/autoload', [
            ['methods' => 'GET', 'callback' => [$this, 'large_autoloads'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Table listing
    // -------------------------------------------------------------------------

    public function tables(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        $rows = (array) $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);

        $tables = array_map(fn(array $t): array => [
            'name'           => $t['Name'],
            'engine'         => $t['Engine'] ?? '',
            'rows'           => (int) $t['Rows'],
            'data_length'    => (int) $t['Data_length'],
            'index_length'   => (int) $t['Index_length'],
            'data_free'      => (int) $t['Data_free'],
            'size_bytes'     => (int) $t['Data_length'] + (int) $t['Index_length'],
            'size_formatted' => size_format((int) $t['Data_length'] + (int) $t['Index_length']),
            'collation'      => $t['Collation'] ?? '',
            'auto_increment' => isset($t['Auto_increment']) ? (int) $t['Auto_increment'] : null,
            'create_time'    => $t['Create_time'] ?? '',
            'update_time'    => $t['Update_time'] ?? '',
        ], $rows);

        $total_size = array_sum(array_column($tables, 'size_bytes'));

        $this->log('db_list_tables', 'database', 0, ['count' => count($tables)]);

        return $this->success([
            'tables'           => $tables,
            'count'            => count($tables),
            'total_size'       => $total_size,
            'total_size_formatted' => size_format($total_size),
        ]);
    }

    // -------------------------------------------------------------------------
    // Table detail (schema + indexes)
    // -------------------------------------------------------------------------

    public function table_detail(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $name = sanitize_key((string) $r['name']);
        if ($name === '') {
            return $this->error('Invalid table name');
        }

        // Verify table exists
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name));
        if (!$exists) {
            return $this->error("Table '{$name}' not found", 404);
        }

        // Columns
        $columns = (array) $wpdb->get_results("DESCRIBE `{$name}`", ARRAY_A);

        // Indexes
        $indexes = (array) $wpdb->get_results("SHOW INDEX FROM `{$name}`", ARRAY_A);

        // Row count and size
        $status = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$name}'", ARRAY_A);

        $this->log('db_table_detail', 'database', 0, ['table' => $name]);

        return $this->success([
            'name'    => $name,
            'columns' => $columns,
            'indexes' => $indexes,
            'status'  => $status ?: [],
        ]);
    }

    // -------------------------------------------------------------------------
    // SELECT-only query
    // -------------------------------------------------------------------------

    public function query(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d   = (array) $r->get_json_params();
        $sql = trim((string) ($d['sql'] ?? ''));

        if ($sql === '') {
            return $this->error('sql is required');
        }

        if (strlen($sql) > 8000) {
            return $this->error('Query too long (max 8000 chars)', 422);
        }

        $limit = max(1, min((int) ($d['limit'] ?? 500), 2000));

        $error = $this->validate_select_query($sql);
        if ($error !== null) {
            return $this->error($error, 403);
        }

        global $wpdb;

        $start   = microtime(true);
        $results = $wpdb->get_results($wpdb->remove_placeholder_escape($sql), ARRAY_A);
        $ms      = (int) ((microtime(true) - $start) * 1000);

        if ($wpdb->last_error) {
            return $this->error('Query failed: ' . $wpdb->last_error, 500);
        }

        $rows = is_array($results) ? array_slice($results, 0, $limit) : [];

        $this->log('db_query', 'database', 0, [
            'len'       => strlen($sql),
            'hash'      => hash('sha256', $sql),
            'rows'      => count($rows),
            'latency_ms'=> $ms,
        ], 3);

        return $this->success([
            'rows'       => $rows,
            'count'      => count($rows),
            'latency_ms' => $ms,
            'truncated'  => is_array($results) && count($results) > $limit,
        ]);
    }

    // -------------------------------------------------------------------------
    // EXPLAIN
    // -------------------------------------------------------------------------

    public function explain(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d   = (array) $r->get_json_params();
        $sql = trim((string) ($d['sql'] ?? ''));

        if ($sql === '') {
            return $this->error('sql is required');
        }

        $error = $this->validate_select_query($sql);
        if ($error !== null) {
            return $this->error($error, 403);
        }

        global $wpdb;

        $explain = (array) $wpdb->get_results('EXPLAIN ' . $wpdb->remove_placeholder_escape($sql), ARRAY_A);

        if ($wpdb->last_error) {
            return $this->error('EXPLAIN failed: ' . $wpdb->last_error, 500);
        }

        $warnings = [];
        foreach ($explain as $row) {
            if (!empty($row['type']) && in_array($row['type'], ['ALL', 'index'], true)) {
                $warnings[] = "Full table scan on table '{$row['table']}' (type: {$row['type']}) – consider adding an index";
            }
        }

        $this->log('db_explain', 'database', 0, ['hash' => hash('sha256', $sql)], 2);

        return $this->success([
            'explain'  => $explain,
            'warnings' => $warnings,
        ]);
    }

    // -------------------------------------------------------------------------
    // Optimize / repair / check
    // -------------------------------------------------------------------------

    public function optimize(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        $d      = (array) $r->get_json_params();
        $tables = $this->resolve_table_list($d);

        $results = [];
        foreach ($tables as $table) {
            $res       = $wpdb->get_results("OPTIMIZE TABLE `{$table}`", ARRAY_A);
            $results[] = ['table' => $table, 'result' => $res[0]['Msg_text'] ?? 'ok'];
        }

        // Clean stale transients
        $cleaned = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
        );

        $this->log('db_optimize', 'database', 0, ['tables' => count($tables), 'cleaned_transients' => $cleaned], 2);

        return $this->success([
            'tables'              => $results,
            'cleaned_transients'  => $cleaned,
        ]);
    }

    public function repair(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        $d      = (array) $r->get_json_params();
        $tables = $this->resolve_table_list($d);

        $results = [];
        foreach ($tables as $table) {
            $res       = $wpdb->get_results("REPAIR TABLE `{$table}`", ARRAY_A);
            $results[] = ['table' => $table, 'result' => $res[0]['Msg_text'] ?? 'ok'];
        }

        $this->log('db_repair', 'database', 0, ['tables' => count($tables)], 2);

        return $this->success(['tables' => $results]);
    }

    public function check(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        $d      = (array) $r->get_json_params();
        $tables = $this->resolve_table_list($d);

        $results  = [];
        $problems = [];

        foreach ($tables as $table) {
            $rows = (array) $wpdb->get_results("CHECK TABLE `{$table}`", ARRAY_A);
            foreach ($rows as $row) {
                $entry = ['table' => $table, 'op' => $row['Op'] ?? '', 'msg_type' => $row['Msg_type'] ?? '', 'result' => $row['Msg_text'] ?? ''];
                $results[] = $entry;
                if (($row['Msg_type'] ?? '') === 'error') {
                    $problems[] = $table;
                }
            }
        }

        $this->log('db_check', 'database', 0, ['tables' => count($tables), 'problems' => count($problems)], 2);

        return $this->success([
            'results'  => $results,
            'problems' => array_unique($problems),
        ]);
    }

    // -------------------------------------------------------------------------
    // Slow-query candidates (large tables with no indexes)
    // -------------------------------------------------------------------------

    public function slow_queries(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        // Tables with many rows but no non-primary indexes
        $rows = (array) $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);

        $candidates = [];
        foreach ($rows as $row) {
            $count   = (int) $row['Rows'];
            $indexes = (array) $wpdb->get_results("SHOW INDEX FROM `{$row['Name']}`", ARRAY_A);
            $non_pk  = array_filter($indexes, fn($i) => ($i['Key_name'] ?? '') !== 'PRIMARY');

            if ($count > 10000 && count($non_pk) === 0) {
                $candidates[] = [
                    'table'      => $row['Name'],
                    'row_count'  => $count,
                    'warning'    => 'Large table with only PRIMARY index – full scans likely',
                ];
            }
        }

        return $this->success(['candidates' => $candidates]);
    }

    // -------------------------------------------------------------------------
    // Large autoloaded options
    // -------------------------------------------------------------------------

    public function large_autoloads(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        $limit = min((int) ($r['limit'] ?? 50), 200);
        $rows  = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY size DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        $items = array_map(fn(array $row): array => [
            'option'          => $row['option_name'],
            'size'            => (int) $row['size'],
            'size_formatted'  => size_format((int) $row['size']),
        ], $rows);

        $total = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        return $this->success([
            'items'           => $items,
            'total_size'      => $total,
            'total_formatted' => size_format($total),
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Validate a SQL string is a safe SELECT statement.
     *
     * @return string|null Error message, or null if the query is allowed.
     */
    private function validate_select_query(string $sql): ?string {
        // Strip comments
        $clean = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
        $clean = preg_replace('#/\*.*?\*/#s', '', $clean) ?? $clean;
        $clean = trim($clean);

        // Reject multiple statements
        if (str_contains($clean, ';')) {
            return 'Multiple statements (semicolons) are not allowed';
        }

        $upper = strtoupper($clean);

        if (!str_starts_with($upper, 'SELECT')) {
            return 'Only SELECT statements are permitted';
        }

        foreach (self::BLOCKED_KEYWORDS as $kw) {
            if (str_contains($upper, $kw)) {
                return "Blocked keyword detected: {$kw}";
            }
        }

        return null;
    }

    /** Resolve the list of tables for an operation. */
    private function resolve_table_list(array $d): array {
        global $wpdb;

        if (!empty($d['tables']) && is_array($d['tables'])) {
            return array_map('sanitize_key', $d['tables']);
        }

        return $wpdb->get_col('SHOW TABLES') ?: [];
    }
}
