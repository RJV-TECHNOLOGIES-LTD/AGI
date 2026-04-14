<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Tests;

use PHPUnit\Framework\TestCase;
use RJV_AGI_Bridge\Settings;

/**
 * Tests for Settings schema validation.
 *
 * Verifies that validate_and_cast (called internally by Settings::set) rejects
 * values that violate the declared type / min / max / enum constraints and
 * accepts valid ones.
 */
final class SettingsValidationTest extends TestCase {

    // ── int range ────────────────────────────────────────────────────────────

    public function test_rate_limit_accepts_valid_value(): void {
        Settings::set('rate_limit', 300);
        $this->assertSame(300, Settings::get('rate_limit'));
    }

    public function test_rate_limit_rejects_negative(): void {
        $this->expectException(\ValueError::class);
        Settings::set('rate_limit', -1);
    }

    public function test_rate_limit_rejects_over_max(): void {
        $this->expectException(\ValueError::class);
        Settings::set('rate_limit', 99999);
    }

    // ── float range ──────────────────────────────────────────────────────────

    public function test_ai_temperature_accepts_boundary_values(): void {
        Settings::set('ai_temperature', 0.0);
        $this->assertEqualsWithDelta(0.0, Settings::get('ai_temperature'), 0.001);

        Settings::set('ai_temperature', 2.0);
        $this->assertEqualsWithDelta(2.0, Settings::get('ai_temperature'), 0.001);
    }

    public function test_ai_temperature_rejects_below_min(): void {
        $this->expectException(\ValueError::class);
        Settings::set('ai_temperature', -0.1);
    }

    public function test_ai_temperature_rejects_above_max(): void {
        $this->expectException(\ValueError::class);
        Settings::set('ai_temperature', 2.1);
    }

    // ── enum ─────────────────────────────────────────────────────────────────

    public function test_default_model_accepts_valid_enum_value(): void {
        foreach (['openai', 'anthropic', 'google'] as $provider) {
            Settings::set('default_model', $provider);
            $this->assertSame($provider, Settings::get('default_model'));
        }
    }

    public function test_default_model_rejects_unknown_provider(): void {
        $this->expectException(\ValueError::class);
        Settings::set('default_model', 'cohere');
    }

    // ── bool ─────────────────────────────────────────────────────────────────

    public function test_audit_enabled_accepts_bool(): void {
        Settings::set('audit_enabled', true);
        $this->assertTrue(Settings::get('audit_enabled'));

        Settings::set('audit_enabled', false);
        $this->assertFalse(Settings::get('audit_enabled'));
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function test_named_keys_rejects_non_array(): void {
        $this->expectException(\ValueError::class);
        Settings::set('named_keys', 'not-an-array');
    }

    public function test_named_keys_accepts_array(): void {
        $keys = [['name' => 'ci', 'key' => 'abc', 'tier' => 1]];
        Settings::set('named_keys', $keys);
        $this->assertSame($keys, Settings::get('named_keys'));
    }

    // ── unknown key (silently stored, no validation) ──────────────────────────

    public function test_set_unknown_key_is_stored_without_validation(): void {
        // Settings::set accepts unknown keys (plugin extensibility); the absence
        // of a schema simply bypasses typed validation.
        $result = Settings::set('custom_extension_key', 'any-value');
        $this->assertTrue($result);
        $this->assertSame('any-value', Settings::get('custom_extension_key'));
    }
}
