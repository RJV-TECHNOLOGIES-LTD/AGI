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
        'ai_max_tokens'        => ['type' => 'int',     'default' => 4096,  'min' => 100,  'max' => 128000],
        'ai_temperature'       => ['type' => 'float',   'default' => 0.3,   'min' => 0.0,  'max' => 2.0],
        'ai_retry_attempts'    => ['type' => 'int',     'default' => 3,     'min' => 0,    'max' => 5],
        'ai_circuit_threshold' => ['type' => 'int',     'default' => 5,     'min' => 1,    'max' => 20],
        'ai_timeout_seconds'   => ['type' => 'int',     'default' => 120,   'min' => 10,   'max' => 600],
        'ai_monthly_token_budget' => ['type' => 'int',  'default' => 0,     'min' => 0],

        // Audit & observability
        'audit_enabled'        => ['type' => 'bool',    'default' => true],
        'log_retention_days'   => ['type' => 'int',     'default' => 90,   'min' => 1,    'max' => 3650],

        // Governance
        'program_scope_taxonomy' => ['type' => 'array', 'default' => []],
        'program_targets'        => ['type' => 'array', 'default' => []],
        'program_milestones'     => ['type' => 'array', 'default' => []],
        'policy_rules'           => ['type' => 'array', 'default' => []],
        'capability_overrides'   => ['type' => 'array', 'default' => []],
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
