<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Performance;

use RJV_AGI_Bridge\AuditLog;

/**
 * Performance Optimizer
 *
 * Continuously enforces best practices in caching, asset optimization,
 * script loading, and rendering efficiency. Detects performance degradation
 * and allows the AGI to act upon it.
 */
final class PerformanceOptimizer {
    private static ?self $instance = null;
    private array $metrics = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        // Register performance hooks
        add_action('wp_head', [$this, 'add_preload_hints'], 1);
        add_action('wp_footer', [$this, 'capture_metrics'], 9999);
        add_filter('script_loader_tag', [$this, 'optimize_scripts'], 10, 3);
        add_filter('style_loader_tag', [$this, 'optimize_styles'], 10, 4);
    }

    /**
     * Run full performance analysis
     */
    public function analyze(): array {
        $results = [
            'timestamp' => gmdate('c'),
            'database' => $this->analyze_database(),
            'caching' => $this->analyze_caching(),
            'assets' => $this->analyze_assets(),
            'content' => $this->analyze_content(),
            'server' => $this->analyze_server(),
        ];

        $results['score'] = $this->calculate_score($results);
        $results['recommendations'] = $this->generate_recommendations($results);

        AuditLog::log('performance_analysis', 'performance', 0, [
            'score' => $results['score'],
            'issues_count' => count($results['recommendations']),
        ], 1);

        return $results;
    }

    /**
     * Analyze database performance
     */
    private function analyze_database(): array {
        global $wpdb;

        $issues = [];
        $metrics = [];

        // Check for autoloaded options
        $autoload_size = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );
        $metrics['autoload_size_kb'] = round($autoload_size / 1024, 2);

        if ($autoload_size > 1000000) { // 1MB
            $issues[] = [
                'type' => 'large_autoload',
                'severity' => 'high',
                'message' => 'Autoloaded options exceed 1MB',
                'value' => $metrics['autoload_size_kb'] . 'KB',
            ];
        }

        // Check for transients
        $transient_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'"
        );
        $metrics['transient_count'] = $transient_count;

        if ($transient_count > 1000) {
            $issues[] = [
                'type' => 'many_transients',
                'severity' => 'medium',
                'message' => 'High number of transients',
                'value' => $transient_count,
            ];
        }

        // Check for post revisions
        $revision_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
        );
        $metrics['revision_count'] = $revision_count;

        if ($revision_count > 5000) {
            $issues[] = [
                'type' => 'many_revisions',
                'severity' => 'low',
                'message' => 'High number of post revisions',
                'value' => $revision_count,
            ];
        }

        // Check for orphaned meta
        $orphaned_postmeta = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE p.ID IS NULL"
        );
        $metrics['orphaned_postmeta'] = $orphaned_postmeta;

        if ($orphaned_postmeta > 1000) {
            $issues[] = [
                'type' => 'orphaned_meta',
                'severity' => 'low',
                'message' => 'Orphaned post meta entries',
                'value' => $orphaned_postmeta,
            ];
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }

    /**
     * Analyze caching configuration
     */
    private function analyze_caching(): array {
        $issues = [];
        $metrics = [];

        // Check for object cache
        $metrics['object_cache'] = wp_using_ext_object_cache();
        if (!$metrics['object_cache']) {
            $issues[] = [
                'type' => 'no_object_cache',
                'severity' => 'medium',
                'message' => 'No external object cache configured',
            ];
        }

        // Check for page caching
        $metrics['page_cache'] = defined('WP_CACHE') && WP_CACHE;
        if (!$metrics['page_cache']) {
            $issues[] = [
                'type' => 'no_page_cache',
                'severity' => 'medium',
                'message' => 'Page caching not enabled',
            ];
        }

        // Check browser caching headers
        $metrics['browser_cache_configured'] = $this->check_browser_caching();

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }

    /**
     * Check browser caching configuration
     */
    private function check_browser_caching(): bool {
        // Check .htaccess for cache headers
        $htaccess = ABSPATH . '.htaccess';
        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            return str_contains($content, 'ExpiresByType') || str_contains($content, 'Cache-Control');
        }
        return false;
    }

    /**
     * Analyze assets
     */
    private function analyze_assets(): array {
        global $wp_scripts, $wp_styles;

        $issues = [];
        $metrics = [];

        // Count registered scripts and styles
        $metrics['scripts_registered'] = $wp_scripts ? count($wp_scripts->registered) : 0;
        $metrics['styles_registered'] = $wp_styles ? count($wp_styles->registered) : 0;

        // Check for render-blocking scripts
        $render_blocking = 0;
        if ($wp_scripts) {
            foreach ($wp_scripts->queue as $handle) {
                $script = $wp_scripts->registered[$handle] ?? null;
                if ($script && empty($script->extra['defer']) && empty($script->extra['async'])) {
                    $render_blocking++;
                }
            }
        }
        $metrics['render_blocking_scripts'] = $render_blocking;

        if ($render_blocking > 5) {
            $issues[] = [
                'type' => 'render_blocking',
                'severity' => 'medium',
                'message' => 'Many render-blocking scripts',
                'value' => $render_blocking,
            ];
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }

    /**
     * Analyze content
     */
    private function analyze_content(): array {
        global $wpdb;

        $issues = [];
        $metrics = [];

        // Check for large posts
        $large_posts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type IN ('post', 'page') 
             AND post_status = 'publish' 
             AND LENGTH(post_content) > 100000"
        );
        $metrics['large_posts'] = $large_posts;

        if ($large_posts > 0) {
            $issues[] = [
                'type' => 'large_posts',
                'severity' => 'low',
                'message' => 'Some posts have very large content',
                'value' => $large_posts,
            ];
        }

        // Check for images without dimensions
        $posts_with_images = $wpdb->get_results(
            "SELECT ID, post_content FROM {$wpdb->posts} 
             WHERE post_status = 'publish' 
             AND post_content LIKE '%<img%' 
             LIMIT 100",
            ARRAY_A
        ) ?: [];

        $images_without_dimensions = 0;
        foreach ($posts_with_images as $post) {
            preg_match_all('/<img[^>]*>/i', $post['post_content'], $matches);
            foreach ($matches[0] as $img) {
                if (!preg_match('/width\s*=|height\s*=/i', $img)) {
                    $images_without_dimensions++;
                }
            }
        }
        $metrics['images_without_dimensions'] = $images_without_dimensions;

        if ($images_without_dimensions > 10) {
            $issues[] = [
                'type' => 'images_no_dimensions',
                'severity' => 'medium',
                'message' => 'Images missing width/height attributes',
                'value' => $images_without_dimensions,
            ];
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }

    /**
     * Analyze server configuration
     */
    private function analyze_server(): array {
        $issues = [];
        $metrics = [];

        // Check PHP memory limit
        $memory_limit = ini_get('memory_limit');
        $metrics['memory_limit'] = $memory_limit;
        $memory_bytes = wp_convert_hr_to_bytes($memory_limit);

        if ($memory_bytes < 256 * 1024 * 1024) {
            $issues[] = [
                'type' => 'low_memory',
                'severity' => 'medium',
                'message' => 'PHP memory limit is low',
                'value' => $memory_limit,
            ];
        }

        // Check max execution time
        $max_exec = (int) ini_get('max_execution_time');
        $metrics['max_execution_time'] = $max_exec;

        if ($max_exec > 0 && $max_exec < 30) {
            $issues[] = [
                'type' => 'low_timeout',
                'severity' => 'low',
                'message' => 'PHP max execution time is low',
                'value' => $max_exec,
            ];
        }

        // Check OPcache
        $metrics['opcache_enabled'] = function_exists('opcache_get_status') && opcache_get_status() !== false;

        if (!$metrics['opcache_enabled']) {
            $issues[] = [
                'type' => 'no_opcache',
                'severity' => 'medium',
                'message' => 'OPcache is not enabled',
            ];
        }

        // Check PHP version
        $metrics['php_version'] = PHP_VERSION;

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }

    /**
     * Calculate overall performance score
     */
    private function calculate_score(array $results): int {
        $score = 100;

        foreach (['database', 'caching', 'assets', 'content', 'server'] as $category) {
            foreach ($results[$category]['issues'] ?? [] as $issue) {
                $deduction = match ($issue['severity'] ?? 'low') {
                    'high' => 15,
                    'medium' => 10,
                    'low' => 5,
                    default => 5,
                };
                $score -= $deduction;
            }
        }

        return max(0, min(100, $score));
    }

    /**
     * Generate recommendations
     */
    private function generate_recommendations(array $results): array {
        $recommendations = [];

        // Database recommendations
        foreach ($results['database']['issues'] ?? [] as $issue) {
            switch ($issue['type']) {
                case 'large_autoload':
                    $recommendations[] = [
                        'category' => 'database',
                        'action' => 'Review autoloaded options and set unnecessary ones to not autoload',
                        'priority' => 'high',
                    ];
                    break;
                case 'many_transients':
                    $recommendations[] = [
                        'category' => 'database',
                        'action' => 'Clear expired transients and review transient usage',
                        'priority' => 'medium',
                    ];
                    break;
                case 'many_revisions':
                    $recommendations[] = [
                        'category' => 'database',
                        'action' => 'Limit post revisions with AUTOSAVE_INTERVAL or WP_POST_REVISIONS',
                        'priority' => 'low',
                    ];
                    break;
            }
        }

        // Caching recommendations
        if (!($results['caching']['metrics']['object_cache'] ?? false)) {
            $recommendations[] = [
                'category' => 'caching',
                'action' => 'Install Redis or Memcached for object caching',
                'priority' => 'high',
            ];
        }

        if (!($results['caching']['metrics']['page_cache'] ?? false)) {
            $recommendations[] = [
                'category' => 'caching',
                'action' => 'Enable page caching with a caching plugin',
                'priority' => 'high',
            ];
        }

        // Server recommendations
        if (!($results['server']['metrics']['opcache_enabled'] ?? false)) {
            $recommendations[] = [
                'category' => 'server',
                'action' => 'Enable PHP OPcache for better performance',
                'priority' => 'high',
            ];
        }

        return $recommendations;
    }

    /**
     * Optimize database
     */
    public function optimize_database(): array {
        global $wpdb;

        $optimized = [];

        // Clean expired transients
        $deleted_transients = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_timeout_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        $optimized['transients_cleaned'] = $deleted_transients;

        // Clean orphaned postmeta
        $deleted_meta = $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm 
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE p.ID IS NULL"
        );
        $optimized['orphaned_meta_cleaned'] = $deleted_meta;

        // Optimize tables - validate table names match expected WordPress pattern
        $tables = $wpdb->get_col("SHOW TABLES");
        $table_prefix = $wpdb->prefix;
        foreach ($tables as $table) {
            // Only optimize tables that start with our WordPress prefix
            // This prevents potential issues with foreign table names
            if (strpos($table, $table_prefix) === 0 && preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $wpdb->query($wpdb->prepare("OPTIMIZE TABLE %i", $table));
            }
        }
        $optimized['tables_optimized'] = count($tables);

        AuditLog::log('database_optimized', 'performance', 0, $optimized, 2);

        return $optimized;
    }

    /**
     * Add preload hints to head
     */
    public function add_preload_hints(): void {
        // Preconnect to common external domains
        $preconnects = [
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
        ];

        foreach ($preconnects as $url) {
            echo '<link rel="preconnect" href="' . esc_url($url) . '" crossorigin>' . "\n";
        }
    }

    /**
     * Capture performance metrics
     */
    public function capture_metrics(): void {
        if (is_admin()) {
            return;
        }

        global $wpdb;

        $this->metrics = [
            'queries' => $wpdb->num_queries,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'load_time_ms' => defined('WP_START_TIMESTAMP')
                ? (int) ((microtime(true) - WP_START_TIMESTAMP) * 1000)
                : null,
        ];

        // Store for analysis
        set_transient('rjv_agi_last_metrics', $this->metrics, HOUR_IN_SECONDS);
    }

    /**
     * Optimize script loading
     */
    public function optimize_scripts(string $tag, string $handle, string $src): string {
        // Add defer to non-critical scripts
        $defer_handles = ['comment-reply', 'wp-embed'];
        if (in_array($handle, $defer_handles, true)) {
            $tag = str_replace(' src', ' defer src', $tag);
        }

        return $tag;
    }

    /**
     * Optimize style loading
     */
    public function optimize_styles(string $tag, string $handle, string $href, string $media): string {
        // Add preload for critical styles
        $critical_handles = ['wp-block-library'];
        if (in_array($handle, $critical_handles, true)) {
            $preload = '<link rel="preload" href="' . esc_url($href) . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">';
            $noscript = '<noscript>' . $tag . '</noscript>';
            return $preload . $noscript;
        }

        return $tag;
    }

    /**
     * Get current metrics
     */
    public function get_metrics(): array {
        return $this->metrics;
    }

    /**
     * Get last captured metrics
     */
    public function get_last_metrics(): array {
        return get_transient('rjv_agi_last_metrics') ?: [];
    }
}
