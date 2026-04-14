<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Observability;

use RJV_AGI_Bridge\Bridge\TenantIsolation;

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
            $response->header('X-RJV-API-Version', 'v1');
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
