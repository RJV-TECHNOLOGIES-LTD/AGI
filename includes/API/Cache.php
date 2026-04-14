<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Cache Purge & Settings Integration
 *
 * Unified cache administration surface: purge (full / per-URL), settings
 * read/update for WP Rocket, LiteSpeed Cache, and W3 Total Cache, and
 * cache warm-up triggering. Auto-detects active plugins.
 */
class Cache extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/cache/purge', [
            ['methods' => 'POST', 'callback' => [$this, 'purge'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/cache/purge/url', [
            ['methods' => 'POST', 'callback' => [$this, 'purge_url'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/cache/status', [
            ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/cache/settings', [
            ['methods' => 'GET',       'callback' => [$this, 'get_settings'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_settings'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/cache/preload', [
            ['methods' => 'POST', 'callback' => [$this, 'trigger_preload'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/cache/exclusions', [
            ['methods' => 'GET',  'callback' => [$this, 'get_exclusions'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'add_exclusion'],     'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE', 'callback' => [$this, 'remove_exclusion'],'permission_callback' => [Auth::class, 'tier2']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Full cache purge
    // -------------------------------------------------------------------------

    /**
     * Purge all caches from every detected cache plugin.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public function purge(\WP_REST_Request $r): \WP_REST_Response {
        $results = [];

        // WP Rocket
        if ($this->is_wp_rocket_active()) {
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
                $results['wp_rocket'] = 'purged';
            } elseif (function_exists('run_rocket_bot')) {
                run_rocket_bot('after_update_option_wp_rocket');
                $results['wp_rocket'] = 'purged';
            } else {
                $results['wp_rocket'] = 'active_but_purge_unavailable';
            }
        }

        // W3 Total Cache
        if ($this->is_w3tc_active()) {
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
                $results['w3_total_cache'] = 'purged';
            } elseif (class_exists('\W3TC\Dispatcher')) {
                try {
                    $dispatcher = \W3TC\Dispatcher::component('CacheFlush');
                    $dispatcher->flush_all();
                    $results['w3_total_cache'] = 'purged';
                } catch (\Throwable $e) {
                    $results['w3_total_cache'] = 'error: ' . $e->getMessage();
                }
            } else {
                $results['w3_total_cache'] = 'active_but_purge_unavailable';
            }
        }

        // LiteSpeed Cache
        if ($this->is_lscache_active()) {
            if (class_exists('\LiteSpeed\Purge')) {
                \LiteSpeed\Purge::purge_all();
                $results['litespeed_cache'] = 'purged';
            } elseif (function_exists('litespeed_purge_all')) {
                litespeed_purge_all();
                $results['litespeed_cache'] = 'purged';
            } else {
                $results['litespeed_cache'] = 'active_but_purge_unavailable';
            }
        }

        // Autoptimize
        if ($this->is_autoptimize_active()) {
            if (class_exists('autoptimizeCache')) {
                \autoptimizeCache::clearall();
                $results['autoptimize'] = 'purged';
            } else {
                $results['autoptimize'] = 'active_but_purge_unavailable';
            }
        }

        // SG Optimizer (SiteGround)
        if ($this->is_sg_optimizer_active()) {
            if (function_exists('sg_cachepress_purge_cache')) {
                sg_cachepress_purge_cache();
                $results['sg_optimizer'] = 'purged';
            } else {
                $results['sg_optimizer'] = 'active_but_purge_unavailable';
            }
        }

        // WordPress core object cache
        wp_cache_flush();
        $results['wp_object_cache'] = 'flushed';

        $this->log('cache_purge_all', 'cache', 0, $results, 2);

        return $this->success([
            'purged'  => true,
            'results' => $results,
        ]);
    }

    // -------------------------------------------------------------------------
    // Single-URL cache purge
    // -------------------------------------------------------------------------

    public function purge_url(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d   = (array) $r->get_json_params();
        $url = esc_url_raw((string) ($d['url'] ?? ''));

        if (empty($url)) {
            return $this->error('url is required');
        }

        $results = [];

        // WP Rocket
        if ($this->is_wp_rocket_active()) {
            if (function_exists('rocket_clean_post_by_url')) {
                rocket_clean_post_by_url($url);
                $results['wp_rocket'] = 'purged';
            } elseif (function_exists('rocket_clean_domain')) {
                // No URL-level purge available; fall back to full flush
                rocket_clean_domain();
                $results['wp_rocket'] = 'full_purge_fallback';
            } else {
                $results['wp_rocket'] = 'active_but_purge_unavailable';
            }
        }

        // LiteSpeed Cache
        if ($this->is_lscache_active()) {
            if (class_exists('\LiteSpeed\Purge')) {
                \LiteSpeed\Purge::purge_url($url);
                $results['litespeed_cache'] = 'purged';
            } else {
                $results['litespeed_cache'] = 'active_but_purge_unavailable';
            }
        }

        // W3 Total Cache
        if ($this->is_w3tc_active() && function_exists('w3tc_flush_url')) {
            w3tc_flush_url($url);
            $results['w3_total_cache'] = 'purged';
        }

        // Best-effort flush of any object-cache entry keyed by URL hash.
        // This is a heuristic; the actual cache key varies by plugin.
        wp_cache_flush_runtime();
        $results['wp_object_cache'] = 'flushed';

        $this->log('cache_purge_url', 'cache', 0, array_merge(['url' => $url], $results), 2);

        return $this->success([
            'url'     => $url,
            'results' => $results,
        ]);
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    public function status(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success([
            'wp_rocket'      => $this->is_wp_rocket_active(),
            'w3_total_cache' => $this->is_w3tc_active(),
            'litespeed_cache'=> $this->is_lscache_active(),
            'autoptimize'    => $this->is_autoptimize_active(),
            'sg_optimizer'   => $this->is_sg_optimizer_active(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    private function is_wp_rocket_active(): bool {
        return defined('WP_ROCKET_VERSION') || function_exists('rocket_clean_domain');
    }

    private function is_w3tc_active(): bool {
        return class_exists('W3_Plugin') || function_exists('w3tc_flush_all') || class_exists('\W3TC\Dispatcher');
    }

    private function is_lscache_active(): bool {
        return defined('LSCWP_V') || class_exists('\LiteSpeed\Core') || class_exists('\LiteSpeed\Purge');
    }

    private function is_autoptimize_active(): bool {
        return class_exists('autoptimizeCache') || defined('AUTOPTIMIZE_PLUGIN_VERSION');
    }

    private function is_sg_optimizer_active(): bool {
        return function_exists('sg_cachepress_purge_cache') || defined('SG_OPTIMIZER_PLUGIN_BASENAME');
    }

    // =========================================================================
    // Settings read/update
    // =========================================================================

    public function get_settings(\WP_REST_Request $r): \WP_REST_Response {
        $settings = ['plugin' => null, 'settings' => []];

        if ($this->is_wp_rocket_active()) {
            $settings['plugin']   = 'wp_rocket';
            $settings['settings'] = $this->get_wp_rocket_settings();
        } elseif ($this->is_lscache_active()) {
            $settings['plugin']   = 'litespeed_cache';
            $settings['settings'] = $this->get_lscache_settings();
        } elseif ($this->is_w3tc_active()) {
            $settings['plugin']   = 'w3_total_cache';
            $settings['settings'] = $this->get_w3tc_settings();
        }

        return $this->success($settings);
    }

    public function update_settings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));

        if (($plugin === 'wp_rocket' || $plugin === '') && $this->is_wp_rocket_active()) {
            return $this->update_wp_rocket_settings($d);
        }

        if (($plugin === 'litespeed_cache' || $plugin === '') && $this->is_lscache_active()) {
            return $this->update_lscache_settings($d);
        }

        if (($plugin === 'w3_total_cache' || $plugin === '') && $this->is_w3tc_active()) {
            return $this->update_w3tc_settings($d);
        }

        return $this->error('No supported cache plugin is active', 503);
    }

    // ── WP Rocket settings ─────────────────────────────────────────────────────

    private function get_wp_rocket_settings(): array {
        $opt = get_option('wp_rocket_settings', []);
        return [
            'cache_mobile'           => (bool) ($opt['cache_mobile'] ?? false),
            'cache_logged_user'      => (bool) ($opt['cache_logged_user'] ?? false),
            'minify_css'             => (bool) ($opt['minify_css'] ?? false),
            'minify_js'              => (bool) ($opt['minify_js'] ?? false),
            'defer_all_js'           => (bool) ($opt['defer_all_js'] ?? false),
            'lazyload'               => (bool) ($opt['lazyload'] ?? false),
            'lazyload_iframes'       => (bool) ($opt['lazyload_iframes'] ?? false),
            'cdn'                    => (bool) ($opt['cdn'] ?? false),
            'cdn_cnames'             => $opt['cdn_cnames'] ?? [],
            'preload'                => (bool) ($opt['manual_preload'] ?? false),
            'preload_links'          => (bool) ($opt['link_prefetch'] ?? false),
            'cache_reject_uri'       => $opt['cache_reject_uri'] ?? [],
            'cache_reject_cookies'   => $opt['cache_reject_cookies'] ?? [],
            'cache_reject_ua'        => $opt['cache_reject_ua'] ?? [],
            'cache_purge_pages'      => $opt['cache_purge_pages'] ?? [],
            'cache_lifetime'         => (int) ($opt['purge_cron_interval'] ?? 10),
            'cache_lifetime_unit'    => $opt['purge_cron_unit'] ?? 'HOUR_IN_SECONDS',
        ];
    }

    private function update_wp_rocket_settings(array $d): \WP_REST_Response {
        $opt = get_option('wp_rocket_settings', []);
        $bool_keys = [
            'cache_mobile', 'cache_logged_user', 'minify_css', 'minify_js',
            'defer_all_js', 'lazyload', 'lazyload_iframes', 'cdn',
            'manual_preload', 'link_prefetch',
        ];
        $updated = [];

        foreach ($bool_keys as $key) {
            if (array_key_exists($key, $d)) {
                $opt[$key] = (int) (bool) $d[$key];
                $updated[] = $key;
            }
        }

        if (isset($d['cdn_cnames'])) {
            $opt['cdn_cnames'] = array_map('esc_url_raw', (array) $d['cdn_cnames']);
            $updated[] = 'cdn_cnames';
        }
        if (isset($d['cache_reject_uri'])) {
            $opt['cache_reject_uri'] = array_map('sanitize_text_field', (array) $d['cache_reject_uri']);
            $updated[] = 'cache_reject_uri';
        }

        update_option('wp_rocket_settings', $opt);

        // Purge after settings change so new rules take effect
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        $this->log('cache_update_settings', 'cache', 0, ['plugin' => 'wp_rocket', 'updated' => $updated], 2);
        return $this->success(['updated' => $updated, 'plugin' => 'wp_rocket']);
    }

    // ── LiteSpeed Cache settings ───────────────────────────────────────────────

    private function get_lscache_settings(): array {
        return [
            'cache_enabled'          => get_option('litespeed.conf.cache', false),
            'cache_mobile'           => get_option('litespeed.conf.cache-mobile', false),
            'cache_logged_in'        => get_option('litespeed.conf.cache-login_cookie', false),
            'js_min'                 => get_option('litespeed.conf.optm-js_min', false),
            'css_min'                => get_option('litespeed.conf.optm-css_min', false),
            'lazy_img'               => get_option('litespeed.conf.media-lazy', false),
            'lazyload_js'            => get_option('litespeed.conf.media-lazy_js', false),
            'cdn_enabled'            => get_option('litespeed.conf.cdn', false),
            'cdn_url'                => get_option('litespeed.conf.cdn-ori', ''),
            'ttl_pub'                => (int) get_option('litespeed.conf.cache-ttl_pub', 604800),
            'ttl_front'              => (int) get_option('litespeed.conf.cache-ttl_frontpage', 604800),
        ];
    }

    private function update_lscache_settings(array $d): \WP_REST_Response {
        $option_map = [
            'cache_enabled'    => 'litespeed.conf.cache',
            'cache_mobile'     => 'litespeed.conf.cache-mobile',
            'js_min'           => 'litespeed.conf.optm-js_min',
            'css_min'          => 'litespeed.conf.optm-css_min',
            'lazy_img'         => 'litespeed.conf.media-lazy',
            'lazyload_js'      => 'litespeed.conf.media-lazy_js',
            'cdn_enabled'      => 'litespeed.conf.cdn',
            'cdn_url'          => 'litespeed.conf.cdn-ori',
            'ttl_pub'          => 'litespeed.conf.cache-ttl_pub',
        ];

        $updated = [];
        foreach ($option_map as $k => $opt) {
            if (!array_key_exists($k, $d)) continue;
            $value = in_array($k, ['cdn_url'], true)
                ? esc_url_raw((string) $d[$k])
                : (in_array($k, ['ttl_pub'], true) ? (int) $d[$k] : (bool) $d[$k]);
            update_option($opt, $value);
            $updated[] = $k;
        }

        if ($this->is_lscache_active() && class_exists('\LiteSpeed\Purge')) {
            \LiteSpeed\Purge::purge_all();
        }

        $this->log('cache_update_settings', 'cache', 0, ['plugin' => 'litespeed_cache', 'updated' => $updated], 2);
        return $this->success(['updated' => $updated, 'plugin' => 'litespeed_cache']);
    }

    // ── W3 Total Cache settings ────────────────────────────────────────────────

    private function get_w3tc_settings(): array {
        $config = [];
        if (class_exists('\W3TC\Config')) {
            try {
                $c      = new \W3TC\Config();
                $config = [
                    'pgcache_enabled'    => (bool) $c->get_boolean('pgcache.enabled'),
                    'minify_enabled'     => (bool) $c->get_boolean('minify.enabled'),
                    'objectcache_enabled'=> (bool) $c->get_boolean('objectcache.enabled'),
                    'browsercache_enabled'=> (bool) $c->get_boolean('browsercache.enabled'),
                    'cdn_enabled'        => (bool) $c->get_boolean('cdn.enabled'),
                ];
            } catch (\Throwable $e) {
                $config = ['error' => $e->getMessage()];
            }
        }
        return $config;
    }

    private function update_w3tc_settings(array $d): \WP_REST_Response|\WP_Error {
        if (!class_exists('\W3TC\Config') || !class_exists('\W3TC\Dispatcher')) {
            return $this->error('W3 Total Cache Config API is unavailable', 503);
        }

        try {
            $c       = new \W3TC\Config();
            $updated = [];
            $bool_keys = ['pgcache.enabled', 'minify.enabled', 'objectcache.enabled', 'browsercache.enabled', 'cdn.enabled'];

            $key_map = [
                'pgcache_enabled'     => 'pgcache.enabled',
                'minify_enabled'      => 'minify.enabled',
                'objectcache_enabled' => 'objectcache.enabled',
                'cdn_enabled'         => 'cdn.enabled',
            ];

            foreach ($key_map as $input => $w3tc_key) {
                if (array_key_exists($input, $d)) {
                    $c->set($w3tc_key, (bool) $d[$input]);
                    $updated[] = $input;
                }
            }

            $c->save();
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
            }

            $this->log('cache_update_settings', 'cache', 0, ['plugin' => 'w3_total_cache', 'updated' => $updated], 2);
            return $this->success(['updated' => $updated, 'plugin' => 'w3_total_cache']);
        } catch (\Throwable $e) {
            return $this->error('W3TC update failed: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Cache warm-up / preload
    // =========================================================================

    public function trigger_preload(\WP_REST_Request $r): \WP_REST_Response {
        $results = [];

        // WP Rocket
        if ($this->is_wp_rocket_active() && function_exists('run_rocket_sitemap_preload')) {
            run_rocket_sitemap_preload();
            $results['wp_rocket'] = 'preload_triggered';
        } elseif ($this->is_wp_rocket_active() && function_exists('rocket_preload_cache_pending_jobs')) {
            rocket_preload_cache_pending_jobs();
            $results['wp_rocket'] = 'preload_triggered';
        } elseif ($this->is_wp_rocket_active()) {
            $results['wp_rocket'] = 'manual_preload_unavailable';
        }

        // LiteSpeed
        if ($this->is_lscache_active() && class_exists('\LiteSpeed\Crawler')) {
            try {
                do_action('litespeed_crawler_start');
                $results['litespeed_cache'] = 'crawler_triggered';
            } catch (\Throwable $e) {
                $results['litespeed_cache'] = 'error: ' . $e->getMessage();
            }
        }

        // Generic: schedule a background ping for each public post
        if (empty($results)) {
            $posts = get_posts(['posts_per_page' => 50, 'post_status' => 'publish', 'fields' => 'ids']);
            $queued = 0;
            foreach ($posts as $post_id) {
                $url = get_permalink($post_id);
                if ($url) {
                    wp_remote_get($url, ['timeout' => 1, 'blocking' => false, 'sslverify' => false]);
                    $queued++;
                }
            }
            $results['generic'] = "queued {$queued} non-blocking background requests";
        }

        $this->log('cache_preload', 'cache', 0, $results, 2);
        return $this->success(['results' => $results]);
    }

    // =========================================================================
    // Exclusion management
    // =========================================================================

    public function get_exclusions(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if ($this->is_wp_rocket_active()) {
            $opt = get_option('wp_rocket_settings', []);
            return $this->success([
                'plugin'           => 'wp_rocket',
                'reject_uri'       => $opt['cache_reject_uri'] ?? [],
                'reject_cookies'   => $opt['cache_reject_cookies'] ?? [],
                'reject_ua'        => $opt['cache_reject_ua'] ?? [],
                'purge_pages'      => $opt['cache_purge_pages'] ?? [],
            ]);
        }

        return $this->error('Exclusion management is currently supported for WP Rocket only', 503);
    }

    public function add_exclusion(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_wp_rocket_active()) {
            return $this->error('Exclusion management is currently supported for WP Rocket only', 503);
        }

        $d    = (array) $r->get_json_params();
        $type = sanitize_key((string) ($d['type'] ?? 'uri'));
        $value= sanitize_text_field((string) ($d['value'] ?? ''));

        if (empty($value)) return $this->error('value is required');

        $opt    = get_option('wp_rocket_settings', []);
        $key_map= ['uri' => 'cache_reject_uri', 'cookie' => 'cache_reject_cookies', 'ua' => 'cache_reject_ua', 'purge' => 'cache_purge_pages'];
        $opt_key= $key_map[$type] ?? 'cache_reject_uri';

        $opt[$opt_key]   = array_unique(array_merge($opt[$opt_key] ?? [], [$value]));
        update_option('wp_rocket_settings', $opt);

        $this->log('cache_add_exclusion', 'cache', 0, ['type' => $type, 'value' => $value], 2);
        return $this->success(['added' => true, 'type' => $type, 'value' => $value]);
    }

    public function remove_exclusion(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_wp_rocket_active()) {
            return $this->error('Exclusion management is currently supported for WP Rocket only', 503);
        }

        $d    = (array) $r->get_json_params();
        $type = sanitize_key((string) ($d['type'] ?? 'uri'));
        $value= sanitize_text_field((string) ($d['value'] ?? ''));

        $opt    = get_option('wp_rocket_settings', []);
        $key_map= ['uri' => 'cache_reject_uri', 'cookie' => 'cache_reject_cookies', 'ua' => 'cache_reject_ua', 'purge' => 'cache_purge_pages'];
        $opt_key= $key_map[$type] ?? 'cache_reject_uri';

        $opt[$opt_key] = array_values(array_filter($opt[$opt_key] ?? [], fn($v) => $v !== $value));
        update_option('wp_rocket_settings', $opt);

        $this->log('cache_remove_exclusion', 'cache', 0, ['type' => $type, 'value' => $value], 2);
        return $this->success(['removed' => true, 'type' => $type, 'value' => $value]);
    }
}
