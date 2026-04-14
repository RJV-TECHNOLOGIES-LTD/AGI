<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Security;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Bridge\TenantIsolation;

/**
 * Security/compliance baseline: threat controls, secret rotation, legal holds, residency.
 */
final class ComplianceManager {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function threat_controls(): array {
        $default = [
            'prompt_injection' => ['enabled' => true, 'status' => 'monitoring'],
            'privilege_escalation' => ['enabled' => true, 'status' => 'enforced'],
            'data_exfiltration' => ['enabled' => true, 'status' => 'enforced'],
            'supply_chain' => ['enabled' => true, 'status' => 'monitoring'],
            'audit_tampering' => ['enabled' => true, 'status' => 'enforced'],
        ];
        $stored = TenantIsolation::instance()->get_option('rjv_agi_threat_model_controls', []);
        return is_array($stored) ? array_replace_recursive($default, $stored) : $default;
    }

    public function update_threat_controls(array $controls): array {
        $existing = $this->threat_controls();
        $updated = array_replace_recursive($existing, $controls);
        TenantIsolation::instance()->set_option('rjv_agi_threat_model_controls', $updated);
        return ['success' => true, 'threat_controls' => $updated];
    }

    public function compliance_controls(): array {
        $default = [
            'retention_days' => 90,
            'legal_hold' => ['enabled' => false, 'reason' => '', 'set_at' => ''],
            'data_residency' => ['region' => 'global', 'strict' => false],
            'exports_enabled' => true,
        ];
        $stored = TenantIsolation::instance()->get_option('rjv_agi_compliance_controls', []);
        return is_array($stored) ? array_replace_recursive($default, $stored) : $default;
    }

    public function update_compliance_controls(array $controls): array {
        $existing = $this->compliance_controls();
        $updated = array_replace_recursive($existing, $controls);
        if ((int) ($updated['retention_days'] ?? 90) < 1) {
            $updated['retention_days'] = 1;
        }
        TenantIsolation::instance()->set_option('rjv_agi_compliance_controls', $updated);
        return ['success' => true, 'compliance_controls' => $updated];
    }

    public function apply_legal_hold(bool $enabled, string $reason = ''): array {
        $controls = $this->compliance_controls();
        $controls['legal_hold'] = [
            'enabled' => $enabled,
            'reason' => sanitize_text_field($reason),
            'set_at' => gmdate('c'),
        ];
        TenantIsolation::instance()->set_option('rjv_agi_compliance_controls', $controls);
        AuditLog::log('compliance_legal_hold_updated', 'compliance', 0, $controls['legal_hold'], 2);
        return ['success' => true, 'legal_hold' => $controls['legal_hold']];
    }

    public function rotate_secret(string $secret_key, string $new_secret, string $actor = 'system'): array {
        $allowed = ['openai_key', 'anthropic_key', 'tenant_secret', 'api_key'];
        if (!in_array($secret_key, $allowed, true)) {
            return ['success' => false, 'error' => 'Secret key not rotatable'];
        }
        if ($new_secret === '') {
            return ['success' => false, 'error' => 'New secret is required'];
        }

        $option = 'rjv_agi_' . $secret_key;
        $old = (string) get_option($option, '');
        update_option($option, $new_secret);

        $log = TenantIsolation::instance()->get_option('rjv_agi_secret_rotation_log', []);
        $log = is_array($log) ? $log : [];
        $prevHash = !empty($log) ? (string) (($log[count($log) - 1]['hash'] ?? '')) : '';
        $entry = [
            'secret_key' => $secret_key,
            'rotated_at' => gmdate('c'),
            'actor' => sanitize_text_field($actor),
            'old_fingerprint' => $old !== '' ? substr(hash('sha256', $old), 0, 16) : '',
            'new_fingerprint' => substr(hash('sha256', $new_secret), 0, 16),
            'prev_hash' => $prevHash,
        ];
        $entry['hash'] = hash('sha256', wp_json_encode($entry));
        $log[] = $entry;
        TenantIsolation::instance()->set_option('rjv_agi_secret_rotation_log', $log);

        AuditLog::log('secret_rotated', 'security', 0, [
            'secret_key' => $secret_key,
            'actor' => $actor,
            'fingerprint' => $entry['new_fingerprint'],
        ], 3);

        return ['success' => true, 'rotation' => $entry];
    }

    public function verify_rotation_chain(): array {
        $log = TenantIsolation::instance()->get_option('rjv_agi_secret_rotation_log', []);
        $log = is_array($log) ? $log : [];
        $previous = '';
        foreach ($log as $idx => $entry) {
            if (($entry['prev_hash'] ?? '') !== $previous) {
                return ['valid' => false, 'error' => "Hash chain break at index {$idx}"];
            }
            $expected = $entry;
            unset($expected['hash']);
            $calc = hash('sha256', wp_json_encode($expected));
            if (!hash_equals((string) ($entry['hash'] ?? ''), $calc)) {
                return ['valid' => false, 'error' => "Hash mismatch at index {$idx}"];
            }
            $previous = (string) $entry['hash'];
        }
        return ['valid' => true, 'entries' => count($log)];
    }

    public function export_compliance_snapshot(): array {
        return [
            'generated_at' => gmdate('c'),
            'tenant_id' => TenantIsolation::instance()->get_tenant_id(),
            'threat_controls' => $this->threat_controls(),
            'compliance_controls' => $this->compliance_controls(),
            'rotation_chain' => $this->verify_rotation_chain(),
        ];
    }
}

