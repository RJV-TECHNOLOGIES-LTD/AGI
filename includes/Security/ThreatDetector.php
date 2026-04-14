<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Security;

use RJV_AGI_Bridge\AuditLog;

/**
 * Real-Time Threat Detector
 *
 * Inspects every REST request before it reaches a controller and assigns a
 * composite threat score.  Requests that exceed the configured threshold are
 * blocked outright; persistent attackers are placed on a time-limited ban list.
 *
 * Detection surfaces:
 *   • SQL Injection  – parameterised-query-bypass patterns, stacked queries,
 *                      information_schema probes, time-based blind injections.
 *   • XSS            – script injection, event-handler attributes, data URIs,
 *                      DOM clobbering, javascript: protocol.
 *   • Path Traversal – ../ chains (raw and encoded), null-byte suffixes,
 *                      absolute path escapes.
 *   • Command Injection – shell meta characters in parameters.
 *   • Prompt Injection  – AI-route-specific role-hijacking, instruction-override
 *                         and jailbreak phrase detection.
 *   • Anomaly        – abnormal Content-Length, unusual HTTP method, repeated
 *                      high-entropy payloads.
 *
 * Configuration (all stored as WP options):
 *   rjv_agi_threat_block_score   – score threshold above which requests are blocked (default 70)
 *   rjv_agi_threat_ban_score     – score threshold for automatic IP ban (default 120)
 *   rjv_agi_threat_ban_ttl       – ban duration in seconds (default 3600)
 *   rjv_agi_threat_detector_mode – 'enforce' (default) | 'monitor'
 */
final class ThreatDetector {

    // ── Tunable thresholds (overridable via WP options) ──────────────────────
    private const DEFAULT_BLOCK_SCORE = 70;
    private const DEFAULT_BAN_SCORE   = 120;
    private const DEFAULT_BAN_TTL     = 3600;   // 1 hour

    // ── Individual signal scores ──────────────────────────────────────────────
    private const SCORE_SQL_INJECTION       = 40;
    private const SCORE_XSS                 = 35;
    private const SCORE_PATH_TRAVERSAL      = 50;
    private const SCORE_COMMAND_INJECTION    = 60;
    private const SCORE_PROMPT_INJECTION     = 30;
    private const SCORE_NULL_BYTE           = 45;
    private const SCORE_EXCESSIVE_ENCODING  = 20;
    private const SCORE_ANOMALOUS_METHOD    = 25;

    // ── Option / transient key prefixes ──────────────────────────────────────
    private const BAN_OPTION_PREFIX   = 'rjv_threat_ban_';
    private const STATS_OPTION        = 'rjv_agi_threat_stats';

    // ── SQL Injection signatures ──────────────────────────────────────────────
    private const SQLI_PATTERNS = [
        '/\bunion\b.{0,30}\bselect\b/i',
        '/\bselect\b.{0,60}\bfrom\b/i',
        '/\binsert\s+into\b/i',
        '/\bupdate\b.{0,60}\bset\b/i',
        '/\bdelete\s+from\b/i',
        '/\bdrop\s+(table|database|schema)\b/i',
        '/\bexec(?:ute)?\s*\(/i',
        '/\bxp_cmdshell\b/i',
        '/\binformation_schema\b/i',
        '/\bsleep\s*\(/i',
        '/\bbenchmark\s*\(/i',
        '/\bwaitfor\s+delay\b/i',
        '/\bload_file\s*\(/i',
        '/\binto\s+outfile\b/i',
        '/(\'|")\s*(?:or|and)\s*[\'"1-9]/i',    // ' OR 1
        '/;.{0,20}\b(?:drop|select|insert|update|delete|exec)/i', // stacked
    ];

    // ── XSS signatures ───────────────────────────────────────────────────────
    private const XSS_PATTERNS = [
        '/<script[\s>]/i',
        '/javascript\s*:/i',
        '/\bon\w+\s*=/i',         // onerror=, onload=, onclick= …
        '/expression\s*\(/i',
        '/vbscript\s*:/i',
        '/<\s*(?:iframe|object|embed|svg|img|input|body)\b.*?(?:src|href|action)\s*=\s*[\'"]?\s*(?:javascript|data|vbscript)/i',
        '/\bdata:\s*(?:text\/html|application\/javascript)/i',
        '/<\s*img[^>]+src\s*=\s*["\']?\s*(?:javascript|data)/i',
    ];

    // ── Path traversal signatures ─────────────────────────────────────────────
    private const PATH_TRAVERSAL_PATTERNS = [
        '/\.\.[\\/]/',                   // ../  ..\
        '/%2e%2e[\\/]|%2e%2e%2f/i',      // URL-encoded
        '/\x00/',                        // null byte
        '/(?:^|\/)(?:etc\/passwd|proc\/self|windows\/win\.ini)/i',
        '/\bphp:\/\/(?:input|filter|expect)\b/i',   // PHP wrappers
    ];

    // ── Command injection signatures ──────────────────────────────────────────
    private const CMD_INJECTION_PATTERNS = [
        '/[;&|`$]\s*(?:cat|ls|rm|chmod|wget|curl|bash|sh|python|perl|nc\s)/i',
        '/\$\(.*\)/',      // $( subshell )
        '/`[^`]+`/',       // backtick subshell
        '/\|\s*(?:bash|sh|cmd)\b/i',
        '/>\s*\/(?:dev|tmp|etc)/i',
    ];

    // ── Prompt injection signatures (AI routes only) ──────────────────────────
    private const PROMPT_INJECTION_PATTERNS = [
        '/ignore\s+(?:all\s+)?(?:previous|above)\s+instructions?/i',
        '/you\s+are\s+now\s+(?:a|an|the)\s+\w/i',
        '/disregard\s+(?:your\s+)?(?:previous|prior|earlier)\s+(?:instructions?|context)/i',
        '/forget\s+(?:everything|all)\s+(?:above|before|prior)/i',
        '/\bact\s+as\s+(?:if\s+(?:you\s+are|you\'?re)\s+)?(?:an?\s+)?\w+\s+with\s+no\s+restrictions/i',
        '/do\s+anything\s+now/i',         // DAN
        '/developer\s+mode\s+enabled/i',
        '/\bsystem\s+prompt\b.*?(?:reveal|show|print|output|repeat)/i',
        '/\bconfidential\b.*?(?:system|instructions?|prompt)/i',
        '/pretend\s+(?:you\s+(?:have\s+no|don\'?t\s+have)\s+(?:rules|restrictions|filters))/i',
    ];

    // ── AI route prefix ───────────────────────────────────────────────────────
    private const AI_ROUTE_PREFIX = '/rjv-agi/v1/ai/';

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Inspect a request and return a threat assessment.
     *
     * @return array{
     *   allowed: bool,
     *   score: int,
     *   flags: list<string>,
     *   mode: string
     * }
     */
    public static function inspect(\WP_REST_Request $r): array {
        $ip    = self::client_ip();
        $flags = [];
        $score = 0;

        // 1. IP ban list check (fast-path)
        if (self::is_banned($ip)) {
            AuditLog::log('threat_banned_ip', 'security', 0, ['ip' => $ip], 1, 'error');
            return ['allowed' => false, 'score' => 999, 'flags' => ['banned_ip'], 'mode' => self::mode()];
        }

        // 2. Aggregate all parameter values for pattern scanning
        $route  = $r->get_route();
        $method = strtoupper($r->get_method());
        $params = self::extract_values($r);

        // 3. Run pattern scans on every parameter value
        foreach ($params as $value) {
            $str = (string) $value;

            if (self::matches_any($str, self::SQLI_PATTERNS)) {
                $flags[] = 'sqli';
                $score  += self::SCORE_SQL_INJECTION;
            }
            if (self::matches_any($str, self::XSS_PATTERNS)) {
                $flags[] = 'xss';
                $score  += self::SCORE_XSS;
            }
            if (self::matches_any($str, self::PATH_TRAVERSAL_PATTERNS)) {
                $flags[] = 'path_traversal';
                $score  += self::SCORE_PATH_TRAVERSAL;
            }
            if (self::matches_any($str, self::CMD_INJECTION_PATTERNS)) {
                $flags[] = 'cmd_injection';
                $score  += self::SCORE_COMMAND_INJECTION;
            }
        }

        // 4. Prompt injection – only on AI routes
        if (str_starts_with($route, self::AI_ROUTE_PREFIX)) {
            $body_raw = $r->get_body();
            if (self::matches_any($body_raw, self::PROMPT_INJECTION_PATTERNS)) {
                $flags[] = 'prompt_injection';
                $score  += self::SCORE_PROMPT_INJECTION;
            }
        }

        // 5. Null byte in raw body
        if (str_contains($r->get_body(), "\x00")) {
            $flags[] = 'null_byte';
            $score  += self::SCORE_NULL_BYTE;
        }

        // 6. Excessive percent-encoding (>20% of URL is %XX sequences)
        $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $encoded = substr_count($uri, '%');
        if ($encoded > 0 && strlen($uri) > 0 && ($encoded * 3 / strlen($uri)) > 0.20) {
            $flags[] = 'excessive_encoding';
            $score  += self::SCORE_EXCESSIVE_ENCODING;
        }

        // 7. Anomalous HTTP method
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            $flags[] = 'anomalous_method';
            $score  += self::SCORE_ANOMALOUS_METHOD;
        }

        $flags = array_unique($flags);
        sort($flags);

        // 8. Decide outcome
        $block_threshold = (int) get_option('rjv_agi_threat_block_score', self::DEFAULT_BLOCK_SCORE);
        $ban_threshold   = (int) get_option('rjv_agi_threat_ban_score',   self::DEFAULT_BAN_SCORE);
        $ban_ttl         = (int) get_option('rjv_agi_threat_ban_ttl',     self::DEFAULT_BAN_TTL);

        if ($score >= $ban_threshold) {
            self::ban($ip, $ban_ttl);
            self::record_detection($ip, $score, $flags, $route);
            AuditLog::log('threat_auto_banned', 'security', 0, [
                'ip' => $ip, 'score' => $score, 'flags' => $flags, 'route' => $route,
            ], 2, 'error');
        }

        $mode    = self::mode();
        $allowed = ($score < $block_threshold) || ($mode === 'monitor');

        if (!$allowed || $score >= $block_threshold) {
            self::record_detection($ip, $score, $flags, $route);
            AuditLog::log('threat_detected', 'security', 0, [
                'ip' => $ip, 'score' => $score, 'flags' => $flags, 'route' => $route, 'blocked' => !$allowed,
            ], 1, $allowed ? 'warning' : 'error');
        }

        return ['allowed' => $allowed, 'score' => $score, 'flags' => $flags, 'mode' => $mode];
    }

    /**
     * Scrub a prompt string of known prompt-injection phrases.
     *
     * Returns the cleaned string and a list of removed phrases.
     */
    public static function scrub_prompt(string $prompt): array {
        $original = $prompt;
        $removed  = [];

        foreach (self::PROMPT_INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $prompt)) {
                $removed[] = $pattern;
                $prompt    = (string) preg_replace($pattern, '[REDACTED]', $prompt);
            }
        }

        return [
            'prompt'    => $prompt,
            'modified'  => $prompt !== $original,
            'patterns'  => $removed,
        ];
    }

    // ── Ban list management ───────────────────────────────────────────────────

    /** Check whether an IP is currently banned. */
    public static function is_banned(string $ip): bool {
        $key   = self::BAN_OPTION_PREFIX . self::ip_key($ip);
        $until = (int) (get_transient($key) ?: 0);
        return $until > time();
    }

    /** Ban an IP for $ttl seconds. */
    public static function ban(string $ip, int $ttl): void {
        $key = self::BAN_OPTION_PREFIX . self::ip_key($ip);
        set_transient($key, time() + $ttl, $ttl + 60);
    }

    /** Lift a ban on an IP. */
    public static function unban(string $ip): void {
        $key = self::BAN_OPTION_PREFIX . self::ip_key($ip);
        delete_transient($key);
    }

    /** Return the current list of banned IPs with expiry times (from recent detection log). */
    public static function banned_list(): array {
        $stats = get_option(self::STATS_OPTION, []);
        return is_array($stats) ? ($stats['recent_bans'] ?? []) : [];
    }

    // ── Detection statistics ──────────────────────────────────────────────────

    /** Return aggregate threat detection statistics. */
    public static function stats(): array {
        $stats = get_option(self::STATS_OPTION, []);
        return is_array($stats) ? $stats : [];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function mode(): string {
        $m = (string) get_option('rjv_agi_threat_detector_mode', 'enforce');
        return in_array($m, ['enforce', 'monitor'], true) ? $m : 'enforce';
    }

    /** Flatten all request parameter values (body + query + URL params) into a string array. */
    private static function extract_values(\WP_REST_Request $r): array {
        $values = [];
        // URL route parameters
        $params = $r->get_url_params();
        if (is_array($params)) {
            array_walk_recursive($params, static function ($v) use (&$values) { $values[] = (string) $v; });
        }
        // Query string parameters
        $query = $r->get_query_params();
        if (is_array($query)) {
            array_walk_recursive($query, static function ($v) use (&$values) { $values[] = (string) $v; });
        }
        // JSON body parameters
        $body = $r->get_body_params();
        if (is_array($body)) {
            array_walk_recursive($body, static function ($v) use (&$values) { $values[] = (string) $v; });
        }
        // Raw body (for JSON-encoded payloads without form encoding)
        $raw = $r->get_body();
        if ($raw !== '') {
            $values[] = $raw;
        }
        return $values;
    }

    /** Return true if $str matches any of the given regex patterns. */
    private static function matches_any(string $str, array $patterns): bool {
        // Decode common URL-encoding before matching
        $decoded = urldecode(urldecode($str)); // double-decode for double-encoded payloads
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $str) || @preg_match($pattern, $decoded)) {
                return true;
            }
        }
        return false;
    }

    /** 16-char safe key derived from an IP address. */
    private static function ip_key(string $ip): string {
        return substr(hash('sha256', $ip . 'rjv-threat'), 0, 16);
    }

    /** Get client IP from server globals. */
    private static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $raw = sanitize_text_field(wp_unslash((string) $_SERVER[$h]));
                $ip  = trim(explode(',', $raw)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /** Append a detection event to rolling statistics. */
    private static function record_detection(string $ip, int $score, array $flags, string $route): void {
        $stats = get_option(self::STATS_OPTION, []);
        if (!is_array($stats)) {
            $stats = [];
        }

        // Increment counters
        $stats['total_detections'] = (int) ($stats['total_detections'] ?? 0) + 1;
        foreach ($flags as $flag) {
            $stats['by_type'][$flag] = (int) ($stats['by_type'][$flag] ?? 0) + 1;
        }

        // Keep last 100 events
        $event = [
            'ts'    => gmdate('c'),
            'ip'    => $ip,
            'score' => $score,
            'flags' => $flags,
            'route' => $route,
        ];
        $stats['recent'] = array_slice(
            array_merge([$event], (array) ($stats['recent'] ?? [])),
            0,
            100
        );

        if ($score >= (int) get_option('rjv_agi_threat_ban_score', self::DEFAULT_BAN_SCORE)) {
            $ban = ['ts' => gmdate('c'), 'ip' => $ip, 'score' => $score,
                    'until' => gmdate('c', time() + (int) get_option('rjv_agi_threat_ban_ttl', self::DEFAULT_BAN_TTL))];
            $stats['recent_bans'] = array_slice(
                array_merge([$ban], (array) ($stats['recent_bans'] ?? [])),
                0,
                50
            );
        }

        update_option(self::STATS_OPTION, $stats, false);
    }
}
