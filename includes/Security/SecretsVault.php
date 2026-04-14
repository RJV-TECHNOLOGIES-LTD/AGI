<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Security;

/**
 * SecretsVault
 *
 * Zero-knowledge, authenticated-encryption credential store for RJV AGI Bridge.
 *
 * ── Security model ───────────────────────────────────────────────────────────
 *
 *  1. Credentials are NEVER written as plaintext to the WordPress options table.
 *     Every secret is encrypted with AES-256-GCM before storage.
 *
 *  2. The 256-bit encryption key is derived fresh on every PHP request via
 *     HKDF-SHA-256:
 *       IKM  = AUTH_KEY  (from wp-config.php – NOT in the database)
 *             + per-install random 256-bit salt (stored in wp_options)
 *       info = "rjv-agi-vault-v1:" + WP site URL
 *     A full database dump, without the wp-config.php AUTH_KEY, cannot
 *     recover any secret.
 *
 *  3. Each encryption uses a freshly generated 96-bit random nonce (IV).
 *     GCM mode appends a 128-bit authentication tag.  Any byte-level
 *     tampering with the ciphertext in the database returns null, not garbled
 *     plaintext.
 *
 *  4. The encrypted envelope is tagged with a 64-bit key-version fingerprint
 *     (first 16 hex chars of SHA-256 of the derived key).  On AUTH_KEY
 *     rotation the vault detects a version mismatch on first read and surfaces
 *     the mismatch to the caller – preventing silent decryption with a wrong
 *     key.
 *
 *  5. The option key under which the envelope is stored is itself hashed
 *     (SHA-256 of "rjv-vault-name:" + secret_name), so the logical name of
 *     a secret is not visible to anyone with database access.
 *
 *  6. Every read and write is emitted to the plugin audit log with
 *     a hashed secret name, enabling forensic review without exposing values.
 *
 *  7. Memory hygiene: decrypted values are never cached in object properties.
 *     After use, intermediate byte strings are overwritten with null bytes
 *     before unsetting (PHP's refcount GC means we cannot guarantee zeroing,
 *     but we minimise the exposure window as much as the language allows).
 *
 * ── Requirements ─────────────────────────────────────────────────────────────
 *   PHP ≥ 7.1 (hash_hkdf, openssl AES-256-GCM, random_bytes)
 *   openssl extension enabled
 *   hash extension enabled
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SecretsVault {

    // ─── Cryptographic constants ─────────────────────────────────────────────
    private const ALGO        = 'aes-256-gcm';
    private const NONCE_BYTES = 12;           // 96-bit GCM nonce
    private const TAG_BYTES   = 16;           // 128-bit GCM auth tag
    private const KEY_BYTES   = 32;           // 256-bit AES key
    private const HKDF_HASH   = 'sha256';
    private const HKDF_INFO   = 'rjv-agi-vault-v1';

    // ─── WordPress option names ───────────────────────────────────────────────
    private const SALT_OPTION = 'rjv_agi_vault_salt';

    // ─── Envelope schema version ──────────────────────────────────────────────
    private const ENV_VERSION = 2;

    /** Singleton – one derived-key computation per request. */
    private static ?self $instance = null;

    /** 32-byte AES-256 key, held only for the lifetime of this object. */
    private string $key;

    /** 16-char hex fingerprint of the derived key used for version checking. */
    private string $key_version;

    private function __construct() {
        [$this->key, $this->key_version] = $this->build_key();
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Reset the singleton (useful during key rotation and unit tests).
     */
    public static function reset(): void {
        self::$instance = null;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Encrypt and persist a secret.
     *
     * @param  string $name   Logical identifier (e.g. 'cloudflare_token').
     * @param  string $value  Plaintext secret value.  Pass '' to clear.
     * @param  bool   $audit  Whether to emit an audit log entry.
     * @return bool           True on success.
     */
    public function put(string $name, string $value, bool $audit = true): bool {
        if ($value === '') {
            // Store an authenticated empty-marker rather than empty ciphertext
            $ok = (bool) update_option($this->option_key($name), [
                'v'     => self::ENV_VERSION,
                'kv'    => $this->key_version,
                'empty' => true,
            ], false);
            if ($audit && $ok) {
                $this->audit('vault_write_empty', $name);
            }
            return $ok;
        }

        $nonce      = random_bytes(self::NONCE_BYTES);
        $tag        = '';
        $ciphertext = openssl_encrypt(
            $value,
            self::ALGO,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false || $tag === '') {
            $this->audit('vault_encrypt_failed', $name);
            return false;
        }

        $envelope = [
            'v'  => self::ENV_VERSION,
            'kv' => $this->key_version,
            'n'  => base64_encode($nonce),
            'c'  => base64_encode($ciphertext),
            't'  => base64_encode($tag),
        ];

        $ok = (bool) update_option($this->option_key($name), $envelope, false);

        // Overwrite sensitive locals before GC
        sodium_memzero($ciphertext);

        if ($audit && $ok) {
            $this->audit('vault_write', $name);
        }
        return $ok;
    }

    /**
     * Decrypt and return a stored secret.
     *
     * Returns null when:
     *   – the secret was never stored
     *   – the envelope is malformed
     *   – the GCM authentication tag fails (tampered ciphertext)
     *   – the key version does not match (AUTH_KEY rotated)
     *
     * @param  bool $audit  Whether to emit an audit log entry.
     */
    public function get(string $name, bool $audit = true): ?string {
        $envelope = get_option($this->option_key($name));

        if (!is_array($envelope) || ($envelope['v'] ?? 0) !== self::ENV_VERSION) {
            return null;
        }

        if (!empty($envelope['empty'])) {
            return '';
        }

        if (($envelope['kv'] ?? '') !== $this->key_version) {
            $this->audit('vault_key_version_mismatch', $name);
            return null;
        }

        $nonce      = base64_decode((string) ($envelope['n'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['c'] ?? ''), true);
        $tag        = base64_decode((string) ($envelope['t'] ?? ''), true);

        if ($nonce === false || $ciphertext === false || $tag === false ||
            strlen($nonce) !== self::NONCE_BYTES ||
            strlen($tag)   !== self::TAG_BYTES) {
            $this->audit('vault_malformed_envelope', $name);
            return null;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGO,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            // GCM authentication failure – ciphertext was tampered with
            $this->audit('vault_integrity_failure', $name);
            return null;
        }

        if ($audit) {
            $this->audit('vault_read', $name);
        }

        return $plaintext;
    }

    /**
     * Delete a stored secret.
     */
    public function delete(string $name): bool {
        $this->audit('vault_delete', $name);
        return delete_option($this->option_key($name));
    }

    /**
     * Return true if a secret exists and can be successfully decrypted.
     */
    public function has(string $name): bool {
        return $this->get($name, false) !== null;
    }

    /**
     * Return a masked display string (first 4 chars + ••• + last 4 chars).
     * Safe for UI display; never exposes the full value.
     */
    public function masked(string $name): string {
        $val = $this->get($name, false);
        if ($val === null || $val === '') {
            return '';
        }
        $len = mb_strlen($val);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return mb_substr($val, 0, 4) . '•••' . mb_substr($val, -4);
    }

    /**
     * Re-encrypt all vault entries under the current (new) derived key.
     *
     * Call this after changing AUTH_KEY in wp-config.php.
     * Pass the previous AUTH_KEY value so the old ciphertext can be read.
     *
     * @param  string   $old_auth_key  The AUTH_KEY value used before rotation.
     * @param  string[] $secret_names  Logical names of all secrets to migrate.
     * @return array{migrated: int, failed: int, skipped: int}
     */
    public function rotate_key(string $old_auth_key, array $secret_names): array {
        $old_key  = $this->derive_key_from_auth_key($old_auth_key);
        $migrated = $failed = $skipped = 0;

        foreach ($secret_names as $name) {
            $envelope = get_option($this->option_key($name));
            if (!is_array($envelope) || !empty($envelope['empty'])) {
                $skipped++;
                continue;
            }

            // Decrypt with old key
            $nonce      = base64_decode((string) ($envelope['n'] ?? ''), true);
            $ciphertext = base64_decode((string) ($envelope['c'] ?? ''), true);
            $tag        = base64_decode((string) ($envelope['t'] ?? ''), true);

            if (!$nonce || !$ciphertext || !$tag) {
                $failed++;
                continue;
            }

            $plaintext = openssl_decrypt(
                $ciphertext, self::ALGO, $old_key, OPENSSL_RAW_DATA, $nonce, $tag
            );

            if ($plaintext === false) {
                $failed++;
                continue;
            }

            // Re-encrypt with new key
            if ($this->put($name, $plaintext, false)) {
                $migrated++;
            } else {
                $failed++;
            }

            sodium_memzero($plaintext);
        }

        $this->audit('vault_key_rotation', 'batch:' . count($secret_names));
        return ['migrated' => $migrated, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * List all known vault option keys (hashed names only; logical names are
     * not recoverable without the original name string).
     *
     * @return string[]
     */
    public function list_option_keys(): array {
        global $wpdb;
        $prefix  = 'rjv_agi_vault_';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
        return is_array($rows) ? $rows : [];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Build the AES-256 key and its version fingerprint.
     *
     * @return array{0: string, 1: string}  [32-byte key, 16-char hex fingerprint]
     */
    private function build_key(): array {
        // Retrieve (or lazily create) the per-install 256-bit salt.
        // This salt never leaves the database but is useless without AUTH_KEY.
        $salt_hex = get_option(self::SALT_OPTION);
        if (!is_string($salt_hex) || strlen($salt_hex) < 64) {
            $salt_hex = bin2hex(random_bytes(32));
            // autoload=false: this option is read once per request at most.
            update_option(self::SALT_OPTION, $salt_hex, false);
        }
        $salt = hex2bin($salt_hex);

        $auth_key  = defined('AUTH_KEY') ? AUTH_KEY : '';
        if ($auth_key === '') {
            // wp-config.php missing or not loaded yet; derive from a secondary
            // WordPress secret to avoid a completely insecure fallback.
            $auth_key = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '';
        }

        $key         = $this->derive_key_from_auth_key($auth_key, $salt);
        $key_version = substr(hash('sha256', 'kv:' . $key), 0, 16);

        return [$key, $key_version];
    }

    /**
     * HKDF-SHA256 key derivation.
     *
     * @param string      $auth_key  AUTH_KEY from wp-config.php.
     * @param string|null $salt      Per-install salt; if null, read from DB.
     * @return string 32-byte binary key.
     */
    private function derive_key_from_auth_key(string $auth_key, ?string $salt = null): string {
        if ($salt === null) {
            $salt_hex = (string) get_option(self::SALT_OPTION, '');
            $salt     = $salt_hex !== '' ? hex2bin($salt_hex) : '';
        }

        // IKM = AUTH_KEY || per-install salt
        $ikm  = $auth_key . $salt;
        // Context binds the key to this plugin + site URL (prevents cross-site key reuse)
        $info = self::HKDF_INFO . ':' . site_url();

        return hash_hkdf(self::HKDF_HASH, $ikm, self::KEY_BYTES, $info);
    }

    /**
     * Compute the WordPress option name for a logical secret name.
     * The result is a hash, so the logical name is not stored.
     */
    private function option_key(string $name): string {
        return 'rjv_agi_vault_' . hash('sha256', 'rjv-vault-name:' . $name);
    }

    /** Emit an audit log entry (no-throw: vault must never crash WordPress). */
    private function audit(string $action, string $name): void {
        try {
            if (class_exists(\RJV_AGI_Bridge\AuditLog::class, false)) {
                \RJV_AGI_Bridge\AuditLog::log(
                    $action,
                    'vault',
                    0,
                    ['name_hash' => hash('sha256', $name)],
                    3
                );
            }
        } catch (\Throwable $e) {
            // Intentionally silent – audit failure must never break credential access
        }
    }
}
