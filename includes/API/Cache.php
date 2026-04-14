<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Cache Purge Integration
 *
 * Provides a unified cache-purge surface for WP Rocket, W3 Total Cache,
 * LiteSpeed Cache, Autoptimize, and WordPress core's own object cache.
 * Auto-detects which cache plugin(s) are active so the AGI can invalidate
 * caches after content changes without any manual configuration.
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
}
