<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Observability;

use RJV_AGI_Bridge\Bridge\TenantIsolation;
use RJV_AGI_Bridge\Governance\ProgramRegistry;

/**
 * Enterprise observability and reliability monitor.
 */
final class ReliabilityMonitor {
    private static ?self $instance = null;
    private ?string $trace_id = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function begin_trace(\WP_REST_Request $request): string {
        $incoming = sanitize_text_field((string) $request->get_header('X-RJV-Trace-ID'));
        $this->trace_id = $incoming !== '' ? $incoming : wp_generate_uuid4();
        return $this->trace_id;
    }

    public function trace_id(): string {
        return $this->trace_id ?? wp_generate_uuid4();
    }

    public function attach_headers($response) {
        if (method_exists($response, 'header')) {
            $response->header('X-RJV-Trace-ID', $this->trace_id());
            $response->header('X-RJV-Governance', 'enforced');
        }
        return $response;
    }

    public function slo_status(): array {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s",
            $since
        ));
        $errors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s AND status = %s",
            $since,
            'error'
        ));

        $latencies = $wpdb->get_col($wpdb->prepare(
            "SELECT execution_time_ms FROM {$table} WHERE timestamp >= %s AND execution_time_ms IS NOT NULL ORDER BY execution_time_ms ASC",
            $since
        ));
        $latencies = array_values(array_filter(array_map('intval', is_array($latencies) ? $latencies : []), static fn($v) => $v >= 0));
        $p95 = $this->percentile($latencies, 95);

        $availability = $total > 0 ? round((($total - $errors) / $total) * 100, 3) : 100.0;
        $error_rate = $total > 0 ? round(($errors / $total) * 100, 3) : 0.0;

        return [
            'window' => '24h',
            'availability_pct' => $availability,
            'error_rate_pct' => $error_rate,
            'p95_latency_ms' => $p95,
            'sample_size' => $total,
            'trace_id' => $this->trace_id(),
        ];
    }

    public function drift_report(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'rjv_agi_%'",
            ARRAY_A
        ) ?: [];

        $current = [];
        foreach ($rows as $row) {
            $current[$row['option_name']] = $row['option_value'];
        }
        ksort($current);

        $baseline = TenantIsolation::instance()->get_option('rjv_agi_config_baseline', []);
        $baseline = is_array($baseline) ? $baseline : [];

        $added = array_values(array_diff(array_keys($current), array_keys($baseline)));
        $removed = array_values(array_diff(array_keys($baseline), array_keys($current)));
        $changed = [];
        foreach ($current as $key => $value) {
            if (array_key_exists($key, $baseline) && (string) $baseline[$key] !== (string) $value) {
                $changed[] = $key;
            }
        }

        return [
            'baseline_exists' => !empty($baseline),
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'drift_score' => count($added) + count($removed) + count($changed),
            'trace_id' => $this->trace_id(),
        ];
    }

    public function snapshot_baseline(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'rjv_agi_%'",
            ARRAY_A
        ) ?: [];

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[$row['option_name']] = $row['option_value'];
        }
        TenantIsolation::instance()->set_option('rjv_agi_config_baseline', $snapshot);

        return [
            'success' => true,
            'captured_options' => count($snapshot),
            'captured_at' => gmdate('c'),
            'trace_id' => $this->trace_id(),
        ];
    }

    public function anomaly_report(): array {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $hourSince = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $daySince = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $hourTotal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s", $hourSince));
        $hourErrors = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s AND status = %s", $hourSince, 'error'));
        $dayTotal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s", $daySince));
        $dayErrors = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s AND status = %s", $daySince, 'error'));

        $hourRate = $hourTotal > 0 ? ($hourErrors / $hourTotal) * 100 : 0.0;
        $dayRate = $dayTotal > 0 ? ($dayErrors / $dayTotal) * 100 : 0.0;
        $ratio = $dayRate > 0 ? ($hourRate / $dayRate) : ($hourRate > 0 ? 999.0 : 1.0);

        return [
            'error_rate_last_hour_pct' => round($hourRate, 3),
            'error_rate_24h_pct' => round($dayRate, 3),
            'anomaly_ratio' => round($ratio, 3),
            'anomaly_detected' => $ratio >= 2.0 && $hourTotal >= 20,
            'trace_id' => $this->trace_id(),
        ];
    }

    public function error_budget_status(): array {
        $targets = ProgramRegistry::instance()->get_targets();
        $slo = $this->slo_status();
        $targetAvailability = (float) ($targets['availability_slo'] ?? 99.9);
        $allowedErrorRate = max(0.0, 100.0 - $targetAvailability);
        $actualErrorRate = (float) ($slo['error_rate_pct'] ?? 0.0);
        $remaining = max(0.0, $allowedErrorRate - $actualErrorRate);

        return [
            'target_availability_pct' => $targetAvailability,
            'allowed_error_rate_pct' => round($allowedErrorRate, 3),
            'actual_error_rate_pct' => round($actualErrorRate, 3),
            'remaining_error_budget_pct' => round($remaining, 3),
            'burn_exceeded' => $actualErrorRate > $allowedErrorRate,
            'trace_id' => $this->trace_id(),
        ];
    }

    public function alerts(): array {
        $drift = $this->drift_report();
        $anomaly = $this->anomaly_report();
        $budget = $this->error_budget_status();
        $alerts = [];

        if (($drift['drift_score'] ?? 0) > 0) {
            $alerts[] = ['type' => 'config_drift', 'severity' => ($drift['drift_score'] > 10 ? 'high' : 'medium'), 'message' => 'Configuration drift detected'];
        }
        if (($anomaly['anomaly_detected'] ?? false) === true) {
            $alerts[] = ['type' => 'error_anomaly', 'severity' => 'high', 'message' => 'Error-rate anomaly detected in last hour'];
        }
        if (($budget['burn_exceeded'] ?? false) === true) {
            $alerts[] = ['type' => 'error_budget_burn', 'severity' => 'critical', 'message' => 'Error budget exhausted'];
        }

        return [
            'active_alerts' => $alerts,
            'count' => count($alerts),
            'trace_id' => $this->trace_id(),
        ];
    }

    public function remediation_playbooks(): array {
        return [
            'config_drift' => [
                'steps' => ['Review drift report', 'Compare with baseline', 'Reconcile unauthorized option changes', 'Re-snapshot baseline'],
            ],
            'error_anomaly' => [
                'steps' => ['Review last-hour failing actions', 'Throttle risky routes', 'Enable guarded approval mode', 'Rollback latest high-risk change'],
            ],
            'error_budget_burn' => [
                'steps' => ['Enforce change freeze', 'Prioritize reliability fixes', 'Run targeted recovery plan', 'Lift freeze after sustained SLO recovery'],
            ],
        ];
    }

    public function release_gates_status(): array {
        $thresholds = get_option('rjv_agi_release_gate_thresholds', []);
        $thresholds = is_array($thresholds) ? $thresholds : [];
        $scores = [
            'contract_tests' => (int) get_option('rjv_agi_gate_contract_tests', 100),
            'integration_tests' => (int) get_option('rjv_agi_gate_integration_tests', 100),
            'e2e_tests' => (int) get_option('rjv_agi_gate_e2e_tests', 100),
            'load_tests' => (int) get_option('rjv_agi_gate_load_tests', 100),
            'chaos_tests' => (int) get_option('rjv_agi_gate_chaos_tests', 100),
        ];
        $map = [
            'contract_tests' => 'contract_tests_min',
            'integration_tests' => 'integration_tests_min',
            'e2e_tests' => 'e2e_tests_min',
            'load_tests' => 'load_tests_min',
            'chaos_tests' => 'chaos_tests_min',
        ];

        $gates = [];
        $allPass = true;
        foreach ($map as $scoreKey => $thresholdKey) {
            $min = (int) ($thresholds[$thresholdKey] ?? 80);
            $score = (int) ($scores[$scoreKey] ?? 0);
            $pass = $score >= $min;
            $allPass = $allPass && $pass;
            $gates[$scoreKey] = ['score' => $score, 'minimum' => $min, 'pass' => $pass];
        }

        return ['all_pass' => $allPass, 'gates' => $gates, 'trace_id' => $this->trace_id()];
    }

    private function percentile(array $numbers, int $percentile): int {
        $count = count($numbers);
        if ($count === 0) {
            return 0;
        }
        if ($count === 1) {
            return (int) $numbers[0];
        }

        $index = (int) ceil(($percentile / 100) * $count) - 1;
        $index = max(0, min($count - 1, $index));
        return (int) $numbers[$index];
    }
}
