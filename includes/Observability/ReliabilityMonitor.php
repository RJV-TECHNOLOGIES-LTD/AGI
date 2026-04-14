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
        $targets            = ProgramRegistry::instance()->get_targets();
        $slo                = $this->slo_status();
        $targetAvailability = (float) ($targets['availability_slo'] ?? 99.9);
        $allowedErrorRate   = max(0.0, 100.0 - $targetAvailability);
        $actualErrorRate    = (float) ($slo['error_rate_pct'] ?? 0.0);
        $remaining          = max(0.0, $allowedErrorRate - $actualErrorRate);

        $burn = $this->burn_rate();

        return [
            'target_availability_pct'    => $targetAvailability,
            'allowed_error_rate_pct'     => round($allowedErrorRate, 3),
            'actual_error_rate_pct'      => round($actualErrorRate, 3),
            'remaining_error_budget_pct' => round($remaining, 3),
            'burn_exceeded'              => $actualErrorRate > $allowedErrorRate,
            'burn_rate'                  => $burn,
            'trace_id'                   => $this->trace_id(),
        ];
    }

    /**
     * Calculate error budget burn rates across three time windows.
     *
     * Burn rate > 1.0 means the budget is being consumed faster than it is
     * allocated (i.e., the error rate over the window exceeds the SLO target).
     *
     * @return array{1h: float, 6h: float, 24h: float, critical: bool}
     */
    public function burn_rate(): array {
        global $wpdb;
        $table   = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $targets = ProgramRegistry::instance()->get_targets();
        $target_availability = (float) ($targets['availability_slo'] ?? 99.9);
        $allowed_error_rate  = max(0.001, 100.0 - $target_availability); // avoid /0

        $windows = ['1h' => 3600, '6h' => 21600, '24h' => 86400];
        $rates   = [];

        foreach ($windows as $label => $seconds) {
            $since  = gmdate('Y-m-d H:i:s', time() - $seconds);
            $total  = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s", $since
            ));
            $errors = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s AND status = 'error'", $since
            ));
            $window_error_rate  = $total > 0 ? ($errors / $total) * 100.0 : 0.0;
            $rates[$label]      = round($window_error_rate / $allowed_error_rate, 4);
        }

        // Critically burning: 1h burn > 14.4× (would exhaust a weekly budget in an hour)
        $rates['critical'] = $rates['1h'] > 14.4;

        return $rates;
    }

    /**
     * Evaluate active alerts and dispatch email notifications for thresholds
     * that have been breached since the last notification.
     *
     * Alert thresholds (WP options):
     *   rjv_agi_alert_availability_min  – availability % floor (default 99.0)
     *   rjv_agi_alert_latency_p95_max   – p95 latency ceiling in ms (default 2000)
     *   rjv_agi_alert_burn_rate_1h_max  – 1-hour burn rate ceiling (default 14.4)
     *   rjv_agi_alert_email             – recipient email (default admin_email)
     *
     * Notifications are rate-limited to one per alert type per 30 minutes.
     */
    public function dispatch_alerts_if_needed(): void {
        $slo    = $this->slo_status();
        $budget = $this->error_budget_status();
        $burn   = $budget['burn_rate'] ?? [];

        $ti = TenantIsolation::instance();

        $avail_min   = (float) $ti->get_option('rjv_agi_alert_availability_min', 99.0);
        $latency_max = (int)   $ti->get_option('rjv_agi_alert_latency_p95_max',  2000);
        $burn_max    = (float) $ti->get_option('rjv_agi_alert_burn_rate_1h_max', 14.4);
        $email       = (string) $ti->get_option('rjv_agi_alert_email', get_option('admin_email', ''));

        $checks = [
            'low_availability' => [
                'breach'  => ((float) ($slo['availability_pct'] ?? 100)) < $avail_min,
                'message' => sprintf(
                    'Availability dropped to %.3f%% (threshold: %.1f%%)',
                    (float) ($slo['availability_pct'] ?? 100),
                    $avail_min
                ),
            ],
            'high_latency' => [
                'breach'  => ((int) ($slo['p95_latency_ms'] ?? 0)) > $latency_max,
                'message' => sprintf(
                    'P95 latency is %dms (threshold: %dms)',
                    (int) ($slo['p95_latency_ms'] ?? 0),
                    $latency_max
                ),
            ],
            'burn_rate_critical' => [
                'breach'  => ((float) ($burn['1h'] ?? 0)) > $burn_max,
                'message' => sprintf(
                    '1-hour error budget burn rate is %.2f× (threshold: %.1f×)',
                    (float) ($burn['1h'] ?? 0),
                    $burn_max
                ),
            ],
            'error_budget_exhausted' => [
                'breach'  => (bool) ($budget['burn_exceeded'] ?? false),
                'message' => sprintf(
                    'Error budget exhausted – actual error rate %.3f%% exceeds allowed %.3f%%',
                    (float) ($budget['actual_error_rate_pct'] ?? 0),
                    (float) ($budget['allowed_error_rate_pct'] ?? 0)
                ),
            ],
        ];

        $site_name = get_bloginfo('name');

        foreach ($checks as $alert_type => $check) {
            if (!$check['breach']) {
                continue;
            }
            // Rate-limit: one email per alert type per 30 minutes
            $throttle_key = 'rjv_alert_sent_' . $alert_type;
            if (get_transient($throttle_key)) {
                continue;
            }

            if ($email !== '') {
                wp_mail(
                    $email,
                    "[{$site_name}] SLO Alert: {$alert_type}",
                    "An SLO alert has been triggered on {$site_name}.\n\n" .
                    "Alert: {$alert_type}\n" .
                    "Detail: {$check['message']}\n\n" .
                    "Time: " . gmdate('c') . "\n" .
                    "Trace ID: " . $this->trace_id() . "\n\n" .
                    "Log in to your WordPress admin to review the reliability dashboard.",
                    ['Content-Type: text/plain; charset=UTF-8']
                );
            }

            set_transient($throttle_key, 1, 1800); // 30-minute cooldown

            AuditLog::log('slo_alert_dispatched', 'observability', 0, [
                'alert_type' => $alert_type,
                'message'    => $check['message'],
                'email'      => $email,
            ], 1, 'warning');
        }
    }

    /**
     * Return Prometheus-compatible text-format metrics.
     *
     * Compatible with the OpenMetrics / Prometheus exposition format
     * (Content-Type: text/plain; version=0.0.4).
     *
     * Exposed metrics:
     *   rjv_agi_requests_total{status, action}
     *   rjv_agi_request_duration_p95_ms
     *   rjv_agi_availability_pct
     *   rjv_agi_error_rate_pct
     *   rjv_agi_error_budget_remaining_pct
     *   rjv_agi_burn_rate_1h
     *   rjv_agi_burn_rate_6h
     *   rjv_agi_burn_rate_24h
     *   rjv_agi_ai_tokens_used_today
     */
    public function metrics_text(): string {
        $slo    = $this->slo_status();
        $budget = $this->error_budget_status();
        $burn   = $budget['burn_rate'] ?? [];

        // Per-action request counts for the last 24h
        global $wpdb;
        $table   = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $since24 = gmdate('Y-m-d H:i:s', time() - 86400);

        $action_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT action, status, COUNT(*) AS cnt
             FROM {$table}
             WHERE timestamp >= %s
             GROUP BY action, status
             ORDER BY cnt DESC
             LIMIT 100",
            $since24
        ), ARRAY_A);

        $tokens_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_used), 0) FROM {$table}
             WHERE timestamp >= %s AND tokens_used IS NOT NULL",
            gmdate('Y-m-d 00:00:00')
        ));

        $lines   = [];
        $lines[] = '# HELP rjv_agi_requests_total Total REST API requests in the last 24h';
        $lines[] = '# TYPE rjv_agi_requests_total counter';
        foreach ((array) $action_counts as $row) {
            $action = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) ($row['action'] ?? ''));
            $status = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) ($row['status'] ?? ''));
            $lines[] = "rjv_agi_requests_total{action=\"{$action}\",status=\"{$status}\"} {$row['cnt']}";
        }

        $lines[] = '';
        $lines[] = '# HELP rjv_agi_availability_pct Service availability percentage (24h window)';
        $lines[] = '# TYPE rjv_agi_availability_pct gauge';
        $lines[] = 'rjv_agi_availability_pct ' . (float) ($slo['availability_pct'] ?? 100);

        $lines[] = '';
        $lines[] = '# HELP rjv_agi_error_rate_pct Error rate percentage (24h window)';
        $lines[] = '# TYPE rjv_agi_error_rate_pct gauge';
        $lines[] = 'rjv_agi_error_rate_pct ' . (float) ($slo['error_rate_pct'] ?? 0);

        $lines[] = '';
        $lines[] = '# HELP rjv_agi_request_duration_p95_ms P95 request duration in milliseconds (24h)';
        $lines[] = '# TYPE rjv_agi_request_duration_p95_ms gauge';
        $lines[] = 'rjv_agi_request_duration_p95_ms ' . (int) ($slo['p95_latency_ms'] ?? 0);

        $lines[] = '';
        $lines[] = '# HELP rjv_agi_error_budget_remaining_pct Remaining error budget percentage';
        $lines[] = '# TYPE rjv_agi_error_budget_remaining_pct gauge';
        $lines[] = 'rjv_agi_error_budget_remaining_pct ' . (float) ($budget['remaining_error_budget_pct'] ?? 100);

        $lines[] = '';
        $lines[] = '# HELP rjv_agi_burn_rate Error budget burn rate (1 = consuming at exactly-SLO rate)';
        $lines[] = '# TYPE rjv_agi_burn_rate gauge';
        $lines[] = 'rjv_agi_burn_rate{window="1h"} '  . (float) ($burn['1h']  ?? 0);
        $lines[] = 'rjv_agi_burn_rate{window="6h"} '  . (float) ($burn['6h']  ?? 0);
        $lines[] = 'rjv_agi_burn_rate{window="24h"} ' . (float) ($burn['24h'] ?? 0);

        $lines[] = '';
        $lines[] = '# HELP rjv_agi_ai_tokens_used_today AI tokens consumed today';
        $lines[] = '# TYPE rjv_agi_ai_tokens_used_today counter';
        $lines[] = 'rjv_agi_ai_tokens_used_today ' . $tokens_today;

        $lines[] = '';
        return implode("\n", $lines);
    }

    public function alerts(): array {
        $drift   = $this->drift_report();
        $anomaly = $this->anomaly_report();
        $budget  = $this->error_budget_status();
        $burn    = $budget['burn_rate'] ?? [];
        $slo     = $this->slo_status();
        $alerts  = [];

        if (($drift['drift_score'] ?? 0) > 0) {
            $alerts[] = ['type' => 'config_drift', 'severity' => ($drift['drift_score'] > 10 ? 'high' : 'medium'), 'message' => 'Configuration drift detected'];
        }
        if (($anomaly['anomaly_detected'] ?? false) === true) {
            $alerts[] = ['type' => 'error_anomaly', 'severity' => 'high', 'message' => 'Error-rate anomaly detected in last hour'];
        }
        if (($budget['burn_exceeded'] ?? false) === true) {
            $alerts[] = ['type' => 'error_budget_burn', 'severity' => 'critical', 'message' => 'Error budget exhausted'];
        }
        if (($burn['critical'] ?? false) === true) {
            $alerts[] = ['type' => 'burn_rate_critical', 'severity' => 'critical',
                'message' => sprintf('1h burn rate %.2f× – budget will be exhausted within the hour', (float) ($burn['1h'] ?? 0))];
        }
        if ((float) ($slo['availability_pct'] ?? 100) < (float) TenantIsolation::instance()->get_option('rjv_agi_alert_availability_min', 99.0)) {
            $alerts[] = ['type' => 'low_availability', 'severity' => 'high',
                'message' => 'Availability below configured SLO threshold'];
        }

        return [
            'active_alerts' => $alerts,
            'count'         => count($alerts),
            'trace_id'      => $this->trace_id(),
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
        $ti         = TenantIsolation::instance();
        $thresholds = $ti->get_option('rjv_agi_release_gate_thresholds', []);
        $thresholds = is_array($thresholds) ? $thresholds : [];
        $scores = [
            'contract_tests'    => (int) $ti->get_option('rjv_agi_gate_contract_tests', 100),
            'integration_tests' => (int) $ti->get_option('rjv_agi_gate_integration_tests', 100),
            'e2e_tests'         => (int) $ti->get_option('rjv_agi_gate_e2e_tests', 100),
            'load_tests'        => (int) $ti->get_option('rjv_agi_gate_load_tests', 100),
            'chaos_tests'       => (int) $ti->get_option('rjv_agi_gate_chaos_tests', 100),
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
