<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

/**
 * Settings – schema-validated, typed configuration store.
 *
 * All plugin options are namespaced under the `rjv_agi_` prefix.
 * Every key has a declared type and optional validator; writes are
 * rejected with a \ValueError when the value fails validation.
 */
final class Settings {

    private const SCHEMA = [
        // API access
        'api_key'              => ['type' => 'string',  'default' => ''],
        'allowed_ips'          => ['type' => 'string',  'default' => ''],
        'rate_limit'           => ['type' => 'int',     'default' => 600,  'min' => 0,    'max' => 10000],
        'replay_protection'    => ['type' => 'bool',    'default' => false],  // reject replayed requests via nonce window
        'named_keys'           => ['type' => 'array',   'default' => []],     // issued API credential records (name, tier, scope, expiry); managed via /auth/keys

        // AI providers
        'default_model'        => ['type' => 'enum',    'default' => 'anthropic', 'values' => ['openai', 'anthropic', 'google']],
        'openai_key'           => ['type' => 'string',  'default' => '', 'secret' => true],
        'openai_model'         => ['type' => 'string',  'default' => 'gpt-4.1-mini'],
        'openai_org'           => ['type' => 'string',  'default' => ''],
        'anthropic_key'        => ['type' => 'string',  'default' => '', 'secret' => true],
        'anthropic_model'      => ['type' => 'string',  'default' => 'claude-sonnet-4-20250514'],
        'google_key'           => ['type' => 'string',  'default' => '', 'secret' => true],
        'google_model'         => ['type' => 'string',  'default' => 'gemini-2.5-pro'],

        // AI behaviour
        'ai_max_tokens'           => ['type' => 'int',   'default' => 4096,  'min' => 100,  'max' => 128000],
        'ai_temperature'          => ['type' => 'float', 'default' => 0.3,   'min' => 0.0,  'max' => 2.0],
        'ai_retry_attempts'       => ['type' => 'int',   'default' => 3,     'min' => 0,    'max' => 5],
        'ai_circuit_threshold'    => ['type' => 'int',   'default' => 5,     'min' => 1,    'max' => 20],
        'ai_timeout_seconds'      => ['type' => 'int',   'default' => 120,   'min' => 10,   'max' => 600],
        'ai_monthly_token_budget' => ['type' => 'int',   'default' => 0,     'min' => 0],
        'ai_response_cache_ttl'   => ['type' => 'int',   'default' => 300,   'min' => 0,    'max' => 86400],

        // Audit & observability
        'audit_enabled'        => ['type' => 'bool',    'default' => true],
        'log_retention_days'   => ['type' => 'int',     'default' => 90,   'min' => 1,    'max' => 3650],

        // Reliability alerts
        'alert_availability_min'  => ['type' => 'float', 'default' => 99.0,  'min' => 0.0,  'max' => 100.0],
        'alert_latency_p95_max'   => ['type' => 'int',   'default' => 2000,  'min' => 0],
        'alert_burn_rate_1h_max'  => ['type' => 'float', 'default' => 14.4,  'min' => 0.0],
        'alert_email'             => ['type' => 'string', 'default' => ''],

        // Security / threat detection
        'threat_block_score'   => ['type' => 'int',  'default' => 70,       'min' => 0,  'max' => 500],
        'threat_ban_score'     => ['type' => 'int',  'default' => 120,      'min' => 0,  'max' => 1000],
        'threat_ban_ttl'       => ['type' => 'int',  'default' => 3600,     'min' => 60, 'max' => 86400],
        'threat_detector_mode' => ['type' => 'enum', 'default' => 'enforce', 'values' => ['enforce', 'monitor']],
        'security_scan_enabled'  => ['type' => 'bool', 'default' => true],

        // Platform connection
        'platform_url'         => ['type' => 'string', 'default' => 'https://platform.rjvtechnologies.com/api/v1'],
        'tenant_id'            => ['type' => 'string', 'default' => ''],
        'tenant_secret'        => ['type' => 'string', 'default' => '', 'secret' => true],

        // Feature flags
        'event_streaming'        => ['type' => 'bool', 'default' => false],
        'design_system_enabled'  => ['type' => 'bool', 'default' => false],
        'multi_tenant_enabled'   => ['type' => 'bool', 'default' => false],
        'performance_monitoring' => ['type' => 'bool', 'default' => true],

        // Cloudflare
        'cloudflare_token'      => ['type' => 'string', 'default' => '', 'secret' => true],
        'cloudflare_email'      => ['type' => 'string', 'default' => ''],
        'cloudflare_api_key'    => ['type' => 'string', 'default' => '', 'secret' => true],
        'cloudflare_account_id' => ['type' => 'string', 'default' => ''],

        // Cloudflare Tunnel
        'tunnel_enabled'      => ['type' => 'bool',   'default' => false],
        'tunnel_mode'         => ['type' => 'enum',   'default' => 'quick', 'values' => ['quick', 'named']],
        'tunnel_token'        => ['type' => 'string', 'default' => '', 'secret' => true],
        'tunnel_hostname'     => ['type' => 'string', 'default' => ''],
        'tunnel_original_url' => ['type' => 'string', 'default' => ''],
        'tunnel_local_port'   => ['type' => 'int',    'default' => 80,  'min' => 1, 'max' => 65535],
        'tunnel_auto_apply_url' => ['type' => 'bool', 'default' => false],

        // Google OAuth / analytics
        'google_client_id'      => ['type' => 'string', 'default' => ''],
        'google_client_secret'  => ['type' => 'string', 'default' => '', 'secret' => true],
        'google_redirect_uri'   => ['type' => 'string', 'default' => ''],
        'google_access_token'   => ['type' => 'string', 'default' => '', 'secret' => true],
        'google_refresh_token'  => ['type' => 'string', 'default' => '', 'secret' => true],
        'ga4_measurement_id'    => ['type' => 'string', 'default' => ''],
        'ga4_enabled'           => ['type' => 'bool',   'default' => false],
        'gtm_container_id'      => ['type' => 'string', 'default' => ''],
        'gtm_enabled'           => ['type' => 'bool',   'default' => false],
        'google_ads_id'         => ['type' => 'string', 'default' => ''],
        'google_ads_enabled'    => ['type' => 'bool',   'default' => false],
        'gsc_verification'      => ['type' => 'string', 'default' => ''],

        // Microsoft / Azure
        'microsoft_client_id'     => ['type' => 'string', 'default' => ''],
        'microsoft_client_secret' => ['type' => 'string', 'default' => '', 'secret' => true],
        'microsoft_redirect_uri'  => ['type' => 'string', 'default' => ''],
        'microsoft_access_token'  => ['type' => 'string', 'default' => '', 'secret' => true],
        'microsoft_refresh_token' => ['type' => 'string', 'default' => '', 'secret' => true],
        'clarity_project_id'      => ['type' => 'string', 'default' => ''],
        'clarity_api_key'         => ['type' => 'string', 'default' => '', 'secret' => true],
        'clarity_enabled'         => ['type' => 'bool',   'default' => false],
        'bing_uet_tag_id'         => ['type' => 'string', 'default' => ''],
        'bing_ads_enabled'        => ['type' => 'bool',   'default' => false],
        'bing_developer_token'    => ['type' => 'string', 'default' => '', 'secret' => true],
        'bing_customer_id'        => ['type' => 'string', 'default' => ''],
        'bing_account_id'         => ['type' => 'string', 'default' => ''],
        'bing_wmt_api_key'        => ['type' => 'string', 'default' => '', 'secret' => true],
        'bing_verification'       => ['type' => 'string', 'default' => ''],
        'appinsights_key'                => ['type' => 'string', 'default' => '', 'secret' => true],
        'appinsights_connection_string'  => ['type' => 'string', 'default' => '', 'secret' => true],
        'appinsights_enabled'            => ['type' => 'bool',   'default' => false],

        // Provisioning
        'provision_auto_start' => ['type' => 'bool',  'default' => false],
        'provision_services'   => ['type' => 'array', 'default' => []],

        // Governance
        'program_scope_taxonomy'   => ['type' => 'array', 'default' => []],
        'program_targets'          => ['type' => 'array', 'default' => []],
        'program_milestones'       => ['type' => 'array', 'default' => []],
        'policy_rules'             => ['type' => 'array', 'default' => []],
        'capability_overrides'     => ['type' => 'array', 'default' => []],
        'capability_plan_overrides' => ['type' => 'array', 'default' => []],
        'capability_tenant_overrides' => ['type' => 'array', 'default' => []],
        'api_contract'             => ['type' => 'array', 'default' => []],
        'api_deprecations'         => ['type' => 'array', 'default' => []],
        'upgrade_history'          => ['type' => 'array', 'default' => []],
        'upgrade_last'             => ['type' => 'array', 'default' => []],
        'upgrade_lock'             => ['type' => 'array', 'default' => []],
        'threat_model_controls'    => ['type' => 'array', 'default' => []],
        'compliance_controls'      => ['type' => 'array', 'default' => []],
        'secret_rotation_log'      => ['type' => 'array', 'default' => []],
        'release_gate_thresholds'  => ['type' => 'array', 'default' => []],
        'config_baseline'          => ['type' => 'array', 'default' => []],

        // Observability: per-gate CI/CD test scores (written by external pipelines, read by release-gates check)
        'gate_contract_tests'    => ['type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100],
        'gate_integration_tests' => ['type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100],
        'gate_e2e_tests'         => ['type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100],
        'gate_load_tests'        => ['type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100],
        'gate_chaos_tests'       => ['type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100],

        // Cloudflare / tunnel runtime state (written by integration layer)
        'cf_zone_id'       => ['type' => 'string', 'default' => ''],
        'tunnel_url'       => ['type' => 'string', 'default' => ''],

        // Provisioning runtime state
        'provision_summary' => ['type' => 'string', 'default' => ''],

        // Local LLM (Ollama)
        'local_llm_enabled'     => ['type' => 'bool',   'default' => false],
        'local_llm_endpoint'    => ['type' => 'string', 'default' => 'http://127.0.0.1:11434'],
        'local_llm_model'       => ['type' => 'string', 'default' => 'phi3:mini'],
        'local_llm_timeout'     => ['type' => 'int',    'default' => 60,   'min' => 5,   'max' => 300],
        'local_llm_max_tokens'  => ['type' => 'int',    'default' => 512,  'min' => 64,  'max' => 4096],
        'local_llm_temperature' => ['type' => 'float',  'default' => 0.0,  'min' => 0.0, 'max' => 1.0],

        // ── Runtime state (written internally; listed here for type-safety and discoverability) ──

        // AuditLog HMAC chain: fallback key when AUTH_KEY is absent (auto-generated)
        'chain_key_fallback'  => ['type' => 'string', 'default' => '', 'secret' => true],

        // TunnelManager: SHA-256 of the verified cloudflared binary
        'tunnel_binary_sha256' => ['type' => 'string', 'default' => ''],

        // AccessControl: daily AI call limit for the "limited" capability tier
        'limited_ai_daily'    => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 10000],   // max AI calls per day for the "limited" capability tier

        // AccessControl: custom WordPress role → capability tier mappings
        'custom_role_mappings' => ['type' => 'array', 'default' => []],

        // TenantIsolation: explicitly configured external tenants (non-multisite)
        'configured_tenants'  => ['type' => 'array', 'default' => []],

        // CapabilityGate: runtime snapshot of active agent records
        'active_agents'       => ['type' => 'array', 'default' => []],

        // CapabilityGate: runtime snapshot of active integration records
        'integrations'        => ['type' => 'array', 'default' => []],

        // DesignSystemController: persisted design token values
        'design_tokens'       => ['type' => 'array', 'default' => []],

        // SecurityMonitor: result payload of the most recent security scan
        'last_security_scan'  => ['type' => 'array', 'default' => []],

        // Tools API: async job status store
        'tools_jobs'          => ['type' => 'array', 'default' => []],
    ];

    // -------------------------------------------------------------------------
    // Public read/write API
    // -------------------------------------------------------------------------

    /** Return the value of a setting, cast to its declared type. */
    public static function get(string $key, mixed $default = null): mixed {
        $schema  = self::SCHEMA[$key] ?? null;
        $stored  = get_option('rjv_agi_' . $key, $schema ? $schema['default'] : $default);

        if ($schema === null) {
            return $stored ?? $default;
        }

        return self::cast($stored, $schema);
    }

    /**
     * Persist a setting after schema validation.
     *
     * @throws \ValueError when the value fails validation.
     */
    public static function set(string $key, mixed $value): bool {
        $schema = self::SCHEMA[$key] ?? null;

        if ($schema !== null) {
            $value = self::validate_and_cast($key, $value, $schema);
        }

        return update_option('rjv_agi_' . $key, $value);
    }

    /**
     * Return a sanitised snapshot of all settings (secrets are redacted).
     *
     * @return array<string, mixed>
     */
    public static function all(): array {
        $result = [];
        foreach (self::SCHEMA as $key => $schema) {
            $value = self::get($key);
            $result[$key] = ($schema['secret'] ?? false) ? (empty($value) ? '' : '***set***') : $value;
        }
        return $result;
    }

    /**
     * Return the full schema (without secret values).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array {
        $out = [];
        foreach (self::SCHEMA as $key => $def) {
            $entry = ['type' => $def['type'], 'default' => $def['default']];
            foreach (['min', 'max', 'values'] as $attr) {
                if (isset($def[$attr])) {
                    $entry[$attr] = $def[$attr];
                }
            }
            $entry['secret'] = $def['secret'] ?? false;
            $out[$key] = $entry;
        }
        return $out;
    }

    /**
     * Reset all settings to their schema defaults.
     */
    public static function reset_defaults(): void {
        foreach (self::SCHEMA as $key => $schema) {
            update_option('rjv_agi_' . $key, $schema['default']);
        }
    }

    /**
     * Bulk-update multiple settings from an associative array.
     *
     * Returns ['updated' => [...], 'errors' => [key => message]].
     */
    public static function bulk_set(array $values): array {
        $updated = [];
        $errors  = [];

        foreach ($values as $key => $value) {
            try {
                self::set((string) $key, $value);
                $updated[] = $key;
            } catch (\ValueError $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Typed convenience getters
    // -------------------------------------------------------------------------

    public static function get_string(string $key, string $default = ''): string {
        return (string) (self::get($key, $default) ?? $default);
    }

    public static function get_int(string $key, int $default = 0): int {
        return (int) (self::get($key, $default) ?? $default);
    }

    public static function get_float(string $key, float $default = 0.0): float {
        return (float) (self::get($key, $default) ?? $default);
    }

    public static function get_bool(string $key, bool $default = false): bool {
        $v = self::get($key, $default);
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function get_array(string $key, array $default = []): array {
        $v = self::get($key, $default);
        return is_array($v) ? $v : $default;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Cast a raw stored value to its declared type. */
    private static function cast(mixed $value, array $schema): mixed {
        return match ($schema['type']) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => is_array($value) ? $value : ($schema['default'] ?? []),
            default => (string) $value,  // 'string', 'enum'
        };
    }

    /**
     * Validate and cast a value against its schema definition.
     *
     * @throws \ValueError on validation failure.
     */
    private static function validate_and_cast(string $key, mixed $value, array $schema): mixed {
        $type = $schema['type'];

        switch ($type) {
            case 'int':
                $v = (int) $value;
                if (isset($schema['min']) && $v < $schema['min']) {
                    throw new \ValueError("Setting '{$key}' must be >= {$schema['min']}.");
                }
                if (isset($schema['max']) && $v > $schema['max']) {
                    throw new \ValueError("Setting '{$key}' must be <= {$schema['max']}.");
                }
                return $v;

            case 'float':
                $v = (float) $value;
                if (isset($schema['min']) && $v < (float) $schema['min']) {
                    throw new \ValueError("Setting '{$key}' must be >= {$schema['min']}.");
                }
                if (isset($schema['max']) && $v > (float) $schema['max']) {
                    throw new \ValueError("Setting '{$key}' must be <= {$schema['max']}.");
                }
                return $v;

            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'enum':
                $v = (string) $value;
                if (!in_array($v, $schema['values'] ?? [], true)) {
                    $allowed = implode(', ', $schema['values'] ?? []);
                    throw new \ValueError("Setting '{$key}' must be one of: {$allowed}.");
                }
                return $v;

            case 'array':
                if (!is_array($value)) {
                    throw new \ValueError("Setting '{$key}' must be an array.");
                }
                return $value;

            default: // 'string'
                return (string) $value;
        }
    }
}
