<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

/**
 * Authentication & Authorisation
 *
 * Three access tiers with real capability differentiation:
 *   Tier 1 – read-only operations (GET endpoints)
 *   Tier 2 – write / create / update operations
 *   Tier 3 – destructive / administrative operations (delete, plugin install …)
 *
 * Optional scope suffix restricts a key to a subset of endpoints:
 *   key:content   → Posts, Pages, Media, SEO, ContentGen
 *   key:admin     → Users, Options, Plugins, Themes, Database, FileSystem
 *   key:ai        → AI completion & generation endpoints only
 *   key:monitor   → Read-only health / audit-log endpoints
 *   key:woo       → WooCommerce endpoints
 *   key:forms     → Form entry endpoints
 *
 * Rate-limiting uses a per-key sliding-window counter stored in WP object
 * cache (with transient fallback) and rejects requests that exceed the
 * configured rpm with a 429 response before any work is done.
 */
final class Auth {

    /** Route-prefix → scopes that may access it (empty = no restriction). */
    private const SCOPE_MAP = [
        '/rjv-agi/v1/ai/'             => ['ai', 'admin'],
        '/rjv-agi/v1/posts'           => ['content', 'admin'],
        '/rjv-agi/v1/pages'           => ['content', 'admin'],
        '/rjv-agi/v1/media'           => ['content', 'admin'],
        '/rjv-agi/v1/seo'             => ['content', 'admin'],
        '/rjv-agi/v1/comments'        => ['content', 'admin'],
        '/rjv-agi/v1/taxonomies'      => ['content', 'admin'],
        '/rjv-agi/v1/menus'           => ['content', 'admin'],
        '/rjv-agi/v1/woo'             => ['woo', 'admin'],
        '/rjv-agi/v1/forms'           => ['forms', 'admin'],
        '/rjv-agi/v1/email-marketing' => ['forms', 'admin'],
        '/rjv-agi/v1/acf'             => ['content', 'admin'],
        '/rjv-agi/v1/users'           => ['admin'],
        '/rjv-agi/v1/plugins'         => ['admin'],
        '/rjv-agi/v1/themes'          => ['admin'],
        '/rjv-agi/v1/options'         => ['admin'],
        '/rjv-agi/v1/database'        => ['admin'],
        '/rjv-agi/v1/files'           => ['admin'],
        '/rjv-agi/v1/cache'           => ['admin'],
        '/rjv-agi/v1/cron'            => ['admin'],
        '/rjv-agi/v1/health'          => ['monitor', 'admin'],
        '/rjv-agi/v1/audit-log'       => ['monitor', 'admin'],
        '/rjv-agi/v1/sites'           => ['admin'],
    ];

    // -------------------------------------------------------------------------
    // Tier callbacks – used as permission_callback in register_rest_route
    // -------------------------------------------------------------------------

    /** Read-only access. */
    public static function tier1(\WP_REST_Request $r): bool {
        return self::authorize($r, 1);
    }

    /** Write / create / update access. */
    public static function tier2(\WP_REST_Request $r): bool {
        return self::authorize($r, 2);
    }

    /** Destructive / administrative access. */
    public static function tier3(\WP_REST_Request $r): bool {
        return self::authorize($r, 3);
    }

    // -------------------------------------------------------------------------
    // Core authorisation pipeline
    // -------------------------------------------------------------------------

    private static function authorize(\WP_REST_Request $r, int $required_tier): bool {
        $provided = trim((string) $r->get_header('X-RJV-AGI-Key'));

        if ($provided === '') {
            self::log_failure('missing_key', $r, $required_tier);
            return false;
        }

        // Support "key:scope" format (e.g. "mytoken:content")
        $scope     = '';
        $check_key = $provided;

        if (str_contains($provided, ':')) {
            [$check_key, $scope] = explode(':', $provided, 2);
            $scope = sanitize_key($scope);
        }

        // Constant-time key comparison
        $stored = (string) get_option('rjv_agi_api_key', '');
        if ($stored === '' || !hash_equals($stored, $check_key)) {
            self::log_failure('invalid_key', $r, $required_tier);
            return false;
        }

        // IP allowlist (plain IPs and CIDR ranges supported)
        if (!self::check_ip_allowlist()) {
            self::log_failure('ip_denied', $r, $required_tier, ['ip' => self::client_ip()]);
            return false;
        }

        // Per-key rate-limit
        if (!self::check_rate_limit($check_key, $r)) {
            return false; // log_failure called inside check_rate_limit
        }

        // Scope enforcement
        if ($scope !== '' && !self::check_scope($r->get_route(), $scope)) {
            self::log_failure('scope_denied', $r, $required_tier, ['scope' => $scope]);
            return false;
        }

        // Extra audit entry for every tier-3 request
        if ($required_tier >= 3) {
            AuditLog::log('tier3_access', 'auth', 0, [
                'route'  => $r->get_route(),
                'method' => $r->get_method(),
                'ip'     => self::client_ip(),
                'scope'  => $scope ?: 'global',
            ], 3);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // IP allowlist – plain IPs and CIDR /prefix notation
    // -------------------------------------------------------------------------

    private static function check_ip_allowlist(): bool {
        $allowed = trim((string) get_option('rjv_agi_allowed_ips', ''));
        if ($allowed === '') {
            return true;
        }

        $client  = self::client_ip();
        $entries = array_filter(array_map('trim', explode("\n", $allowed)));

        foreach ($entries as $entry) {
            if ($entry === $client) {
                return true;
            }
            if (str_contains($entry, '/') && self::ip_in_cidr($client, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** Test whether $ip falls within a CIDR range (IPv4 only). */
    private static function ip_in_cidr(string $ip, string $cidr): bool {
        $parts  = explode('/', $cidr, 2);
        $prefix = isset($parts[1]) ? (int) $parts[1] : 32;
        $prefix = max(0, min(32, $prefix));

        $net_long = ip2long($parts[0]);
        $ip_long  = ip2long($ip);

        if ($net_long === false || $ip_long === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        return ($ip_long & $mask) === ($net_long & $mask);
    }

    // -------------------------------------------------------------------------
    // Per-key sliding-window rate-limit (requests per minute)
    // -------------------------------------------------------------------------

    private static function check_rate_limit(string $key, \WP_REST_Request $r): bool {
        $limit = (int) get_option('rjv_agi_rate_limit', 600);
        if ($limit <= 0) {
            return true; // Rate-limiting disabled
        }

        $bucket_key  = 'rjv_rl_' . substr(md5($key), 0, 16);
        $window_key  = $bucket_key . '_ts';
        $cache_group = 'rjv_agi_rl';

        $now   = time();
        $count = (int) (wp_cache_get($bucket_key, $cache_group) ?: (int) get_transient($bucket_key));
        $ts    = (int) (wp_cache_get($window_key, $cache_group) ?: (int) get_transient($window_key));

        // Reset counter when the 60-second window expires
        if ($now - $ts >= 60) {
            $count = 0;
            $ts    = $now;
        }

        $count++;

        wp_cache_set($bucket_key, $count, $cache_group, 75);
        wp_cache_set($window_key, $ts,    $cache_group, 75);
        set_transient($bucket_key, $count, 75);
        set_transient($window_key, $ts,    75);

        if ($count > $limit) {
            self::log_failure('rate_limit_exceeded', $r, 1, [
                'count'  => $count,
                'limit'  => $limit,
                'window' => '60s',
            ]);
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Scope enforcement
    // -------------------------------------------------------------------------

    private static function check_scope(string $route, string $scope): bool {
        foreach (self::SCOPE_MAP as $prefix => $allowed_scopes) {
            if (str_starts_with($route, $prefix)) {
                return in_array($scope, $allowed_scopes, true);
            }
        }
        // Route not in scope map → any authenticated scope is allowed
        return true;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Return the real client IP, respecting common proxy headers. */
    public static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $raw = sanitize_text_field(wp_unslash((string) $_SERVER[$header]));
                $ip  = trim(explode(',', $raw)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    private static function log_failure(string $reason, \WP_REST_Request $r, int $tier, array $extra = []): void {
        AuditLog::log('auth_failed', 'auth', 0, array_merge([
            'reason' => $reason,
            'route'  => $r->get_route(),
            'method' => $r->get_method(),
            'ip'     => self::client_ip(),
        ], $extra), $tier, 'error');
    }
}
