<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Hosting;

use RJV_AGI_Bridge\AuditLog;

/**
 * Cloudflare Tunnel Manager
 *
 * Allows a WordPress site running on a developer's local machine to be
 * accessible on the public internet without purchasing hosting.
 *
 * Two modes:
 *   Quick Tunnel  – Zero-config; generates a random *.trycloudflare.com URL.
 *                   No Cloudflare account required. Ideal for demos and testing.
 *   Named Tunnel  – Persistent subdomain or custom domain. Requires a Cloudflare
 *                   account and an API token stored in plugin settings.
 *
 * The manager downloads the correct `cloudflared` binary for the server's
 * OS/architecture, then manages the process lifecycle (start, stop, health).
 * The public tunnel URL is stored as a WP option and can optionally overwrite
 * `siteurl` / `home` so WordPress links are correct.
 *
 * Security: all process management uses `proc_open()` with explicit argument
 * arrays, never `shell_exec` with user input, to prevent injection.
 */
final class TunnelManager {

    private const OPTION_PID      = 'rjv_agi_tunnel_pid';
    private const OPTION_URL      = 'rjv_agi_tunnel_url';
    private const OPTION_LOG      = 'rjv_agi_tunnel_log';
    private const OPTION_MODE     = 'rjv_agi_tunnel_mode';
    private const OPTION_TOKEN    = 'rjv_agi_tunnel_token';
    private const OPTION_HOSTNAME = 'rjv_agi_tunnel_hostname';
    private const OPTION_ENABLED  = 'rjv_agi_tunnel_enabled';

    /** Map of OS+arch to cloudflared download URL template. */
    private const BINARY_URLS = [
        'linux_x86_64'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64',
        'linux_aarch64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64',
        'linux_armv7l'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm',
        'darwin_x86_64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-amd64',
        'darwin_arm64'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-arm64',
        'windows_AMD64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe',
    ];

    private string $binary_path;
    private string $log_dir;

    public function __construct() {
        $upload           = wp_upload_dir();
        $this->log_dir    = trailingslashit((string) $upload['basedir']) . 'rjv-agi-tunnel';
        $this->binary_path = $this->log_dir . '/cloudflared' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        wp_mkdir_p($this->log_dir);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Start a quick tunnel (no account required).
     *
     * @param  int   $local_port  Local HTTP port WordPress is serving on (default 80).
     * @return array{success: bool, url?: string, mode?: string, error?: string}
     */
    public function start_quick(int $local_port = 80): array {
        if ($this->is_running()) {
            $url = (string) get_option(self::OPTION_URL, '');
            return ['success' => true, 'url' => $url, 'mode' => 'quick', 'message' => 'Tunnel already running'];
        }

        $binary = $this->ensure_binary();
        if (!$binary['success']) {
            return $binary;
        }

        $log_file = $this->log_dir . '/tunnel.log';
        $cmd      = [$this->binary_path, 'tunnel', '--url', "http://localhost:{$local_port}"];

        $pid = $this->spawn_background($cmd, $log_file);
        if ($pid === null) {
            return ['success' => false, 'error' => 'Failed to start cloudflared process'];
        }

        update_option(self::OPTION_PID,     $pid);
        update_option(self::OPTION_MODE,    'quick');
        update_option(self::OPTION_ENABLED, '1');

        // Wait for the URL to appear in the log (up to 15 seconds)
        $url = $this->wait_for_tunnel_url($log_file, 15);

        if ($url !== '') {
            update_option(self::OPTION_URL, $url);
        }

        AuditLog::log('tunnel_started', 'hosting', 0, [
            'mode' => 'quick',
            'url'  => $url,
            'port' => $local_port,
            'pid'  => $pid,
        ], 2);

        return ['success' => true, 'url' => $url, 'mode' => 'quick', 'pid' => $pid];
    }

    /**
     * Start a named / authenticated tunnel (requires Cloudflare token).
     *
     * @param  string $token     Cloudflare tunnel token (from Cloudflare dashboard).
     * @param  string $hostname  Public hostname (e.g. mysite.example.com).
     * @param  int    $local_port
     * @return array{success: bool, url?: string, mode?: string, error?: string}
     */
    public function start_named(string $token, string $hostname, int $local_port = 80): array {
        if (empty($token) || empty($hostname)) {
            return ['success' => false, 'error' => 'token and hostname are required'];
        }

        if ($this->is_running()) {
            $this->stop();
        }

        $binary = $this->ensure_binary();
        if (!$binary['success']) {
            return $binary;
        }

        update_option(self::OPTION_TOKEN,    $token);
        update_option(self::OPTION_HOSTNAME, $hostname);

        $log_file = $this->log_dir . '/tunnel-named.log';
        $cmd      = [$this->binary_path, 'tunnel', '--no-autoupdate', 'run', '--token', $token];

        $pid = $this->spawn_background($cmd, $log_file);
        if ($pid === null) {
            return ['success' => false, 'error' => 'Failed to start cloudflared tunnel process'];
        }

        $url = 'https://' . $hostname;

        update_option(self::OPTION_PID,     $pid);
        update_option(self::OPTION_URL,     $url);
        update_option(self::OPTION_MODE,    'named');
        update_option(self::OPTION_ENABLED, '1');

        AuditLog::log('tunnel_started', 'hosting', 0, [
            'mode'     => 'named',
            'hostname' => $hostname,
            'pid'      => $pid,
        ], 2);

        return ['success' => true, 'url' => $url, 'mode' => 'named', 'pid' => $pid, 'hostname' => $hostname];
    }

    /**
     * Stop the running tunnel process.
     */
    public function stop(): array {
        $pid = (int) get_option(self::OPTION_PID, 0);

        if ($pid > 0) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /PID {$pid}");
            } else {
                posix_kill($pid, SIGTERM);
                sleep(1);
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                }
            }
        }

        update_option(self::OPTION_PID,     0);
        update_option(self::OPTION_ENABLED, '0');

        AuditLog::log('tunnel_stopped', 'hosting', 0, ['pid' => $pid], 2);

        return ['success' => true, 'stopped_pid' => $pid];
    }

    /**
     * Return current tunnel status.
     *
     * @return array{running: bool, url: string, mode: string, pid: int, uptime?: string}
     */
    public function status(): array {
        $pid     = (int) get_option(self::OPTION_PID, 0);
        $running = $this->is_running();
        $url     = (string) get_option(self::OPTION_URL, '');
        $mode    = (string) get_option(self::OPTION_MODE, 'quick');

        // If process is gone but option says it was running, clean up
        if (!$running && $pid > 0) {
            update_option(self::OPTION_PID, 0);
            update_option(self::OPTION_ENABLED, '0');
        }

        return [
            'running'         => $running,
            'url'             => $url,
            'mode'            => $mode,
            'pid'             => $running ? $pid : 0,
            'binary_present'  => file_exists($this->binary_path),
            'binary_path'     => $this->binary_path,
            'os_arch'         => $this->os_arch(),
            'hostname'        => (string) get_option(self::OPTION_HOSTNAME, ''),
        ];
    }

    /**
     * Apply the tunnel URL to WordPress site options so links work correctly.
     * Also writes HTTP → HTTPS redirect rule.
     *
     * @param  string $url  Full tunnel URL (https://xxxxx.trycloudflare.com)
     */
    public function apply_to_wordpress(string $url): array {
        $url   = rtrim(esc_url_raw($url), '/');
        $old_home    = get_option('home');
        $old_siteurl = get_option('siteurl');

        update_option('siteurl', $url);
        update_option('home',    $url);

        AuditLog::log('tunnel_apply_wp_url', 'hosting', 0, [
            'new_url'    => $url,
            'old_home'   => $old_home,
            'old_siteurl'=> $old_siteurl,
        ], 3);

        return [
            'success'     => true,
            'url'         => $url,
            'old_siteurl' => $old_siteurl,
            'old_home'    => $old_home,
        ];
    }

    /**
     * Revert WordPress URLs to the original local values.
     */
    public function revert_wordpress_urls(): array {
        $original = (string) get_option('rjv_agi_tunnel_original_url', '');
        if ($original !== '') {
            update_option('siteurl', $original);
            update_option('home',    $original);
        }
        return ['success' => true, 'reverted_to' => $original];
    }

    /**
     * Download the cloudflared binary for the current OS/architecture.
     */
    public function download_binary(): array {
        $arch     = $this->os_arch();
        $url      = self::BINARY_URLS[$arch] ?? null;

        if ($url === null) {
            return ['success' => false, 'error' => "No cloudflared binary available for: {$arch}"];
        }

        if (!is_writable($this->log_dir)) {
            return ['success' => false, 'error' => 'Tunnel directory is not writable: ' . $this->log_dir];
        }

        $response = wp_remote_get($url, ['timeout' => 120, 'stream' => true, 'filename' => $this->binary_path]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['success' => false, 'error' => "Download failed with HTTP {$code}"];
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($this->binary_path, 0755);
        }

        return ['success' => true, 'binary' => $this->binary_path, 'arch' => $arch];
    }

    /**
     * Read the last N lines of the tunnel log.
     */
    public function read_log(int $lines = 100): array {
        $file = $this->log_dir . '/tunnel.log';
        if (!file_exists($file)) {
            $file = $this->log_dir . '/tunnel-named.log';
        }
        if (!file_exists($file)) {
            return ['lines' => [], 'file' => $file];
        }

        $all   = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail  = array_slice($all, -$lines);

        return ['lines' => $tail, 'file' => $file, 'total_lines' => count($all)];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Ensure binary exists, downloading if necessary. */
    private function ensure_binary(): array {
        if (file_exists($this->binary_path) && is_executable($this->binary_path)) {
            return ['success' => true];
        }
        return $this->download_binary();
    }

    /** Is the tunnel process currently running? */
    private function is_running(): bool {
        $pid     = (int) get_option(self::OPTION_PID, 0);
        $enabled = get_option(self::OPTION_ENABLED, '0');

        if ($pid <= 0 || $enabled !== '1') {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $output);
            return !empty($output) && str_contains(implode('', $output), (string) $pid);
        }

        return file_exists("/proc/{$pid}") || (function_exists('posix_kill') && posix_kill($pid, 0));
    }

    /**
     * Spawn a background process and return its PID.
     *
     * @param  string[] $cmd
     */
    private function spawn_background(array $cmd, string $log_file): ?int {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use wmic to get PID
            $proc = proc_open(
                array_merge(['cmd', '/c', 'start', '/B'], $cmd, ['>', $log_file, '2>&1']),
                [0 => ['pipe', 'r'], 1 => ['file', $log_file, 'a'], 2 => ['file', $log_file, 'a']],
                $pipes
            );
        } else {
            $proc = proc_open(
                $cmd,
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $log_file, 'a'],
                    2 => ['file', $log_file, 'a'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => false]
            );
        }

        if (!is_resource($proc)) {
            return null;
        }

        $status = proc_get_status($proc);
        // Don't close the process handle – leave it detached
        // (proc_close would wait for it to finish)
        return ($status['pid'] ?? null) ? (int) $status['pid'] : null;
    }

    /**
     * Poll the log file until a trycloudflare.com URL appears.
     *
     * @return string URL or empty string on timeout.
     */
    private function wait_for_tunnel_url(string $log_file, int $timeout_seconds): string {
        $deadline = time() + $timeout_seconds;

        while (time() < $deadline) {
            if (file_exists($log_file)) {
                $content = file_get_contents($log_file);
                if ($content !== false) {
                    if (preg_match('~https://[\w-]+\.trycloudflare\.com~', $content, $m)) {
                        return $m[0];
                    }
                }
            }
            sleep(1);
        }

        return '';
    }

    /** Return the current OS + architecture key used for binary URL lookup. */
    private function os_arch(): string {
        $os   = PHP_OS_FAMILY;
        $arch = php_uname('m');

        if ($os === 'Windows') {
            return 'windows_' . (PHP_INT_SIZE === 8 ? 'AMD64' : 'x86');
        }

        return strtolower($os) . '_' . $arch;
    }
}
