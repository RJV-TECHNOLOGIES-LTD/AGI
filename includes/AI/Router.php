<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;

use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Security\ThreatDetector;

/**
 * AI Router
 *
 * Enterprise-grade AI provider management:
 *   – Named provider registry (OpenAI, Anthropic, Google)
 *   – Per-provider circuit breakers (auto-opens after N consecutive failures)
 *   – Exponential-backoff retry for transient errors
 *   – Fallback provider chain (tries preferred → others in priority order)
 *   – Monthly token budget enforcement
 *   – Estimated cost tracking (per-model token pricing)
 */
final class Router {

    /** Response cache TTL in seconds (0 = disabled). */
    private const RESPONSE_CACHE_TTL = 300; // 5 minutes

    /** Transient prefix for response cache. */
    private const CACHE_PREFIX = 'rjv_ai_rc_';

    /** Token → estimated USD cost per 1,000 tokens (blended in/out). */
    private const COST_PER_1K = [
        'gpt-4.1'                      => 0.007,
        'gpt-4.1-mini'                 => 0.00035,
        'gpt-4o'                       => 0.005,
        'gpt-4o-mini'                  => 0.00015,
        'claude-opus-4-20250514'       => 0.0135,
        'claude-sonnet-4-20250514'     => 0.003,
        'claude-haiku-4-20250514'      => 0.0004,
        'gemini-2.5-pro'               => 0.00125,
        'gemini-2.0-flash'             => 0.000075,
    ];

    /** Transient/option key prefix for circuit-breaker state. */
    private const CB_PREFIX = 'rjv_agi_cb_';

    /** @var array<string, Provider> */
    private array $providers = [];

    /** Ordered list of provider names for fallback chain. */
    private array $priority = ['anthropic', 'openai', 'google'];

    public function __construct() {
        $this->providers['openai']    = new OpenAI();
        $this->providers['anthropic'] = new Anthropic();

        if (class_exists(Google::class)) {
            $this->providers['google'] = new Google();
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Get a named provider instance.
     *
     * @throws \InvalidArgumentException for unknown provider names.
     */
    public function get(string $name = ''): Provider {
        if ($name === '') {
            $name = Settings::get_string('default_model', 'anthropic');
        }
        if (!isset($this->providers[$name])) {
            throw new \InvalidArgumentException("Unknown AI provider: {$name}");
        }
        return $this->providers[$name];
    }

    /**
     * Send a completion request with automatic retry, circuit-breaker,
     * fallback chain, and token-budget enforcement.
     *
     * @param string $system   System prompt.
     * @param string $message  User message.
     * @param array  $opts     {
     *   @type string $provider       Preferred provider name.
     *   @type string $model          Override model.
     *   @type int    $max_tokens     Maximum completion tokens.
     *   @type float  $temperature    Sampling temperature.
     *   @type int    $timeout        HTTP timeout seconds.
     *   @type bool   $json_mode      Request JSON output (OpenAI).
     *   @type bool   $no_fallback    Disable fallback chain.
     * }
     * @return array{content: string, model: string, tokens: int, latency_ms: int, provider: string, cost_usd?: float, error?: string}
     */
    public function complete(string $system, string $message, array $opts = []): array {
        // ── 0. Prompt injection scrubbing ─────────────────────────────────────
        $sys_scrub = ThreatDetector::scrub_prompt($system);
        $msg_scrub = ThreatDetector::scrub_prompt($message);

        if ($sys_scrub['modified'] || $msg_scrub['modified']) {
            AuditLog::log('ai_prompt_injection_scrubbed', 'ai', 0, [
                'system_patterns' => $sys_scrub['patterns'],
                'msg_patterns'    => $msg_scrub['patterns'],
            ], 1, 'warning');
        }

        $system  = $sys_scrub['prompt'];
        $message = $msg_scrub['prompt'];

        // ── 1. Monthly token budget check ─────────────────────────────────────
        if (!$this->within_token_budget()) {
            AuditLog::log('ai_budget_exceeded', 'ai', 0, [], 1, 'error');
            return ['error' => 'Monthly AI token budget exceeded', 'content' => ''];
        }

        // ── 2. Response deduplication cache ──────────────────────────────────
        $cache_ttl = (int) get_option('rjv_agi_ai_response_cache_ttl', self::RESPONSE_CACHE_TTL);
        $cache_key = '';
        if ($cache_ttl > 0 && empty($opts['no_cache'])) {
            $cache_key = self::CACHE_PREFIX . substr(
                hash('sha256', $system . '||' . $message . '||' . wp_json_encode($opts)),
                0,
                32
            );
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $preferred = sanitize_key((string) ($opts['provider'] ?? ''));
        if ($preferred === '') {
            $preferred = Settings::get_string('default_model', 'anthropic');
        }

        // Build provider attempt order: preferred first, then others
        $chain = [$preferred];
        if (empty($opts['no_fallback'])) {
            foreach ($this->priority as $name) {
                if ($name !== $preferred) {
                    $chain[] = $name;
                }
            }
        }

        $last_error = 'No configured AI provider available';

        foreach ($chain as $provider_name) {
            if (!isset($this->providers[$provider_name])) {
                continue;
            }

            $provider = $this->providers[$provider_name];

            if (!$provider->is_configured()) {
                continue;
            }

            if ($this->circuit_is_open($provider_name)) {
                continue;
            }

            $result = $this->attempt_with_retry($provider, $system, $message, $opts);

            if (empty($result['error'])) {
                $this->circuit_record_success($provider_name);
                $result['cost_usd'] = $this->estimate_cost($result['model'] ?? '', (int) ($result['tokens'] ?? 0));
                $this->record_token_usage((int) ($result['tokens'] ?? 0));

                // Store in response cache
                if ($cache_key !== '') {
                    set_transient($cache_key, $result, $cache_ttl);
                }

                return $result;
            }

            $last_error = $result['error'];
            $this->circuit_record_failure($provider_name);
        }

        return ['error' => $last_error, 'content' => ''];
    }

    /**
     * Return the configured state of all registered providers.
     *
     * @return array<string, array{configured: bool, model: string, circuit: string, failures: int}>
     */
    public function status(): array {
        $result = [];
        foreach ($this->providers as $name => $provider) {
            $result[$name] = [
                'configured'   => $provider->is_configured(),
                'model'        => $provider->get_model(),
                'circuit'      => $this->circuit_is_open($name) ? 'open' : 'closed',
                'failure_count'=> $this->circuit_failure_count($name),
            ];
        }
        return $result;
    }

    /**
     * Manually reset the circuit breaker for a provider.
     */
    public function circuit_reset(string $provider_name): void {
        delete_transient(self::CB_PREFIX . $provider_name . '_failures');
        delete_transient(self::CB_PREFIX . $provider_name . '_open_until');
    }

    // -------------------------------------------------------------------------
    // Retry logic
    // -------------------------------------------------------------------------

    private function attempt_with_retry(Provider $provider, string $system, string $message, array $opts): array {
        $max_attempts = Settings::get_int('ai_retry_attempts', 3);
        $attempt      = 0;
        $last         = ['error' => 'Unknown error', 'content' => ''];

        while ($attempt < max(1, $max_attempts)) {
            $result = $provider->complete($system, $message, $opts);

            if (empty($result['error'])) {
                return $result;
            }

            // Do not retry on non-transient errors
            if ($this->is_permanent_error($result['error'])) {
                return $result;
            }

            $last     = $result;
            $attempt++;

            if ($attempt < $max_attempts) {
                // Exponential backoff: 1s, 2s, 4s …
                $wait = (int) (1000000 * (2 ** ($attempt - 1)));
                usleep(min($wait, 8000000)); // cap at 8 seconds
            }
        }

        return $last;
    }

    private function is_permanent_error(string $error): bool {
        $permanent_patterns = [
            'invalid_api_key', 'authentication', 'not configured',
            'billing', 'permission', 'model not found',
        ];

        $lower = strtolower($error);
        foreach ($permanent_patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Circuit breaker
    // -------------------------------------------------------------------------

    private function circuit_is_open(string $name): bool {
        $open_until = (int) get_transient(self::CB_PREFIX . $name . '_open_until');
        return $open_until > time();
    }

    private function circuit_record_failure(string $name): void {
        $threshold = Settings::get_int('ai_circuit_threshold', 5);
        $failures  = $this->circuit_failure_count($name) + 1;

        set_transient(self::CB_PREFIX . $name . '_failures', $failures, HOUR_IN_SECONDS);

        if ($failures >= $threshold) {
            // Open the circuit for 5 minutes
            set_transient(self::CB_PREFIX . $name . '_open_until', time() + 300, 600);
            AuditLog::log('ai_circuit_opened', 'ai', 0, [
                'provider' => $name,
                'failures' => $failures,
            ], 1, 'warning');
        }
    }

    private function circuit_record_success(string $name): void {
        delete_transient(self::CB_PREFIX . $name . '_failures');
        delete_transient(self::CB_PREFIX . $name . '_open_until');
    }

    private function circuit_failure_count(string $name): int {
        return (int) (get_transient(self::CB_PREFIX . $name . '_failures') ?: 0);
    }

    // -------------------------------------------------------------------------
    // Token budget
    // -------------------------------------------------------------------------

    private function within_token_budget(): bool {
        $budget = Settings::get_int('ai_monthly_token_budget', 0);
        if ($budget <= 0) {
            return true; // No budget configured
        }

        $month_key = 'rjv_agi_tokens_' . gmdate('Y_m');
        $used      = (int) (get_option($month_key, 0));

        return $used < $budget;
    }

    private function record_token_usage(int $tokens): void {
        if ($tokens <= 0) {
            return;
        }
        $month_key = 'rjv_agi_tokens_' . gmdate('Y_m');
        $current   = (int) get_option($month_key, 0);
        update_option($month_key, $current + $tokens, false);
    }

    // -------------------------------------------------------------------------
    // Cost estimation
    // -------------------------------------------------------------------------

    private function estimate_cost(string $model, int $tokens): float {
        if ($tokens <= 0) {
            return 0.0;
        }
        $rate = self::COST_PER_1K[$model] ?? 0.004; // default blended rate
        return round(($tokens / 1000) * $rate, 6);
    }
}
