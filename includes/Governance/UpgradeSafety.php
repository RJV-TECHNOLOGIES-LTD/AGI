<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Governance;

use RJV_AGI_Bridge\Bridge\TenantIsolation;

/**
 * Upgrade safety framework: compatibility checks, migration tracking, rollback guards.
 */
final class UpgradeSafety {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function compatibility_report(): array {
        global $wp_version, $wpdb;

        $php_ok = version_compare((string) phpversion(), '8.1.0', '>=');
        $wp_ok = version_compare((string) $wp_version, '6.4.0', '>=');
        $db_ok = version_compare((string) $wpdb->db_version(), '8.0.0', '>=');

        return [
            'compatible' => $php_ok && $wp_ok && $db_ok,
            'checks' => [
                'php' => ['required' => '8.1+', 'current' => (string) phpversion(), 'ok' => $php_ok],
                'wordpress' => ['required' => '6.4+', 'current' => (string) $wp_version, 'ok' => $wp_ok],
                'mysql' => ['required' => '8.0+', 'current' => (string) $wpdb->db_version(), 'ok' => $db_ok],
            ],
            'evaluated_at' => gmdate('c'),
        ];
    }

    public function begin_upgrade(string $from, string $to): array {
        $snapshot_id = 'upgrade_' . wp_generate_uuid4();
        $snapshot = [
            'id' => $snapshot_id,
            'from_version' => $from,
            'to_version' => $to,
            'created_at' => gmdate('c'),
            'compatibility' => $this->compatibility_report(),
            'critical_options' => $this->capture_critical_options(),
            'rollback_guard' => ['active' => true, 'until' => gmdate('c', time() + HOUR_IN_SECONDS)],
            'migrations' => [],
            'status' => 'running',
        ];

        $history = $this->history();
        $history[] = $snapshot;
        TenantIsolation::instance()->set_option('rjv_agi_upgrade_history', $history);
        TenantIsolation::instance()->set_option('rjv_agi_upgrade_lock', $snapshot['rollback_guard']);
        TenantIsolation::instance()->set_option('rjv_agi_upgrade_last', $snapshot);

        return $snapshot;
    }

    public function record_migration(string $snapshot_id, string $step, string $status, array $details = []): void {
        $history = $this->history();
        foreach ($history as &$row) {
            if (($row['id'] ?? '') !== $snapshot_id) {
                continue;
            }
            $row['migrations'][] = [
                'step' => sanitize_key($step),
                'status' => sanitize_key($status),
                'details' => $details,
                'at' => gmdate('c'),
            ];
            $row['updated_at'] = gmdate('c');
            TenantIsolation::instance()->set_option('rjv_agi_upgrade_last', $row);
            break;
        }
        TenantIsolation::instance()->set_option('rjv_agi_upgrade_history', $history);
    }

    public function complete_upgrade(string $snapshot_id, bool $success, array $details = []): void {
        $history = $this->history();
        foreach ($history as &$row) {
            if (($row['id'] ?? '') !== $snapshot_id) {
                continue;
            }
            $row['status'] = $success ? 'completed' : 'failed';
            $row['completed_at'] = gmdate('c');
            $row['result'] = $details;
            TenantIsolation::instance()->set_option('rjv_agi_upgrade_last', $row);
            break;
        }
        TenantIsolation::instance()->set_option('rjv_agi_upgrade_history', $history);
        TenantIsolation::instance()->set_option('rjv_agi_upgrade_lock', ['active' => false, 'until' => gmdate('c')]);
    }

    public function status(): array {
        $lock = TenantIsolation::instance()->get_option('rjv_agi_upgrade_lock', ['active' => false]);
        $last = TenantIsolation::instance()->get_option('rjv_agi_upgrade_last', []);
        return [
            'compatibility' => $this->compatibility_report(),
            'rollback_guard' => is_array($lock) ? $lock : ['active' => false],
            'last_upgrade' => is_array($last) ? $last : [],
            'history_count' => count($this->history()),
        ];
    }

    private function history(): array {
        $history = TenantIsolation::instance()->get_option('rjv_agi_upgrade_history', []);
        return is_array($history) ? array_values($history) : [];
    }

    private function capture_critical_options(): array {
        $keys = [
            'rjv_agi_api_contract',
            'rjv_agi_policy_rules',
            'rjv_agi_capability_overrides',
            'rjv_agi_program_targets',
            'rjv_agi_compliance_controls',
        ];
        $snapshot = [];
        foreach ($keys as $key) {
            $snapshot[$key] = TenantIsolation::instance()->get_option($key, null);
        }
        return $snapshot;
    }
}

