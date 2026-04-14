<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Hosting;

use RJV_AGI_Bridge\AuditLog;

/**
 * TunnelHealthMonitor
 *
 * WP-cron powered self-healing monitor for the Cloudflare Tunnel.
 *
 * Registered on the 'rjv_agi_tunnel_heartbeat' WP cron event (every 5 min).
 * When the tunnel process is found to be dead while it should be running:
 *
 *  1. Increments a consecutive-failure counter stored in WP options.
 *  2. Attempts restart using the same configuration (mode, port, token).
 *  3. If restart succeeds, resets the failure counter and logs a recovery event.
 *  4. If restart fails, applies exponential back-off (skip N subsequent
 *     heartbeat ticks before retrying: 1, 2, 4, 8, 16 … up to 32 ticks max).
 *  5. After MAX_CONSECUTIVE_FAILURES successive failed restarts (without a
 *     single success), sends a WP admin email notification and pauses
 *     auto-restart until the administrator intervenes.
 *
 * The monitor also performs a binary integrity check on every tick, ensuring
 * that the cloudflared binary on disk still matches the hash recorded at
 * download time.  A mismatch raises a severity-4 audit event and halts
 * the tunnel to prevent execution of a tampered binary.
 */
final class TunnelHealthMonitor {

    private const OPT_FAILURES    = 'rjv_agi_tunnel_failures';
    private const OPT_SKIP_TICKS  = 'rjv_agi_tunnel_skip_ticks';
    private const OPT_LAST_FAIL   = 'rjv_agi_tunnel_last_fail';
    private const OPT_NOTIFIED    = 'rjv_agi_tunnel_admin_notified';
    private const MAX_FAILURES    = 5;
    private const MAX_SKIP_TICKS  = 32;

    private TunnelManager $tunnel;

    public function __construct() {
        $this->tunnel = new TunnelManager();
    }

    // =========================================================================
    // Cron callback (registered in Plugin.php on 'rjv_agi_tunnel_heartbeat')
    // =========================================================================

    /**
     * Heartbeat tick – called every 5 minutes by WP-cron.
     */
    public function tick(): void {
        $status = $this->tunnel->status();

        // Nothing to monitor if the tunnel was not intentionally started
        if (get_option('rjv_agi_tunnel_enabled', '0') !== '1') {
            return;
        }

        // ── Binary integrity check ─────────────────────────────────────────
        if ($status['binary_present'] && $status['binary_verified']) {
            $integrity = $this->tunnel->verify_binary_integrity();
            if (!$integrity['verified'] && $integrity['reason'] === 'hash_mismatch') {
                AuditLog::log('tunnel_health_binary_tampered', 'hosting', 0, $integrity, 4);
                $this->tunnel->stop();
                $this->notify_admin('binary_tampered', $integrity);
                return;
            }
        }

        // ── Process health check ───────────────────────────────────────────
        if ($status['running']) {
            // Healthy – reset failure counter and back-off
            if ((int) get_option(self::OPT_FAILURES, 0) > 0) {
                update_option(self::OPT_FAILURES,   0);
                update_option(self::OPT_SKIP_TICKS, 0);
                update_option(self::OPT_NOTIFIED,   0);
                AuditLog::log('tunnel_health_recovered', 'hosting', 0, ['url' => $status['url']], 2);
            }
            return;
        }

        // ── Dead process handling ──────────────────────────────────────────
        $failures   = (int) get_option(self::OPT_FAILURES, 0);
        $skip_ticks = (int) get_option(self::OPT_SKIP_TICKS, 0);

        // Back-off: skip ticks without retrying
        if ($skip_ticks > 0) {
            update_option(self::OPT_SKIP_TICKS, $skip_ticks - 1);
            AuditLog::log('tunnel_health_backoff', 'hosting', 0,
                ['ticks_remaining' => $skip_ticks - 1, 'consecutive_failures' => $failures], 2);
            return;
        }

        // Hard cap reached – do not retry until manual intervention
        if ($failures >= self::MAX_FAILURES) {
            if (!get_option(self::OPT_NOTIFIED)) {
                $this->notify_admin('max_failures', ['consecutive_failures' => $failures]);
                update_option(self::OPT_NOTIFIED, 1);
            }
            return;
        }

        // ── Attempt restart ────────────────────────────────────────────────
        $result = $this->attempt_restart($status);

        if ($result['success']) {
            update_option(self::OPT_FAILURES,   0);
            update_option(self::OPT_SKIP_TICKS, 0);
            update_option(self::OPT_NOTIFIED,   0);
            AuditLog::log('tunnel_health_restarted', 'hosting', 0,
                ['url' => $result['url'] ?? '', 'after_failure_count' => $failures], 2);
        } else {
            $new_failures = $failures + 1;
            // Exponential back-off: 2^(failures - 1), capped at MAX_SKIP_TICKS
            $new_skip = min((int) pow(2, max(0, $new_failures - 1)), self::MAX_SKIP_TICKS);

            update_option(self::OPT_FAILURES,   $new_failures);
            update_option(self::OPT_SKIP_TICKS, $new_skip);
            update_option(self::OPT_LAST_FAIL,  time());

            AuditLog::log('tunnel_health_restart_failed', 'hosting', 0, [
                'consecutive_failures' => $new_failures,
                'next_retry_ticks'     => $new_skip,
                'error'                => $result['error'] ?? 'unknown',
            ], 3);
        }
    }

    /**
     * Register WP cron hooks and custom interval.
     * Called from Plugin::boot().
     */
    public static function register_hooks(): void {
        add_filter('cron_schedules', [self::class, 'add_interval']);
        add_action('rjv_agi_tunnel_heartbeat', [new self(), 'tick']);
    }

    /**
     * Add a five-minute cron interval.
     */
    public static function add_interval(array $schedules): array {
        if (!isset($schedules['rjv_agi_five_minutes'])) {
            $schedules['rjv_agi_five_minutes'] = [
                'interval' => 300,
                'display'  => __('Every 5 Minutes (RJV AGI)', 'rjv-agi-bridge'),
            ];
        }
        return $schedules;
    }

    /**
     * Return current monitor state for the admin dashboard.
     */
    public function monitor_status(): array {
        return [
            'consecutive_failures' => (int) get_option(self::OPT_FAILURES,   0),
            'skip_ticks_remaining' => (int) get_option(self::OPT_SKIP_TICKS, 0),
            'last_failure_time'    => (int) get_option(self::OPT_LAST_FAIL,   0),
            'admin_notified'       => (bool) get_option(self::OPT_NOTIFIED,   0),
            'max_failures'         => self::MAX_FAILURES,
            'next_heartbeat'       => (int) wp_next_scheduled('rjv_agi_tunnel_heartbeat'),
        ];
    }

    /**
     * Reset failure state and re-enable auto-restart.
     * Called by the admin after investigating a failure.
     */
    public function reset_failures(): void {
        update_option(self::OPT_FAILURES,   0);
        update_option(self::OPT_SKIP_TICKS, 0);
        update_option(self::OPT_NOTIFIED,   0);
        AuditLog::log('tunnel_health_reset', 'hosting', 0, [], 2);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Attempt to restart the tunnel using the stored configuration.
     */
    private function attempt_restart(array $status): array {
        $mode      = $status['mode'] ?? 'quick';
        $local_port = (int) get_option('rjv_agi_tunnel_local_port', 80);

        if ($mode === 'named') {
            $hostname = (string) get_option('rjv_agi_tunnel_hostname', '');
            $token    = \RJV_AGI_Bridge\Security\SecretsVault::instance()->get('tunnel_named_token', false);

            if ($token === null || $token === '' || $hostname === '') {
                return ['success' => false, 'error' => 'Named tunnel credentials missing'];
            }
            return $this->tunnel->start_named($token, $hostname, $local_port);
        }

        return $this->tunnel->start_quick($local_port);
    }

    /**
     * Send an admin email notification about tunnel health events.
     *
     * @param string $reason  'binary_tampered' | 'max_failures'
     */
    private function notify_admin(string $reason, array $context = []): void {
        $admin_email = get_option('admin_email', '');
        if ($admin_email === '') {
            return;
        }

        $site  = get_bloginfo('name');
        $url   = get_bloginfo('url');
        $body  = $this->build_notification_body($reason, $context, $site, $url);
        $subj  = "[{$site}] RJV AGI Tunnel Alert – " . ucwords(str_replace('_', ' ', $reason));

        wp_mail($admin_email, $subj, $body);

        AuditLog::log('tunnel_health_admin_notified', 'hosting', 0,
            ['reason' => $reason, 'admin' => $admin_email], 3);
    }

    private function build_notification_body(
        string $reason, array $ctx, string $site, string $url
    ): string {
        $lines = [
            "RJV AGI Bridge – Tunnel Health Alert",
            str_repeat('─', 40),
            "Site : {$site} ({$url})",
            "Time : " . gmdate('Y-m-d H:i:s') . ' UTC',
            "Event: {$reason}",
            '',
        ];

        if ($reason === 'binary_tampered') {
            $lines[] = "The cloudflared binary has been modified on disk.";
            $lines[] = "Stored SHA-256 : " . ($ctx['stored'] ?? 'n/a');
            $lines[] = "Actual SHA-256 : " . ($ctx['actual'] ?? 'n/a');
            $lines[] = "The tunnel has been stopped. Please re-download the binary from";
            $lines[] = "the AGI Bridge admin panel to resume hosting.";
        } elseif ($reason === 'max_failures') {
            $lines[] = "The tunnel has failed to restart " . self::MAX_FAILURES . " consecutive times.";
            $lines[] = "Auto-restart has been suspended to prevent a restart loop.";
            $lines[] = "Please log in to the WordPress admin and check the tunnel status.";
        }

        $lines[] = '';
        $lines[] = 'To reset and re-enable auto-restart, visit:';
        $lines[] = admin_url('admin.php?page=rjv-agi-bridge#hosting');

        return implode("\n", $lines);
    }
}
