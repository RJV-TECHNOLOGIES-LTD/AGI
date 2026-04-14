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

    // ── Brute-force lockout ───────────────────────────────────────────────────
    /** Maximum auth failures from a single IP within LOCKOUT_WINDOW_S before lockout. */
    private const LOCKOUT_THRESHOLD  = 10;
    /** Sliding window length in seconds for counting failures. */
    private const LOCKOUT_WINDOW_S   = 300;   // 5 minutes
    /** Lockout duration in seconds once threshold is exceeded. */
    private const LOCKOUT_DURATION_S = 900;   // 15 minutes

    // ── Replay protection ─────────────────────────────────────────────────────
    /** Maximum allowed clock skew (seconds) for X-RJV-Timestamp header. */
    private const REPLAY_MAX_AGE_S   = 300;   // 5 minutes
    /** Transient TTL for seen nonces – must be >= REPLAY_MAX_AGE_S. */
    private const NONCE_TTL_S        = 600;

    // ── Route-prefix → scopes that may access it ──────────────────────────────
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
        $ip       = self::client_ip();
        $provided = trim((string) $r->get_header('X-RJV-AGI-Key'));

        // ── 1. Brute-force lockout (checked before any key work) ──────────────
        if (self::is_locked_out($ip)) {
            self::log_failure('brute_force_lockout', $r, $required_tier, ['ip' => $ip]);
            return false;
        }

        if ($provided === '') {
            self::record_failed_attempt($ip);
            self::log_failure('missing_key', $r, $required_tier);
            return false;
        }

        // ── 2. Parse "key:scope" or "name/key:scope" formats ─────────────────
        $scope     = '';
        $check_key = $provided;

        if (str_contains($provided, ':')) {
            [$check_key, $scope] = explode(':', $provided, 2);
            $scope = sanitize_key($scope);
        }

        // ── 3. Validate against master key or named key registry ─────────────
        $named_meta = null;
        $stored     = (string) get_option('rjv_agi_api_key', '');

        if ($stored === '' || !hash_equals($stored, $check_key)) {
            // Try named key registry
            $named_meta = self::verify_named_key($check_key);
            if ($named_meta === null) {
                self::record_failed_attempt($ip);
                self::log_failure('invalid_key', $r, $required_tier);
                return false;
            }
        }

        // Named key: enforce its own tier and scope constraints
        if ($named_meta !== null) {
            // Key expiry
            if (!empty($named_meta['expires_at']) && strtotime((string) $named_meta['expires_at']) < time()) {
                self::record_failed_attempt($ip);
                self::log_failure('named_key_expired', $r, $required_tier, ['name' => $named_meta['name']]);
                return false;
            }
            // Tier enforcement: named key may not exceed its declared tier
            if ((int) ($named_meta['tier'] ?? 1) < $required_tier) {
                self::log_failure('named_key_insufficient_tier', $r, $required_tier,
                    ['name' => $named_meta['name'], 'key_tier' => $named_meta['tier']]);
                return false;
            }
            // Scope override from named key definition
            if ($scope === '' && !empty($named_meta['scope'])) {
                $scope = (string) $named_meta['scope'];
            }
        }

        // ── 4. Replay protection (optional; only enforced when headers present) ─
        $ts_header    = trim((string) $r->get_header('X-RJV-Timestamp'));
        $nonce_header = trim((string) $r->get_header('X-RJV-Nonce'));

        if (get_option('rjv_agi_replay_protection', '0') === '1' || ($ts_header !== '' && $nonce_header !== '')) {
            if (!self::check_replay($ts_header, $nonce_header, $r)) {
                self::record_failed_attempt($ip);
                return false; // log_failure called inside check_replay
            }
        }

        // ── 5. IP allowlist ───────────────────────────────────────────────────
        if (!self::check_ip_allowlist()) {
            self::record_failed_attempt($ip);
            self::log_failure('ip_denied', $r, $required_tier, ['ip' => $ip]);
            return false;
        }

        // ── 6. Per-key rate-limit ─────────────────────────────────────────────
        if (!self::check_rate_limit($check_key, $r)) {
            return false; // log_failure called inside check_rate_limit
        }

        // ── 7. Scope enforcement ──────────────────────────────────────────────
        if ($scope !== '' && !self::check_scope($r->get_route(), $scope)) {
            self::log_failure('scope_denied', $r, $required_tier, ['scope' => $scope]);
            return false;
        }

        // ── 8. Success: clear any accumulated failure count for this IP ───────
        self::clear_failed_attempts($ip);

        // Extra audit entry for every tier-3 request
        if ($required_tier >= 3) {
            AuditLog::log('tier3_access', 'auth', 0, [
                'route'     => $r->get_route(),
                'method'    => $r->get_method(),
                'ip'        => $ip,
                'scope'     => $scope ?: 'global',
                'key_type'  => $named_meta ? 'named' : 'master',
                'key_name'  => $named_meta['name'] ?? null,
            ], 3);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Brute-force lockout
    // -------------------------------------------------------------------------

    /** Return true if the IP has been temporarily locked out. */
    private static function is_locked_out(string $ip): bool {
        $key = 'rjv_bf_lock_' . substr(hash('sha256', $ip), 0, 16);
        return (bool) get_transient($key);
    }

    /** Record one failed attempt for an IP; locks the IP on threshold breach. */
    private static function record_failed_attempt(string $ip): void {
        $count_key = 'rjv_bf_cnt_' . substr(hash('sha256', $ip), 0, 16);
        $lock_key  = 'rjv_bf_lock_' . substr(hash('sha256', $ip), 0, 16);

        $count = (int) get_transient($count_key);
        $count++;

        // Keep the counter alive for the window
        set_transient($count_key, $count, self::LOCKOUT_WINDOW_S);

        if ($count >= self::LOCKOUT_THRESHOLD) {
            // Lock this IP for the lockout duration
            set_transient($lock_key, 1, self::LOCKOUT_DURATION_S);
            // Reset counter so lockout starts clean
            delete_transient($count_key);
            AuditLog::log('auth_brute_force_lockout', 'auth', 0, [
                'ip'              => $ip,
                'failure_count'   => $count,
                'lockout_seconds' => self::LOCKOUT_DURATION_S,
            ], 1, 'error');
        }
    }

    /** Clear the failure counter for an IP after a successful authentication. */
    private static function clear_failed_attempts(string $ip): void {
        $count_key = 'rjv_bf_cnt_' . substr(hash('sha256', $ip), 0, 16);
        delete_transient($count_key);
    }

    // -------------------------------------------------------------------------
    // Replay protection – X-RJV-Timestamp + X-RJV-Nonce
    // -------------------------------------------------------------------------

    private static function check_replay(string $ts, string $nonce, \WP_REST_Request $r): bool {
        // Both headers are required when replay protection is active
        if ($ts === '' || $nonce === '') {
            self::log_failure('replay_missing_headers', $r, 1);
            return false;
        }

        // Validate timestamp format and clock skew
        if (!ctype_digit($ts)) {
            self::log_failure('replay_invalid_timestamp', $r, 1, ['ts' => $ts]);
            return false;
        }

        $request_time = (int) $ts;
        $now          = time();

        if (abs($now - $request_time) > self::REPLAY_MAX_AGE_S) {
            self::log_failure('replay_timestamp_outside_window', $r, 1, [
                'ts'     => $ts,
                'skew_s' => $now - $request_time,
            ]);
            return false;
        }

        // Validate nonce – must be printable ASCII, 8–128 chars
        if (!preg_match('/^[\x21-\x7E]{8,128}$/', $nonce)) {
            self::log_failure('replay_invalid_nonce', $r, 1);
            return false;
        }

        // Reject replayed nonce
        $nonce_key = 'rjv_nonce_' . substr(hash('sha256', $nonce), 0, 24);
        if (get_transient($nonce_key) !== false) {
            self::log_failure('replay_duplicate_nonce', $r, 1, ['nonce_hash' => substr(hash('sha256', $nonce), 0, 8)]);
            return false;
        }

        // Mark nonce as consumed
        set_transient($nonce_key, 1, self::NONCE_TTL_S);
        return true;
    }

    // -------------------------------------------------------------------------
    // IP allowlist – IPv4 plain, IPv4 CIDR, IPv6 plain, IPv6 CIDR
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

    /**
     * Test whether $ip falls within a CIDR range.
     * Supports both IPv4 (e.g. 10.0.0.0/8) and IPv6 (e.g. 2001:db8::/32).
     */
    private static function ip_in_cidr(string $ip, string $cidr): bool {
        $parts  = explode('/', $cidr, 2);
        $prefix = isset($parts[1]) ? (int) $parts[1] : -1;

        $net = $parts[0];

        // ── IPv6 ──────────────────────────────────────────────────────────────
        if (str_contains($net, ':')) {
            if ($prefix < 0) {
                $prefix = 128;
            }
            $prefix = max(0, min(128, $prefix));

            $net_bin = inet_pton($net);
            $ip_bin  = inet_pton($ip);

            if ($net_bin === false || $ip_bin === false || strlen($ip_bin) !== 16) {
                return false;
            }

            return self::binary_cidr_match($ip_bin, $net_bin, $prefix);
        }

        // ── IPv4 ──────────────────────────────────────────────────────────────
        if ($prefix < 0) {
            $prefix = 32;
        }
        $prefix = max(0, min(32, $prefix));

        $net_long = ip2long($net);
        $ip_long  = ip2long($ip);

        if ($net_long === false || $ip_long === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        return ($ip_long & $mask) === ($net_long & $mask);
    }

    /** Bitwise CIDR match on raw binary strings (works for IPv4-mapped IPv6 too). */
    private static function binary_cidr_match(string $ip_bin, string $net_bin, int $prefix_bits): bool {
        $len = strlen($ip_bin);
        if (strlen($net_bin) !== $len) {
            return false;
        }

        $full_bytes   = intdiv($prefix_bits, 8);
        $partial_bits = $prefix_bits % 8;

        // Compare full bytes
        if (substr($ip_bin, 0, $full_bytes) !== substr($net_bin, 0, $full_bytes)) {
            return false;
        }

        // Compare the partial byte
        if ($partial_bits > 0 && $full_bytes < $len) {
            $mask = 0xFF << (8 - $partial_bits) & 0xFF;
            $ip_byte  = ord($ip_bin[$full_bytes]);
            $net_byte = ord($net_bin[$full_bytes]);
            if (($ip_byte & $mask) !== ($net_byte & $mask)) {
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Named API key registry
    // -------------------------------------------------------------------------

    /**
     * Verify $raw_key against the named-key registry.
     *
     * @return array|null  Named key metadata array or null if not found/invalid.
     */
    private static function verify_named_key(string $raw_key): ?array {
        $keys = self::load_named_keys();
        // Keys are stored as SHA-256 hashes to avoid plaintext storage
        $hash = hash('sha256', $raw_key);

        foreach ($keys as $entry) {
            if (!isset($entry['key_hash'])) {
                continue;
            }
            if (hash_equals((string) $entry['key_hash'], $hash)) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Issue a new named API key.
     *
     * @param string      $name      Human-readable label.
     * @param string      $scope     Scope string (content|admin|ai|monitor|woo|forms) or empty for global.
     * @param int         $tier      Max auth tier (1, 2, or 3).
     * @param int|null    $ttl       Validity in seconds from now; null = never expires.
     * @return array  ['key' => plain-text key, 'meta' => registry entry]  (key shown once only)
     */
    public static function issue_named_key(string $name, string $scope = '', int $tier = 1, ?int $ttl = null): array {
        $name  = sanitize_text_field($name);
        $scope = sanitize_key($scope);
        $tier  = max(1, min(3, $tier));

        // Generate a cryptographically random key
        $plain_key = bin2hex(random_bytes(32)); // 64-char hex = 256 bits

        $entry = [
            'id'         => wp_generate_uuid4(),
            'name'       => $name,
            'key_hash'   => hash('sha256', $plain_key),
            'scope'      => $scope,
            'tier'       => $tier,
            'created_at' => gmdate('c'),
            'expires_at' => $ttl !== null ? gmdate('c', time() + $ttl) : null,
            'last_used'  => null,
        ];

        $keys   = self::load_named_keys();
        $keys[] = $entry;
        update_option('rjv_agi_named_keys', $keys);

        AuditLog::log('named_key_issued', 'auth', 0, [
            'name'       => $name,
            'scope'      => $scope,
            'tier'       => $tier,
            'expires_at' => $entry['expires_at'],
        ], 3);

        return ['key' => $plain_key, 'meta' => $entry];
    }

    /**
     * Revoke a named key by its ID.
     */
    public static function revoke_named_key(string $id): bool {
        $keys    = self::load_named_keys();
        $before  = count($keys);
        $keys    = array_values(array_filter($keys, static fn ($k) => ($k['id'] ?? '') !== $id));

        if (count($keys) === $before) {
            return false;
        }

        update_option('rjv_agi_named_keys', $keys);
        AuditLog::log('named_key_revoked', 'auth', 0, ['id' => $id], 3);
        return true;
    }

    /**
     * List all named keys (hashes only – never exposes the plain-text key).
     */
    public static function list_named_keys(): array {
        return array_map(static function (array $k): array {
            unset($k['key_hash']); // never expose the hash used for comparison
            return $k;
        }, self::load_named_keys());
    }

    /** Load the named-key store from WP options. */
    private static function load_named_keys(): array {
        $v = get_option('rjv_agi_named_keys', []);
        return is_array($v) ? $v : [];
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
