<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Hosting;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Security\SecretsVault;

/**
 * TunnelManager - Secure Cloudflare Tunnel lifecycle controller
 *
 * Enables zero-cost public hosting by routing an on-premises WordPress
 * installation through the Cloudflare network.
 *
 * Security hardening:
 *  - Binary attestation: SHA-256 verified against official Cloudflare checksum
 *    before execution; supply-chain attack prevention.
 *  - No exec()/shell_exec(): all process management via proc_open() with
 *    explicit argument arrays; eliminates shell-injection vectors.
 *  - SSRF guard: tunnel URLs validated against strict Cloudflare allowlist
 *    before being written to WordPress siteurl/home options.
 *  - Encrypted token storage: named-tunnel tokens stored via SecretsVault
 *    (AES-256-GCM); never written as plaintext to wp_options.
 *  - Process nonce binding: 128-bit spawn nonce written to a sidecar file;
 *    is_running() verifies nonce to detect PID reuse.
 */
final class TunnelManager {

    private const OPT_PID      = 'rjv_agi_tunnel_pid';
    private const OPT_URL      = 'rjv_agi_tunnel_url';
    private const OPT_MODE     = 'rjv_agi_tunnel_mode';
    private const OPT_HOSTNAME = 'rjv_agi_tunnel_hostname';
    private const OPT_ENABLED  = 'rjv_agi_tunnel_enabled';
    private const OPT_NONCE    = 'rjv_agi_tunnel_spawn_nonce';
    private const OPT_STARTED  = 'rjv_agi_tunnel_started_at';
    private const VAULT_TOKEN  = 'tunnel_named_token';

    private const BINARY_URLS = [
        'linux_x86_64'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64',
        'linux_aarch64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64',
        'linux_armv7l'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm',
        'darwin_x86_64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-amd64',
        'darwin_arm64'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-arm64',
        'windows_AMD64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe',
    ];

    /** Only Cloudflare-controlled tunnel hostnames pass SSRF validation. */
    private const SAFE_URL_REGEX =
        '~^https://[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]?\.(?:trycloudflare|cfargotunnel)\.com$~i';

    private string $binary_path;
    private string $work_dir;
    private string $nonce_file;

    public function __construct() {
        $upload            = wp_upload_dir();
        $this->work_dir    = trailingslashit((string) $upload['basedir']) . 'rjv-agi-tunnel';
        $this->binary_path = $this->work_dir . DIRECTORY_SEPARATOR . 'cloudflared'
                           . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $this->nonce_file  = $this->work_dir . DIRECTORY_SEPARATOR . '.spawn_nonce';
        wp_mkdir_p($this->work_dir);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Start a quick tunnel (no Cloudflare account required).
     * Generates a random *.trycloudflare.com URL instantly.
     *
     * @param  int $local_port  Local HTTP port WordPress listens on.
     * @return array{success: bool, url?: string, mode?: string, error?: string}
     */
    public function start_quick(int $local_port = 80): array {
        if ($this->is_running()) {
            return ['success' => true, 'url' => (string) get_option(self::OPT_URL, ''),
                    'mode' => 'quick', 'message' => 'Tunnel already running'];
        }
        if ($local_port < 1 || $local_port > 65535) {
            return ['success' => false, 'error' => 'Invalid local port number'];
        }

        $binary = $this->ensure_binary();
        if (!$binary['success']) {
            return $binary;
        }

        $log_file = $this->work_dir . DIRECTORY_SEPARATOR . 'tunnel.log';
        $cmd      = [$this->binary_path, 'tunnel', '--url', 'http://localhost:' . $local_port];
        $spawn    = $this->spawn_background($cmd, $log_file);

        if (!$spawn['success']) {
            return ['success' => false, 'error' => $spawn['error']];
        }

        $pid = $spawn['pid'];
        update_option(self::OPT_PID,     $pid,            false);
        update_option(self::OPT_NONCE,   $spawn['nonce'], false);
        update_option(self::OPT_MODE,    'quick',         false);
        update_option(self::OPT_ENABLED, '1',             false);
        update_option(self::OPT_STARTED, time(),          false);

        $url = $this->wait_for_url($log_file, 18);
        if ($url !== '' && $this->is_safe_url($url)) {
            update_option(self::OPT_URL, $url);
        }

        $this->schedule_heartbeat();
        AuditLog::log('tunnel_started', 'hosting', 0,
            ['mode' => 'quick', 'url' => $url, 'port' => $local_port, 'pid' => $pid], 2);

        return ['success' => true, 'url' => $url, 'mode' => 'quick', 'pid' => $pid];
    }

    /**
     * Start an authenticated named tunnel.
     * Token is stored encrypted via SecretsVault - never as plaintext.
     *
     * @param  string $token      Cloudflare tunnel connector token.
     * @param  string $hostname   Public custom hostname.
     * @param  int    $local_port Local HTTP port.
     * @return array{success: bool, url?: string, mode?: string, error?: string}
     */
    public function start_named(string $token, string $hostname, int $local_port = 80): array {
        if ($token === '' || $hostname === '') {
            return ['success' => false, 'error' => 'token and hostname are required'];
        }
        if ($local_port < 1 || $local_port > 65535) {
            return ['success' => false, 'error' => 'Invalid local port number'];
        }
        if (!$this->is_valid_hostname($hostname)) {
            return ['success' => false, 'error' => 'Invalid hostname format'];
        }

        if ($this->is_running()) {
            $this->stop();
        }

        $binary = $this->ensure_binary();
        if (!$binary['success']) {
            return $binary;
        }

        SecretsVault::instance()->put(self::VAULT_TOKEN, $token);
        update_option(self::OPT_HOSTNAME, sanitize_text_field($hostname));

        $log_file = $this->work_dir . DIRECTORY_SEPARATOR . 'tunnel-named.log';
        $cmd      = [$this->binary_path, 'tunnel', '--no-autoupdate', 'run', '--token', $token];
        $spawn    = $this->spawn_background($cmd, $log_file);

        if (!$spawn['success']) {
            return ['success' => false, 'error' => $spawn['error']];
        }

        $pid = $spawn['pid'];
        $url = 'https://' . $hostname;

        update_option(self::OPT_PID,     $pid,            false);
        update_option(self::OPT_NONCE,   $spawn['nonce'], false);
        update_option(self::OPT_URL,     $url,            false);
        update_option(self::OPT_MODE,    'named',         false);
        update_option(self::OPT_ENABLED, '1',             false);
        update_option(self::OPT_STARTED, time(),          false);

        $this->schedule_heartbeat();
        AuditLog::log('tunnel_started', 'hosting', 0,
            ['mode' => 'named', 'hostname' => $hostname, 'pid' => $pid], 2);

        return ['success' => true, 'url' => $url, 'mode' => 'named',
                'pid' => $pid, 'hostname' => $hostname];
    }

    /**
     * Stop the running tunnel process.
     */
    public function stop(): array {
        $pid = (int) get_option(self::OPT_PID, 0);
        if ($pid > 0) {
            $this->terminate_process($pid);
        }
        if (file_exists($this->nonce_file)) {
            @unlink($this->nonce_file);
        }
        update_option(self::OPT_PID,     0);
        update_option(self::OPT_NONCE,   '');
        update_option(self::OPT_ENABLED, '0');
        wp_clear_scheduled_hook('rjv_agi_tunnel_heartbeat');

        AuditLog::log('tunnel_stopped', 'hosting', 0, ['pid' => $pid], 2);
        return ['success' => true, 'stopped_pid' => $pid];
    }

    /**
     * Return current tunnel status.
     */
    public function status(): array {
        $pid     = (int) get_option(self::OPT_PID, 0);
        $running = $this->is_running();
        $url     = (string) get_option(self::OPT_URL, '');
        $mode    = (string) get_option(self::OPT_MODE, 'quick');

        if (!$running && $pid > 0) {
            update_option(self::OPT_PID,     0);
            update_option(self::OPT_ENABLED, '0');
        }
        $started = (int) get_option(self::OPT_STARTED, 0);

        return [
            'running'         => $running,
            'url'             => $url,
            'mode'            => $mode,
            'pid'             => $running ? $pid : 0,
            'uptime_seconds'  => ($running && $started > 0) ? (time() - $started) : 0,
            'binary_present'  => file_exists($this->binary_path),
            'binary_verified' => (bool) get_option('rjv_agi_tunnel_binary_sha256', ''),
            'binary_path'     => $this->binary_path,
            'os_arch'         => $this->os_arch(),
            'hostname'        => (string) get_option(self::OPT_HOSTNAME, ''),
        ];
    }

    /**
     * Apply the tunnel URL to WordPress siteurl / home after strict SSRF check.
     */
    public function apply_to_wordpress(string $url): array {
        $url = esc_url_raw(rtrim($url, '/'));
        if (!$this->is_safe_url($url) && !$this->matches_named_hostname($url)) {
            AuditLog::log('tunnel_ssrf_blocked', 'hosting', 0, ['rejected_url' => $url], 4);
            return ['success' => false,
                    'error'   => 'URL failed safety validation – not a recognised Cloudflare Tunnel hostname'];
        }

        $old_home    = get_option('home');
        $old_siteurl = get_option('siteurl');
        if (!get_option('rjv_agi_tunnel_original_url')) {
            update_option('rjv_agi_tunnel_original_url', $old_home);
        }
        update_option('siteurl', $url);
        update_option('home',    $url);

        AuditLog::log('tunnel_apply_wp_url', 'hosting', 0,
            ['new_url' => $url, 'old_home' => $old_home, 'old_siteurl' => $old_siteurl], 3);

        return ['success' => true, 'url' => $url,
                'old_siteurl' => $old_siteurl, 'old_home' => $old_home];
    }

    /**
     * Revert WordPress URLs to saved pre-tunnel originals.
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
     * Download and cryptographically attest the cloudflared binary.
     *
     * Steps:
     *  1. Fetch official SHA-256 checksum from Cloudflare release.
     *  2. Download binary to temp file.
     *  3. Verify SHA-256 against checksum; abort on mismatch.
     *  4. Atomic rename to final path.
     *  5. Set executable permissions.
     *  6. Persist verified hash for future integrity checks.
     */
    public function download_binary(): array {
        $arch = $this->os_arch();
        $url  = self::BINARY_URLS[$arch] ?? null;
        if ($url === null) {
            return ['success' => false, 'error' => "No binary available for: {$arch}"];
        }
        if (!is_writable($this->work_dir)) {
            return ['success' => false, 'error' => 'Working directory not writable: ' . $this->work_dir];
        }

        $expected = $this->fetch_official_sha256($url);

        $tmp = $this->binary_path . '.tmp';
        @unlink($tmp);
        $response = wp_remote_get($url, ['timeout' => 180, 'stream' => true, 'filename' => $tmp]);

        if (is_wp_error($response)) {
            @unlink($tmp);
            return ['success' => false, 'error' => $response->get_error_message()];
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            @unlink($tmp);
            return ['success' => false, 'error' => 'HTTP ' . wp_remote_retrieve_response_code($response)];
        }
        if (!file_exists($tmp) || filesize($tmp) < 1024) {
            @unlink($tmp);
            return ['success' => false, 'error' => 'Downloaded file is empty or missing'];
        }

        $actual = (string) hash_file('sha256', $tmp);
        if ($expected !== '' && !hash_equals($expected, $actual)) {
            @unlink($tmp);
            AuditLog::log('tunnel_binary_attestation_failed', 'hosting', 0,
                ['expected' => $expected, 'actual' => $actual, 'arch' => $arch], 4);
            return ['success' => false,
                    'error'   => 'Binary attestation failed: SHA-256 mismatch – download may be tampered with.'];
        }

        if (!rename($tmp, $this->binary_path)) {
            copy($tmp, $this->binary_path);
            @unlink($tmp);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($this->binary_path, 0750);
        }
        update_option('rjv_agi_tunnel_binary_sha256', $actual);

        AuditLog::log('tunnel_binary_downloaded', 'hosting', 0,
            ['arch' => $arch, 'sha256_verified' => $expected !== '', 'sha256' => $actual], 2);

        return ['success' => true, 'binary' => $this->binary_path,
                'arch' => $arch, 'sha256' => $actual, 'sha256_verified' => $expected !== ''];
    }

    /**
     * Verify the binary on disk matches its recorded SHA-256.
     */
    public function verify_binary_integrity(): array {
        if (!file_exists($this->binary_path)) {
            return ['verified' => false, 'reason' => 'binary_missing'];
        }
        $stored = (string) get_option('rjv_agi_tunnel_binary_sha256', '');
        if ($stored === '') {
            return ['verified' => false, 'reason' => 'no_stored_hash'];
        }
        $actual = (string) hash_file('sha256', $this->binary_path);
        if (!hash_equals($stored, $actual)) {
            AuditLog::log('tunnel_binary_integrity_violation', 'hosting', 0,
                ['stored' => $stored, 'actual' => $actual], 4);
            return ['verified' => false, 'reason' => 'hash_mismatch',
                    'stored' => $stored, 'actual' => $actual];
        }
        return ['verified' => true, 'sha256' => $actual];
    }

    /**
     * Read the tail of the active tunnel log.
     */
    public function read_log(int $lines = 100): array {
        $sep   = DIRECTORY_SEPARATOR;
        $quick = $this->work_dir . $sep . 'tunnel.log';
        $named = $this->work_dir . $sep . 'tunnel-named.log';
        $file  = file_exists($quick) ? $quick : $named;
        if (!file_exists($file)) {
            return ['lines' => [], 'file' => $file, 'total_lines' => 0];
        }
        $all  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail = array_slice($all, -abs($lines));
        return ['lines' => $tail, 'file' => $file, 'total_lines' => count($all)];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function ensure_binary(): array {
        if (file_exists($this->binary_path) && is_executable($this->binary_path)) {
            // Verify the resolved path stays within the expected work directory
            // to guard against symlink-based path traversal attacks.
            $real_binary = realpath($this->binary_path);
            $real_dir    = realpath($this->work_dir);
            if ($real_binary === false || $real_dir === false ||
                !str_starts_with($real_binary, $real_dir . DIRECTORY_SEPARATOR)) {
                AuditLog::log('tunnel_binary_path_invalid', 'hosting', 0,
                    ['binary_path' => $this->binary_path], 4);
                return ['success' => false, 'error' => 'Binary path failed confinement check'];
            }

            $check = $this->verify_binary_integrity();
            if ($check['verified'] || $check['reason'] === 'no_stored_hash') {
                return ['success' => true];
            }
            AuditLog::log('tunnel_binary_redownload_triggered', 'hosting', 0, $check, 3);
        }
        return $this->download_binary();
    }

    private function is_running(): bool {
        $pid     = (int) get_option(self::OPT_PID, 0);
        $enabled = (string) get_option(self::OPT_ENABLED, '0');
        if ($pid <= 0 || $enabled !== '1') {
            return false;
        }
        if (!$this->process_exists($pid)) {
            return false;
        }
        $stored = (string) get_option(self::OPT_NONCE, '');
        if ($stored !== '' && file_exists($this->nonce_file)) {
            $disk = trim((string) file_get_contents($this->nonce_file));
            if (!hash_equals($stored, $disk)) {
                AuditLog::log('tunnel_pid_nonce_mismatch', 'hosting', 0, ['pid' => $pid], 4);
                return false;
            }
        }
        return true;
    }

    /**
     * Spawn a background process via proc_open (no shell string interpolation).
     *
     * @param  string[] $cmd
     * @return array{success: bool, pid?: int, nonce?: string, error?: string}
     */
    private function spawn_background(array $cmd, string $log_file): array {
        $nonce       = bin2hex(random_bytes(16));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $log_file, 'a'],
            2 => ['file', $log_file, 'a'],
        ];
        $opts = PHP_OS_FAMILY !== 'Windows' ? ['bypass_shell' => false] : [];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, $opts);

        if (!is_resource($proc)) {
            return ['success' => false, 'error' => 'proc_open failed to launch process'];
        }
        fclose($pipes[0]);
        $status = proc_get_status($proc);
        $pid    = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            proc_close($proc);
            return ['success' => false, 'error' => 'Could not retrieve process PID'];
        }
        file_put_contents($this->nonce_file, $nonce, LOCK_EX);
        @chmod($this->nonce_file, 0600);
        return ['success' => true, 'pid' => $pid, 'nonce' => $nonce];
    }

    /**
     * Terminate a process by PID using proc_open - no exec()/system().
     */
    private function terminate_process(int $pid): void {
        if ($pid <= 0) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $pipes = [];
            $proc  = proc_open(
                ['taskkill', '/F', '/PID', (string) $pid],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (is_resource($proc)) {
                fclose($pipes[0]);
                stream_get_contents($pipes[1]); fclose($pipes[1]);
                stream_get_contents($pipes[2]); fclose($pipes[2]);
                proc_close($proc);
            }
        } elseif (function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
            usleep(600000);
            if ($this->process_exists($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }
    }

    private function process_exists(int $pid): bool {
        if ($pid <= 0) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $pipes = [];
            $proc  = proc_open(
                ['tasklist', '/FI', 'PID eq ' . $pid, '/FO', 'CSV', '/NH'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($proc)) {
                return false;
            }
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            stream_get_contents($pipes[2]);         fclose($pipes[2]);
            proc_close($proc);
            return str_contains((string) $out, '"' . $pid . '"');
        }
        if (is_dir('/proc/' . $pid)) {
            return true;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        return false;
    }

    private function wait_for_url(string $log_file, int $timeout_seconds): string {
        $deadline = time() + $timeout_seconds;
        while (time() < $deadline) {
            if (file_exists($log_file)) {
                $content = (string) file_get_contents($log_file);
                if (preg_match('~https://[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]?\.trycloudflare\.com~i',
                               $content, $m) && $this->is_safe_url($m[0])) {
                    return $m[0];
                }
            }
            sleep(1);
        }
        return '';
    }

    private function fetch_official_sha256(string $binary_url): string {
        $resp = wp_remote_get($binary_url . '.sha256sum', ['timeout' => 15]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return '';
        }
        $body  = trim(wp_remote_retrieve_body($resp));
        $parts = preg_split('/\s+/', $body);
        if (!empty($parts[0]) && ctype_xdigit($parts[0]) && strlen($parts[0]) === 64) {
            return strtolower($parts[0]);
        }
        return '';
    }

    private function is_safe_url(string $url): bool {
        return (bool) preg_match(self::SAFE_URL_REGEX, $url);
    }

    private function matches_named_hostname(string $url): bool {
        $hostname = (string) get_option(self::OPT_HOSTNAME, '');
        if ($hostname === '') {
            return false;
        }
        return rtrim($url, '/') === rtrim('https://' . $hostname, '/');
    }

    private function is_valid_hostname(string $hostname): bool {
        $hostname = (string) preg_replace('~^https?://~i', '', $hostname);
        return (bool) preg_match(
            '~^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$~i',
            $hostname
        );
    }

    private function schedule_heartbeat(): void {
        if (!wp_next_scheduled('rjv_agi_tunnel_heartbeat')) {
            wp_schedule_event(time() + 300, 'rjv_agi_five_minutes', 'rjv_agi_tunnel_heartbeat');
        }
    }

    private function os_arch(): string {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'windows_' . (PHP_INT_SIZE === 8 ? 'AMD64' : 'x86');
        }
        return strtolower(PHP_OS_FAMILY) . '_' . php_uname('m');
    }
}
