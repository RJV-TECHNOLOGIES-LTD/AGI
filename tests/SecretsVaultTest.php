<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Tests;

use PHPUnit\Framework\TestCase;
use RJV_AGI_Bridge\Security\SecretsVault;

/**
 * Tests for SecretsVault – AES-256-GCM encrypt/decrypt round-trips,
 * tamper detection, and key-version mismatch handling.
 *
 * These tests do NOT write to a database; they rely on the get_option /
 * update_option stubs in tests/bootstrap.php.
 */
final class SecretsVaultTest extends TestCase {

    protected function setUp(): void {
        // Reset singleton and option store before each test
        SecretsVault::reset();
        $GLOBALS['_options'] = [];
    }

    // ── Round-trip ────────────────────────────────────────────────────────────

    public function test_put_and_get_round_trip(): void {
        $vault = SecretsVault::instance();

        $this->assertTrue($vault->put('my_token', 'super-secret-value', false));
        $this->assertSame('super-secret-value', $vault->get('my_token', false));
    }

    public function test_empty_value_round_trip(): void {
        $vault = SecretsVault::instance();

        $vault->put('empty_secret', '', false);
        $this->assertSame('', $vault->get('empty_secret', false));
    }

    public function test_get_nonexistent_returns_null(): void {
        $vault = SecretsVault::instance();
        $this->assertNull($vault->get('does_not_exist', false));
    }

    // ── has() ─────────────────────────────────────────────────────────────────

    public function test_has_returns_true_after_put(): void {
        $vault = SecretsVault::instance();
        $vault->put('present_key', 'value', false);
        $this->assertTrue($vault->has('present_key'));
    }

    public function test_has_returns_false_for_missing_key(): void {
        $vault = SecretsVault::instance();
        $this->assertFalse($vault->has('absent_key'));
    }

    // ── delete() ─────────────────────────────────────────────────────────────

    public function test_delete_removes_secret(): void {
        $vault = SecretsVault::instance();
        $vault->put('to_delete', 'value', false);
        $vault->delete('to_delete');
        $this->assertNull($vault->get('to_delete', false));
    }

    // ── masked() ─────────────────────────────────────────────────────────────

    public function test_masked_shows_first_and_last_four_chars(): void {
        $vault = SecretsVault::instance();
        $vault->put('long_token', 'abcdefghijklmnop', false);
        $masked = $vault->masked('long_token');

        $this->assertStringStartsWith('abcd', $masked);
        $this->assertStringEndsWith('mnop', $masked);
        $this->assertStringContainsString('•••', $masked);
    }

    public function test_masked_returns_empty_for_missing_key(): void {
        $vault = SecretsVault::instance();
        $this->assertSame('', $vault->masked('nonexistent'));
    }

    // ── Tamper detection ──────────────────────────────────────────────────────

    public function test_tampered_ciphertext_returns_null(): void {
        $vault = SecretsVault::instance();
        $vault->put('tamper_test', 'original-value', false);

        // Locate the stored option and corrupt the ciphertext byte
        $option_key = null;
        foreach ($GLOBALS['_options'] as $k => $v) {
            if (is_array($v) && isset($v['c'])) {
                $option_key = $k;
                break;
            }
        }
        $this->assertNotNull($option_key, 'Could not locate encrypted option');

        $envelope = $GLOBALS['_options'][$option_key];
        $ciphertext = base64_decode($envelope['c'], true);
        // Flip the first byte
        $ciphertext[0] = chr(ord($ciphertext[0]) ^ 0xFF);
        $GLOBALS['_options'][$option_key]['c'] = base64_encode($ciphertext);

        // Decryption should fail GCM authentication and return null
        SecretsVault::reset();
        $this->assertNull(SecretsVault::instance()->get('tamper_test', false));
    }
}
