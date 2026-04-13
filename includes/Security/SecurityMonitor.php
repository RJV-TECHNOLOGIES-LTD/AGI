<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Security;

use RJV_AGI_Bridge\AuditLog;

/**
 * Security Monitor
 *
 * Implements vulnerability scanning, file integrity monitoring,
 * access control enforcement, and anomaly detection.
 */
final class SecurityMonitor {
    private static ?self $instance = null;
    private array $baseline = [];
    private string $integrity_table;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->integrity_table = $wpdb->prefix . 'rjv_agi_file_integrity';
    }

    /**
     * Create integrity table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rjv_agi_file_integrity';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            file_path VARCHAR(500) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            last_modified DATETIME NOT NULL,
            status ENUM('baseline', 'verified', 'modified', 'new', 'deleted') NOT NULL DEFAULT 'baseline',
            checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_path (file_path(400))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Run full security scan
     */
    public function run_scan(): array {
        $results = [
            'timestamp' => gmdate('c'),
            'vulnerability_scan' => $this->scan_vulnerabilities(),
            'integrity_check' => $this->check_integrity(),
            'permission_audit' => $this->audit_permissions(),
            'anomaly_detection' => $this->detect_anomalies(),
        ];

        AuditLog::log('security_scan', 'security', 0, [
            'vulnerabilities_found' => count($results['vulnerability_scan']['issues'] ?? []),
            'integrity_changes' => count($results['integrity_check']['changes'] ?? []),
            'anomalies_detected' => count($results['anomaly_detection']['anomalies'] ?? []),
        ], 2);

        return $results;
    }

    /**
     * Scan for common vulnerabilities
     */
    public function scan_vulnerabilities(): array {
        $issues = [];

        // Check WordPress version
        global $wp_version;
        if (version_compare($wp_version, '6.4', '<')) {
            $issues[] = [
                'type' => 'outdated_wordpress',
                'severity' => 'high',
                'message' => "WordPress {$wp_version} is outdated. Update recommended.",
            ];
        }

        // Check PHP version
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            $issues[] = [
                'type' => 'outdated_php',
                'severity' => 'high',
                'message' => 'PHP version ' . PHP_VERSION . ' is outdated. Update to 8.1+ recommended.',
            ];
        }

        // Check for debug mode in production
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $issues[] = [
                'type' => 'debug_enabled',
                'severity' => 'medium',
                'message' => 'WP_DEBUG is enabled. Disable in production.',
            ];
        }

        // Check file permissions
        $upload_dir = wp_upload_dir();
        if (is_writable(ABSPATH . 'wp-config.php')) {
            $issues[] = [
                'type' => 'config_writable',
                'severity' => 'high',
                'message' => 'wp-config.php is writable. Set to read-only.',
            ];
        }

        // Check for default admin username
        if (get_user_by('login', 'admin')) {
            $issues[] = [
                'type' => 'default_admin',
                'severity' => 'medium',
                'message' => 'Default "admin" username exists. Consider renaming.',
            ];
        }

        // Check SSL
        if (!is_ssl()) {
            $issues[] = [
                'type' => 'no_ssl',
                'severity' => 'high',
                'message' => 'Site is not using HTTPS.',
            ];
        }

        // Check for plugins with known vulnerabilities
        $issues = array_merge($issues, $this->check_plugin_vulnerabilities());

        // Check for suspicious files
        $issues = array_merge($issues, $this->scan_for_malware());

        return [
            'scanned' => true,
            'issues' => $issues,
            'score' => $this->calculate_security_score($issues),
        ];
    }

    /**
     * Check plugin vulnerabilities
     */
    private function check_plugin_vulnerabilities(): array {
        $issues = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $active = get_option('active_plugins', []);

        foreach ($active as $plugin_file) {
            if (!isset($plugins[$plugin_file])) {
                continue;
            }

            $plugin = $plugins[$plugin_file];

            // Check for outdated plugins
            $update_plugins = get_site_transient('update_plugins');
            if (!empty($update_plugins->response[$plugin_file])) {
                $issues[] = [
                    'type' => 'outdated_plugin',
                    'severity' => 'medium',
                    'message' => "Plugin '{$plugin['Name']}' has an update available.",
                    'plugin' => $plugin_file,
                ];
            }
        }

        return $issues;
    }

    /**
     * Scan for malware signatures
     */
    private function scan_for_malware(): array {
        $issues = [];
        $suspicious_patterns = [
            'eval\s*\(\s*base64_decode' => 'Encoded eval',
            'eval\s*\(\s*gzinflate' => 'Compressed eval',
            '\$GLOBALS\s*\[\s*[\'"]_' => 'Suspicious globals',
            'assert\s*\(' => 'Assert statement',
            'create_function\s*\(' => 'Create function',
            'preg_replace\s*\([^,]*\/e' => 'Eval regex modifier',
        ];

        $scan_dirs = [
            ABSPATH . 'wp-includes/',
            ABSPATH . 'wp-admin/',
            get_template_directory() . '/',
        ];

        foreach ($scan_dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            $scanned = 0;
            foreach ($files as $file) {
                if ($scanned > 500) {
                    break; // Limit scan depth
                }

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    continue;
                }

                foreach ($suspicious_patterns as $pattern => $description) {
                    if (preg_match('/' . $pattern . '/i', $content)) {
                        $issues[] = [
                            'type' => 'suspicious_code',
                            'severity' => 'critical',
                            'message' => "{$description} found in file",
                            'file' => str_replace(ABSPATH, '', $file->getPathname()),
                        ];
                        break;
                    }
                }

                $scanned++;
            }
        }

        return $issues;
    }

    /**
     * Check file integrity
     */
    public function check_integrity(): array {
        global $wpdb;
        $changes = [];

        // Core WordPress files
        $core_files = [
            ABSPATH . 'wp-includes/version.php',
            ABSPATH . 'wp-includes/functions.php',
            ABSPATH . 'wp-admin/admin.php',
            ABSPATH . 'index.php',
            ABSPATH . 'wp-login.php',
        ];

        // Plugin files
        $plugin_files = [
            RJV_AGI_PLUGIN_DIR . 'rjv-agi-bridge.php',
            RJV_AGI_PLUGIN_DIR . 'includes/Plugin.php',
            RJV_AGI_PLUGIN_DIR . 'includes/Auth.php',
        ];

        $files_to_check = array_merge($core_files, $plugin_files);

        foreach ($files_to_check as $file_path) {
            if (!file_exists($file_path)) {
                $changes[] = [
                    'file' => str_replace(ABSPATH, '', $file_path),
                    'status' => 'missing',
                ];
                continue;
            }

            $hash = hash_file('sha256', $file_path);
            $size = filesize($file_path);
            $modified = filemtime($file_path);

            // Check against baseline
            $baseline = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->integrity_table} WHERE file_path = %s",
                $file_path
            ));

            if (!$baseline) {
                // First time seeing this file, establish baseline
                $wpdb->replace($this->integrity_table, [
                    'file_path' => $file_path,
                    'file_hash' => $hash,
                    'file_size' => $size,
                    'last_modified' => gmdate('Y-m-d H:i:s', $modified),
                    'status' => 'baseline',
                ]);
            } elseif ($baseline->file_hash !== $hash) {
                // File has been modified
                $changes[] = [
                    'file' => str_replace(ABSPATH, '', $file_path),
                    'status' => 'modified',
                    'previous_hash' => $baseline->file_hash,
                    'current_hash' => $hash,
                ];

                $wpdb->update($this->integrity_table, [
                    'file_hash' => $hash,
                    'file_size' => $size,
                    'last_modified' => gmdate('Y-m-d H:i:s', $modified),
                    'status' => 'modified',
                    'checked_at' => current_time('mysql', true),
                ], ['file_path' => $file_path]);

                AuditLog::log('file_integrity_change', 'security', 0, [
                    'file' => str_replace(ABSPATH, '', $file_path),
                ], 3, 'error');
            } else {
                // File verified
                $wpdb->update($this->integrity_table, [
                    'status' => 'verified',
                    'checked_at' => current_time('mysql', true),
                ], ['file_path' => $file_path]);
            }
        }

        return [
            'checked' => count($files_to_check),
            'changes' => $changes,
        ];
    }

    /**
     * Audit permissions
     */
    public function audit_permissions(): array {
        $issues = [];

        // Check user role capabilities
        $users = get_users(['role__in' => ['administrator', 'editor']]);

        foreach ($users as $user) {
            // Check for excessive permissions
            if (user_can($user, 'unfiltered_html') && !in_array('administrator', $user->roles, true)) {
                $issues[] = [
                    'type' => 'excessive_capability',
                    'user_id' => $user->ID,
                    'capability' => 'unfiltered_html',
                    'message' => "User {$user->user_login} has unfiltered_html capability",
                ];
            }
        }

        // Check for users with no recent login
        $inactive_threshold = strtotime('-90 days');
        foreach ($users as $user) {
            $last_login = get_user_meta($user->ID, 'last_login', true);
            if ($last_login && strtotime($last_login) < $inactive_threshold) {
                $issues[] = [
                    'type' => 'inactive_privileged_user',
                    'user_id' => $user->ID,
                    'message' => "User {$user->user_login} hasn't logged in for 90+ days",
                ];
            }
        }

        return [
            'users_audited' => count($users),
            'issues' => $issues,
        ];
    }

    /**
     * Detect anomalies in system behavior
     */
    public function detect_anomalies(): array {
        global $wpdb;
        $anomalies = [];

        $log_table = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $hour_ago = gmdate('Y-m-d H:i:s', strtotime('-1 hour'));

        // Check for unusual API activity
        $api_calls = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE timestamp >= %s",
            $hour_ago
        ));

        $hourly_avg = (int) $wpdb->get_var(
            "SELECT AVG(hourly_count) FROM (
                SELECT COUNT(*) as hourly_count FROM {$log_table} 
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY HOUR(timestamp)
            ) as hourly"
        );

        if ($hourly_avg > 0 && $api_calls > $hourly_avg * 3) {
            $anomalies[] = [
                'type' => 'unusual_api_volume',
                'severity' => 'medium',
                'message' => "API calls in last hour ({$api_calls}) significantly exceeds average ({$hourly_avg})",
            ];
        }

        // Check for auth failures
        $auth_failures = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE action = 'auth_ip_denied' AND timestamp >= %s",
            $hour_ago
        ));

        if ($auth_failures > 10) {
            $anomalies[] = [
                'type' => 'auth_failures',
                'severity' => 'high',
                'message' => "{$auth_failures} authentication failures in last hour",
            ];
        }

        // Check for tier 3 action spike
        $tier3_actions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE tier = 3 AND timestamp >= %s",
            $hour_ago
        ));

        if ($tier3_actions > 20) {
            $anomalies[] = [
                'type' => 'tier3_spike',
                'severity' => 'medium',
                'message' => "{$tier3_actions} tier 3 (destructive) actions in last hour",
            ];
        }

        // Check for errors
        $errors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE status = 'error' AND timestamp >= %s",
            $hour_ago
        ));

        if ($errors > 50) {
            $anomalies[] = [
                'type' => 'error_spike',
                'severity' => 'high',
                'message' => "{$errors} errors in last hour",
            ];
        }

        return [
            'anomalies' => $anomalies,
            'metrics' => [
                'api_calls_last_hour' => $api_calls,
                'auth_failures_last_hour' => $auth_failures,
                'tier3_actions_last_hour' => $tier3_actions,
                'errors_last_hour' => $errors,
            ],
        ];
    }

    /**
     * Calculate security score
     */
    private function calculate_security_score(array $issues): int {
        $score = 100;

        foreach ($issues as $issue) {
            $deduction = match ($issue['severity'] ?? 'low') {
                'critical' => 25,
                'high' => 15,
                'medium' => 10,
                'low' => 5,
                default => 5,
            };
            $score -= $deduction;
        }

        return max(0, $score);
    }

    /**
     * Get security status summary
     */
    public function get_status(): array {
        $last_scan = get_option('rjv_agi_last_security_scan', []);

        return [
            'last_scan' => $last_scan['timestamp'] ?? null,
            'score' => $last_scan['vulnerability_scan']['score'] ?? null,
            'issues_count' => count($last_scan['vulnerability_scan']['issues'] ?? []),
            'integrity_changes' => count($last_scan['integrity_check']['changes'] ?? []),
            'anomalies' => count($last_scan['anomaly_detection']['anomalies'] ?? []),
        ];
    }

    /**
     * Save scan results
     */
    public function save_scan(array $results): void {
        update_option('rjv_agi_last_security_scan', $results);
    }
}
