<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * WordPress Cron Management API
 *
 * Exposes WordPress scheduled tasks with enterprise-grade controls:
 *   – Full cron event listing with next-run times and recurrence
 *   – Custom schedule listing (all registered intervals)
 *   – Schedule new events (single or recurring)
 *   – Clear / unschedule events
 *   – Run a cron hook immediately (do_action)
 *   – Cron execution history (from audit log)
 *   – Validate whether a hook has registered callbacks
 */
class Cron extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/cron', [
            ['methods' => 'GET',  'callback' => [$this, 'list_all'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'schedule'],  'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/cron/schedules', [
            ['methods' => 'GET',  'callback' => [$this, 'schedules'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/cron/run', [
            ['methods' => 'POST', 'callback' => [$this, 'run_now'],   'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/cron/clear', [
            ['methods' => 'POST', 'callback' => [$this, 'clear'],     'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/cron/validate', [
            ['methods' => 'POST', 'callback' => [$this, 'validate'],  'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/cron/history', [
            ['methods' => 'GET',  'callback' => [$this, 'history'],   'permission_callback' => [Auth::class, 'tier1']],
        ]);
    }

    // -------------------------------------------------------------------------
    // List all scheduled events
    // -------------------------------------------------------------------------

    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        $cron  = _get_cron_array() ?: [];
        $items = [];

        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                foreach ($events as $key => $data) {
                    $items[] = [
                        'hook'        => $hook,
                        'next_run'    => gmdate('c', (int) $timestamp),
                        'next_run_ts' => (int) $timestamp,
                        'overdue'     => (int) $timestamp < time(),
                        'seconds_until' => max(0, (int) $timestamp - time()),
                        'schedule'    => $data['schedule'] ?? 'single',
                        'interval'    => $data['interval'] ?? null,
                        'args'        => $data['args']     ?? [],
                        'key'         => $key,
                    ];
                }
            }
        }

        // Sort by next run ascending
        usort($items, fn($a, $b): int => $a['next_run_ts'] <=> $b['next_run_ts']);

        $overdue = array_values(array_filter($items, fn($i): bool => $i['overdue']));

        return $this->success([
            'events'       => $items,
            'count'        => count($items),
            'overdue_count'=> count($overdue),
            'overdue'      => $overdue,
        ]);
    }

    // -------------------------------------------------------------------------
    // List custom schedules (recurrence intervals)
    // -------------------------------------------------------------------------

    public function schedules(\WP_REST_Request $r): \WP_REST_Response {
        $all  = wp_get_schedules();
        $list = [];

        foreach ($all as $key => $sched) {
            $list[] = [
                'key'          => $key,
                'display_name' => $sched['display'] ?? $key,
                'interval'     => (int) $sched['interval'],
                'interval_human' => human_time_diff(0, (int) $sched['interval']),
            ];
        }

        return $this->success(['schedules' => $list, 'count' => count($list)]);
    }

    // -------------------------------------------------------------------------
    // Schedule a new event
    // -------------------------------------------------------------------------

    public function schedule(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $hook = sanitize_key((string) ($d['hook'] ?? ''));

        if ($hook === '') {
            return $this->error('hook is required');
        }

        $recurrence = sanitize_key((string) ($d['recurrence'] ?? ''));
        $time       = !empty($d['time']) ? (int) strtotime((string) $d['time']) : time();
        $args       = is_array($d['args'] ?? null) ? $d['args'] : [];

        if ($recurrence !== '') {
            $valid_schedules = array_keys(wp_get_schedules());
            if (!in_array($recurrence, $valid_schedules, true)) {
                return $this->error("Unknown recurrence '{$recurrence}'. Use GET /cron/schedules for valid values.", 422);
            }
            wp_schedule_event($time, $recurrence, $hook, $args);
        } else {
            wp_schedule_single_event($time, $hook, $args);
        }

        $this->log('schedule_cron', 'cron', 0, [
            'hook'        => $hook,
            'recurrence'  => $recurrence ?: 'single',
            'time'        => gmdate('c', $time),
        ], 2);

        return $this->success([
            'scheduled'   => true,
            'hook'        => $hook,
            'recurrence'  => $recurrence ?: 'single',
            'next_run'    => gmdate('c', $time),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Run a hook immediately
    // -------------------------------------------------------------------------

    public function run_now(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $hook = sanitize_key((string) ($d['hook'] ?? ''));

        if ($hook === '') {
            return $this->error('hook is required');
        }

        $args  = is_array($d['args'] ?? null) ? $d['args'] : [];
        $start = microtime(true);

        do_action_ref_array($hook, $args);

        $ms = (int) ((microtime(true) - $start) * 1000);

        $this->log('run_cron_hook', 'cron', 0, [
            'hook'        => $hook,
            'latency_ms'  => $ms,
        ], 2);

        return $this->success([
            'executed'    => true,
            'hook'        => $hook,
            'latency_ms'  => $ms,
        ]);
    }

    // -------------------------------------------------------------------------
    // Clear / unschedule events
    // -------------------------------------------------------------------------

    public function clear(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $hook = sanitize_key((string) ($d['hook'] ?? ''));

        if ($hook === '') {
            return $this->error('hook is required');
        }

        $args    = is_array($d['args'] ?? null) ? $d['args'] : [];
        $cleared = wp_clear_scheduled_hook($hook, $args);

        $this->log('clear_cron', 'cron', 0, [
            'hook'    => $hook,
            'cleared' => (int) $cleared,
        ], 3);

        return $this->success([
            'cleared'           => (int) $cleared > 0,
            'instances_cleared' => (int) $cleared,
            'hook'              => $hook,
        ]);
    }

    // -------------------------------------------------------------------------
    // Validate hook has callbacks
    // -------------------------------------------------------------------------

    public function validate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $hook = sanitize_key((string) ($d['hook'] ?? ''));

        if ($hook === '') {
            return $this->error('hook is required');
        }

        global $wp_filter;

        $has_callbacks = isset($wp_filter[$hook]) && count($wp_filter[$hook]->callbacks) > 0;
        $is_scheduled  = wp_next_scheduled($hook) !== false;

        $callbacks = [];
        if ($has_callbacks) {
            foreach ($wp_filter[$hook]->callbacks as $priority => $handlers) {
                foreach ($handlers as $handler) {
                    $cb = $handler['function'];
                    if (is_string($cb)) {
                        $callbacks[] = ['priority' => $priority, 'callback' => $cb];
                    } elseif (is_array($cb) && isset($cb[0], $cb[1])) {
                        $class  = is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0];
                        $callbacks[] = ['priority' => $priority, 'callback' => $class . '::' . $cb[1]];
                    } else {
                        $callbacks[] = ['priority' => $priority, 'callback' => '(closure)'];
                    }
                }
            }
        }

        return $this->success([
            'hook'            => $hook,
            'has_callbacks'   => $has_callbacks,
            'is_scheduled'    => $is_scheduled,
            'next_run'        => $is_scheduled ? gmdate('c', (int) wp_next_scheduled($hook)) : null,
            'callbacks'       => $callbacks,
        ]);
    }

    // -------------------------------------------------------------------------
    // Cron execution history (from AGI audit log)
    // -------------------------------------------------------------------------

    public function history(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . RJV_AGI_LOG_TABLE;

        $limit = min(max(1, (int) ($r['limit'] ?? 50)), 200);
        $hook  = sanitize_key((string) ($r['hook'] ?? ''));

        $where  = ["action IN ('schedule_cron', 'clear_cron', 'run_cron_hook')"];
        $params = [];

        if ($hook !== '') {
            $where[]  = "details LIKE %s";
            $params[] = '%"hook":"' . $wpdb->esc_like($hook) . '"%';
        }

        $ws   = implode(' AND ', $where);
        $sql  = "SELECT action, details, timestamp, status, ip_address FROM {$table} WHERE {$ws} ORDER BY id DESC LIMIT %d";
        $params[] = $limit;

        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $items = array_map(function(array $row): array {
            $details = json_decode($row['details'] ?? '{}', true) ?: [];
            return [
                'action'    => $row['action'],
                'hook'      => $details['hook']        ?? '',
                'recurrence'=> $details['recurrence']  ?? '',
                'cleared'   => $details['cleared']     ?? null,
                'latency_ms'=> $details['latency_ms']  ?? null,
                'timestamp' => $row['timestamp'],
                'status'    => $row['status'],
                'ip'        => $row['ip_address'],
            ];
        }, $rows);

        return $this->success(['history' => $items, 'count' => count($items)]);
    }
}
