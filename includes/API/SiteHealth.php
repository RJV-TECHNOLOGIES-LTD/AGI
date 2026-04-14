<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;
use RJV_AGI_Bridge\AuditLog;

/**
 * Site Health & Observability
 *
 * Comprehensive WordPress site health endpoint covering PHP runtime,
 * MySQL server, disk I/O, WordPress configuration, plugin/theme status,
 * and AGI-specific operational metrics.
 */
class SiteHealth extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/health', [
            ['methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/health/detailed', [
            ['methods' => 'GET', 'callback' => [$this, 'detailed'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/health/php', [
            ['methods' => 'GET', 'callback' => [$this, 'php_info'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/health/database', [
            ['methods' => 'GET', 'callback' => [$this, 'db_info'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/health/disk', [
            ['methods' => 'GET', 'callback' => [$this, 'disk_info'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/health/updates', [
            ['methods' => 'GET', 'callback' => [$this, 'available_updates'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/health/external', [
            ['methods' => 'POST', 'callback' => [$this, 'check_external'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/health/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'agi_stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/audit-log', [
            ['methods' => 'GET', 'callback' => [$this, 'audit'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/audit-log/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'audit_stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/audit-log/export', [
            ['methods' => 'POST', 'callback' => [$this, 'export_audit'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Quick health summary
    // -------------------------------------------------------------------------

    public function health(\WP_REST_Request $r): \WP_REST_Response {
        global $wp_version, $wpdb;

        $ai  = new Router();

        return $this->success([
            'status'        => 'healthy',
            'version'       => RJV_AGI_VERSION,
            'wordpress'     => $wp_version,
            'php'           => phpversion(),
            'mysql'         => $wpdb->db_version(),
            'memory_limit'  => ini_get('memory_limit'),
            'ssl'           => is_ssl(),
            'theme'         => get_stylesheet(),
            'active_plugins'=> count(get_option('active_plugins', [])),
            'ai'            => $ai->status(),
            'posts'         => (int) wp_count_posts()->publish,
            'pages'         => (int) wp_count_posts('page')->publish,
            'users'         => (int) count_users()['total_users'],
            'comments'      => (int) wp_count_comments()->approved,
            'timestamp'     => gmdate('c'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Detailed diagnostic
    // -------------------------------------------------------------------------

    public function detailed(\WP_REST_Request $r): \WP_REST_Response {
        $this->log('site_health_detailed', 'health', 0, []);

        return $this->success([
            'php'       => $this->check_php(),
            'database'  => $this->check_database(),
            'wordpress' => $this->check_wordpress(),
            'disk'      => $this->check_disk(),
            'security'  => $this->check_security_basics(),
            'timestamp' => gmdate('c'),
        ]);
    }

    // -------------------------------------------------------------------------
    // PHP runtime
    // -------------------------------------------------------------------------

    public function php_info(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success($this->check_php());
    }

    private function check_php(): array {
        $required_extensions = [
            'curl', 'json', 'mbstring', 'openssl', 'pdo', 'xml', 'zip', 'gd', 'imagick',
        ];

        $extensions = [];
        foreach ($required_extensions as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        $warnings = [];

        $mem = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        if ($mem < 128 * MB_IN_BYTES) {
            $warnings[] = 'memory_limit < 128M (recommended: 256M+)';
        }

        $exec_time = (int) ini_get('max_execution_time');
        if ($exec_time > 0 && $exec_time < 30) {
            $warnings[] = "max_execution_time={$exec_time}s (recommended: 60+)";
        }

        $upload = wp_convert_hr_to_bytes((string) ini_get('upload_max_filesize'));
        if ($upload < 32 * MB_IN_BYTES) {
            $warnings[] = 'upload_max_filesize < 32M';
        }

        return [
            'version'              => phpversion(),
            'major'                => PHP_MAJOR_VERSION,
            'minor'                => PHP_MINOR_VERSION,
            'sapi'                 => PHP_SAPI,
            'memory_limit'         => ini_get('memory_limit'),
            'memory_limit_bytes'   => $mem,
            'max_execution_time'   => (int) ini_get('max_execution_time'),
            'upload_max_filesize'  => ini_get('upload_max_filesize'),
            'post_max_size'        => ini_get('post_max_size'),
            'max_input_vars'       => (int) ini_get('max_input_vars'),
            'display_errors'       => (bool) ini_get('display_errors'),
            'opcache_enabled'      => (bool) ini_get('opcache.enable'),
            'extensions'           => $extensions,
            'warnings'             => $warnings,
        ];
    }

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    public function db_info(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success($this->check_database());
    }

    private function check_database(): array {
        global $wpdb;

        $tables     = (array) $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        $total_size = 0;

        foreach ($tables as $t) {
            $total_size += ((int) $t['Data_length'] + (int) $t['Index_length']);
        }

        // Check for large autoloaded options
        $autoload_size = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        // Stale transients
        $stale_transients = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
        );

        $warnings = [];
        if ($autoload_size > 500 * KB_IN_BYTES) {
            $warnings[] = 'Autoloaded options > 500 KB (current: ' . size_format($autoload_size) . ')';
        }
        if ($stale_transients > 500) {
            $warnings[] = "{$stale_transients} stale transients in the database";
        }

        return [
            'version'              => $wpdb->db_version(),
            'charset'              => $wpdb->charset,
            'collate'              => $wpdb->collate,
            'table_prefix'         => $wpdb->prefix,
            'table_count'          => count($tables),
            'total_size'           => $total_size,
            'total_size_formatted' => size_format($total_size),
            'autoload_size'        => $autoload_size,
            'autoload_size_formatted' => size_format($autoload_size),
            'stale_transients'     => $stale_transients,
            'warnings'             => $warnings,
        ];
    }

    // -------------------------------------------------------------------------
    // Disk
    // -------------------------------------------------------------------------

    public function disk_info(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success($this->check_disk());
    }

    private function check_disk(): array {
        $root    = ABSPATH;
        $total   = disk_total_space($root) ?: 0;
        $free    = disk_free_space($root) ?: 0;
        $used    = $total - $free;
        $pct     = $total > 0 ? round(($used / $total) * 100, 1) : 0;

        $uploads       = wp_upload_dir();
        $uploads_dir   = $uploads['basedir'] ?? '';
        $uploads_size  = $uploads_dir !== '' ? $this->dir_size($uploads_dir) : 0;

        $warnings = [];
        if ($pct > 85) {
            $warnings[] = "Disk usage at {$pct}% (free: " . size_format($free) . ')';
        }

        return [
            'total'               => $total,
            'free'                => $free,
            'used'                => $used,
            'used_pct'            => $pct,
            'total_formatted'     => size_format($total),
            'free_formatted'      => size_format($free),
            'used_formatted'      => size_format($used),
            'uploads_size'        => $uploads_size,
            'uploads_size_formatted' => size_format($uploads_size),
            'abspath'             => $root,
            'warnings'            => $warnings,
        ];
    }

    // -------------------------------------------------------------------------
    // WordPress configuration checks
    // -------------------------------------------------------------------------

    private function check_wordpress(): array {
        global $wp_version;

        $active_plugins = get_option('active_plugins', []);
        $updates        = get_site_transient('update_plugins');
        $plugin_updates = 0;
        if (is_object($updates) && !empty($updates->response)) {
            $plugin_updates = count($updates->response);
        }

        $theme_updates  = 0;
        $theme_trans    = get_site_transient('update_themes');
        if (is_object($theme_trans) && !empty($theme_trans->response)) {
            $theme_updates = count($theme_trans->response);
        }

        $core_update = false;
        $core_trans  = get_site_transient('update_core');
        if (is_object($core_trans) && !empty($core_trans->updates)) {
            foreach ($core_trans->updates as $u) {
                if (($u->response ?? '') === 'upgrade') {
                    $core_update = true;
                    break;
                }
            }
        }

        $warnings = [];
        if ($core_update) {
            $warnings[] = 'WordPress core update available';
        }
        if ($plugin_updates > 0) {
            $warnings[] = "{$plugin_updates} plugin update(s) available";
        }
        if ($theme_updates > 0) {
            $warnings[] = "{$theme_updates} theme update(s) available";
        }
        if (!is_ssl()) {
            $warnings[] = 'Site is not served over HTTPS';
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $warnings[] = 'WP_DEBUG is enabled';
        }

        return [
            'version'          => $wp_version,
            'multisite'        => is_multisite(),
            'debug'            => defined('WP_DEBUG') && WP_DEBUG,
            'ssl'              => is_ssl(),
            'active_plugins'   => count($active_plugins),
            'core_update'      => $core_update,
            'plugin_updates'   => $plugin_updates,
            'theme_updates'    => $theme_updates,
            'admin_email'      => get_option('admin_email'),
            'language'         => get_locale(),
            'timezone'         => get_option('timezone_string') ?: get_option('gmt_offset') . ' UTC',
            'permalink_structure' => get_option('permalink_structure'),
            'warnings'         => $warnings,
        ];
    }

    // -------------------------------------------------------------------------
    // Basic security checks
    // -------------------------------------------------------------------------

    private function check_security_basics(): array {
        $issues = [];

        // Default table prefix
        global $wpdb;
        if ($wpdb->prefix === 'wp_') {
            $issues[] = ['severity' => 'low', 'message' => 'Default table prefix "wp_" in use'];
        }

        // File editing disabled
        if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) {
            $issues[] = ['severity' => 'medium', 'message' => 'DISALLOW_FILE_EDIT is not set'];
        }

        // Login URL (basic check)
        if (file_exists(ABSPATH . 'wp-login.php')) {
            $issues[] = ['severity' => 'low', 'message' => 'Default wp-login.php URL is accessible'];
        }

        // XML-RPC
        if (defined('XMLRPC_REQUEST') || file_exists(ABSPATH . 'xmlrpc.php')) {
            $issues[] = ['severity' => 'low', 'message' => 'XML-RPC may be enabled; consider disabling if not needed'];
        }

        return [
            'score'        => max(0, 100 - count($issues) * 10),
            'issues'       => $issues,
            'issues_count' => count($issues),
        ];
    }

    // -------------------------------------------------------------------------
    // Available updates
    // -------------------------------------------------------------------------

    public function available_updates(\WP_REST_Request $r): \WP_REST_Response {
        wp_update_plugins();
        wp_update_themes();

        $plugin_trans  = get_site_transient('update_plugins');
        $theme_trans   = get_site_transient('update_themes');
        $core_trans    = get_site_transient('update_core');

        $plugins = [];
        if (is_object($plugin_trans) && !empty($plugin_trans->response)) {
            foreach ($plugin_trans->response as $slug => $data) {
                $plugins[] = [
                    'slug'        => $slug,
                    'new_version' => $data->new_version ?? '',
                    'name'        => $data->name ?? $slug,
                    'url'         => $data->url ?? '',
                ];
            }
        }

        $themes = [];
        if (is_object($theme_trans) && !empty($theme_trans->response)) {
            foreach ($theme_trans->response as $slug => $data) {
                $themes[] = [
                    'slug'        => $slug,
                    'new_version' => $data['new_version'] ?? '',
                ];
            }
        }

        $core = null;
        if (is_object($core_trans) && !empty($core_trans->updates)) {
            foreach ($core_trans->updates as $u) {
                if (($u->response ?? '') === 'upgrade') {
                    $core = ['version' => $u->version ?? ''];
                    break;
                }
            }
        }

        return $this->success([
            'core'    => $core,
            'plugins' => $plugins,
            'themes'  => $themes,
        ]);
    }

    // -------------------------------------------------------------------------
    // External HTTP check
    // -------------------------------------------------------------------------

    public function check_external(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $urls = array_map('esc_url_raw', array_slice((array) ($d['urls'] ?? []), 0, 10));

        $defaults = [
            'https://api.openai.com',
            'https://api.anthropic.com',
            'https://generativelanguage.googleapis.com',
        ];

        if (empty($urls)) {
            $urls = $defaults;
        }

        $results = [];
        foreach ($urls as $url) {
            $start    = microtime(true);
            $response = wp_remote_head($url, ['timeout' => 10, 'sslverify' => true]);
            $ms       = (int) ((microtime(true) - $start) * 1000);

            $results[] = [
                'url'        => $url,
                'reachable'  => !is_wp_error($response),
                'status'     => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
                'latency_ms' => $ms,
                'error'      => is_wp_error($response) ? $response->get_error_message() : null,
            ];
        }

        return $this->success($results);
    }

    // -------------------------------------------------------------------------
    // AGI operational stats
    // -------------------------------------------------------------------------

    public function agi_stats(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $today = gmdate('Y-m-d 00:00:00');

        return $this->success([
            'total_entries'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'today_entries'    => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s", $today)),
            'errors_today'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = 'error' AND timestamp >= %s", $today)),
            'ai_calls_today'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE action LIKE 'ai_%' AND timestamp >= %s", $today)),
            'tokens_today'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(tokens_used),0) FROM {$table} WHERE timestamp >= %s", $today)),
            'tokens_this_month'=> (int) get_option('rjv_agi_tokens_' . gmdate('Y_m'), 0),
        ]);
    }

    // -------------------------------------------------------------------------
    // Audit log proxy endpoints
    // -------------------------------------------------------------------------

    public function audit(\WP_REST_Request $r): \WP_REST_Response {
        $result = AuditLog::query([
            'action'        => $r['action']        ?? '',
            'action_like'   => $r['action_like']   ?? '',
            'agent_id'      => $r['agent_id']      ?? '',
            'tier'          => $r['tier']          ?? '',
            'status'        => $r['status']        ?? '',
            'resource_type' => $r['resource_type'] ?? '',
            'since'         => $r['since']         ?? '',
            'until'         => $r['until']         ?? '',
            'ip_address'    => $r['ip_address']    ?? '',
            'per_page'      => $r['per_page']      ?? 50,
            'page'          => $r['page']          ?? 1,
            'order'         => $r['order']         ?? 'DESC',
        ]);

        return $this->success($result);
    }

    public function audit_stats(\WP_REST_Request $r): \WP_REST_Response {
        $window = sanitize_key((string) ($r['window'] ?? '24h'));
        return $this->success(AuditLog::stats($window));
    }

    public function export_audit(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $result = AuditLog::export_csv([
            'since'  => $d['since']  ?? '',
            'until'  => $d['until']  ?? '',
            'action' => $d['action'] ?? '',
            'status' => $d['status'] ?? '',
        ]);

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Export failed', 500);
        }

        $this->log('export_audit_log', 'audit', 0, ['rows' => $result['rows'] ?? 0], 2);
        return $this->success($result);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Recursively calculate directory size in bytes. */
    private function dir_size(string $path): int {
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }
}
