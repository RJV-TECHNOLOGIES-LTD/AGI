<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * File System API
 *
 * Safe, scope-limited access to WordPress file assets:
 *   – Active theme files (read + write)
 *   – Plugin files (read-only for safety)
 *   – Uploads directory (read + delete)
 *   – Diff between two file versions
 *   – Create / delete directories within safe roots
 *   – Simple single-file backup to uploads
 */
class FileSystem extends Base {

    /** Extensions allowed for write operations inside the theme directory. */
    private const WRITABLE_EXTENSIONS = ['css', 'js', 'html', 'json', 'svg', 'txt', 'md', 'xml'];

    /** Extensions blocked unconditionally. */
    private const BLOCKED_EXTENSIONS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'htaccess', 'env'];

    private const MAX_WRITE_BYTES = 2 * MB_IN_BYTES; // 2 MB

    public function register_routes(): void {
        // Theme files
        register_rest_route($this->namespace, '/files/theme', [
            ['methods' => 'GET', 'callback' => [$this, 'list_theme'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/files/theme/read', [
            ['methods' => 'POST', 'callback' => [$this, 'read_theme'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/files/theme/write', [
            ['methods' => 'POST', 'callback' => [$this, 'write_theme'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/files/theme/backup', [
            ['methods' => 'POST', 'callback' => [$this, 'backup_theme_file'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Plugin files (read-only)
        register_rest_route($this->namespace, '/files/plugin', [
            ['methods' => 'GET', 'callback' => [$this, 'list_plugin'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/files/plugin/read', [
            ['methods' => 'POST', 'callback' => [$this, 'read_plugin'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Uploads
        register_rest_route($this->namespace, '/files/uploads', [
            ['methods' => 'GET', 'callback' => [$this, 'list_uploads'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/files/uploads/delete', [
            ['methods' => 'POST', 'callback' => [$this, 'delete_upload'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);

        // Diff
        register_rest_route($this->namespace, '/files/diff', [
            ['methods' => 'POST', 'callback' => [$this, 'diff'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Directory management (within theme)
        register_rest_route($this->namespace, '/files/theme/mkdir', [
            ['methods' => 'POST', 'callback' => [$this, 'mkdir'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/files/theme/rmdir', [
            ['methods' => 'POST', 'callback' => [$this, 'rmdir_safe'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Theme files
    // -------------------------------------------------------------------------

    public function list_theme(\WP_REST_Request $r): \WP_REST_Response {
        $root  = get_stylesheet_directory();
        $files = $this->scan_dir($root, $root);

        return $this->success([
            'root'       => $root,
            'file_count' => count($files),
            'files'      => $files,
        ]);
    }

    public function read_theme(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $file = sanitize_text_field((string) ($d['file'] ?? ''));

        $result = $this->safe_read($file, get_stylesheet_directory());
        if ($result instanceof \WP_Error) {
            return $result;
        }

        $this->log('read_file', 'filesystem', 0, ['file' => $file, 'root' => 'theme']);
        return $this->success($result);
    }

    public function write_theme(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $file    = sanitize_text_field((string) ($d['file']    ?? ''));
        $content = (string) ($d['content'] ?? '');
        $root    = get_stylesheet_directory();

        $error = $this->validate_write($file, $content, $root);
        if ($error !== null) {
            return $error;
        }

        $full = $this->resolve_path($file, $root);
        if ($full === null) {
            return $this->error('Invalid path', 403);
        }

        if (is_link($full)) {
            return $this->error('Symlinks are not allowed', 403);
        }

        file_put_contents($full, $content, LOCK_EX);

        $this->log('write_file', 'filesystem', 0, ['file' => $file, 'size' => strlen($content)], 3);

        return $this->success([
            'written'  => true,
            'file'     => $file,
            'size'     => strlen($content),
            'checksum' => md5($content),
        ]);
    }

    public function backup_theme_file(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $file = sanitize_text_field((string) ($d['file'] ?? ''));
        $root = get_stylesheet_directory();

        $result = $this->safe_read($file, $root);
        if ($result instanceof \WP_Error) {
            return $result;
        }

        $upload  = wp_upload_dir();
        $bak_dir = trailingslashit((string) $upload['basedir']) . 'rjv-agi-backups';
        wp_mkdir_p($bak_dir);

        $safe_name = str_replace(['/', '\\', ':'], '_', $file);
        $bak_file  = $bak_dir . '/' . gmdate('YmdHis') . '_' . $safe_name;

        file_put_contents($bak_file, $result['content'], LOCK_EX);
        $bak_url = trailingslashit((string) $upload['baseurl']) . 'rjv-agi-backups/' . basename($bak_file);

        $this->log('backup_file', 'filesystem', 0, ['file' => $file, 'backup' => $bak_file], 2);

        return $this->success([
            'backup_file' => $bak_file,
            'backup_url'  => $bak_url,
            'original'    => $file,
            'size'        => strlen($result['content']),
        ]);
    }

    // -------------------------------------------------------------------------
    // Plugin files (read-only)
    // -------------------------------------------------------------------------

    public function list_plugin(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $slug = sanitize_key((string) ($r['plugin'] ?? ''));
        if ($slug === '') {
            // List all available plugins
            $plugins = array_map(
                fn($d): array => ['slug' => $d, 'path' => WP_PLUGIN_DIR . '/' . $d],
                array_filter(array_keys(get_plugins()), fn($k) => !str_contains($k, '/') || explode('/', $k)[0] === explode('/', $k)[0])
            );
            // Simplify: top-level plugin directories
            $dirs = array_filter(scandir(WP_PLUGIN_DIR) ?: [], fn($n) => $n !== '.' && $n !== '..' && is_dir(WP_PLUGIN_DIR . '/' . $n));
            return $this->success(['plugins' => array_values($dirs)]);
        }

        $root  = realpath(WP_PLUGIN_DIR . '/' . $slug);
        if (!$root || !str_starts_with($root, WP_PLUGIN_DIR)) {
            return $this->error('Plugin not found', 404);
        }

        return $this->success([
            'plugin'     => $slug,
            'root'       => $root,
            'files'      => $this->scan_dir($root, $root, ['php', 'js', 'css', 'json', 'txt', 'md']),
        ]);
    }

    public function read_plugin(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $slug = sanitize_key((string) ($d['plugin'] ?? ''));
        $file = sanitize_text_field((string) ($d['file']   ?? ''));

        if ($slug === '') {
            return $this->error('plugin is required');
        }

        $root   = realpath(WP_PLUGIN_DIR . '/' . $slug);
        if (!$root || !str_starts_with($root, WP_PLUGIN_DIR)) {
            return $this->error('Plugin not found', 404);
        }

        $result = $this->safe_read($file, $root);
        if ($result instanceof \WP_Error) {
            return $result;
        }

        $this->log('read_plugin_file', 'filesystem', 0, ['plugin' => $slug, 'file' => $file]);
        return $this->success($result);
    }

    // -------------------------------------------------------------------------
    // Uploads
    // -------------------------------------------------------------------------

    public function list_uploads(\WP_REST_Request $r): \WP_REST_Response {
        $upload = wp_upload_dir();
        $root   = (string) ($upload['basedir'] ?? '');
        $year   = sanitize_text_field((string) ($r['year']  ?? gmdate('Y')));
        $month  = sanitize_text_field((string) ($r['month'] ?? ''));

        $subdir = $root . '/' . $year . ($month !== '' ? '/' . $month : '');
        if (!is_dir($subdir)) {
            return $this->success(['files' => [], 'root' => $subdir]);
        }

        $files = $this->scan_dir($subdir, $root);
        return $this->success(['files' => $files, 'root' => $subdir]);
    }

    public function delete_upload(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $file = sanitize_text_field((string) ($d['file'] ?? ''));

        $upload = wp_upload_dir();
        $root   = (string) ($upload['basedir'] ?? '');

        $full = realpath($root . '/' . $file);
        if (!$full || !str_starts_with($full, $root)) {
            return $this->error('Invalid path', 403);
        }
        if (!is_file($full)) {
            return $this->error('File not found', 404);
        }

        unlink($full);

        $this->log('delete_upload', 'filesystem', 0, ['file' => $file], 3);
        return $this->success(['deleted' => true, 'file' => $file]);
    }

    // -------------------------------------------------------------------------
    // Diff
    // -------------------------------------------------------------------------

    public function diff(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $file_a  = sanitize_text_field((string) ($d['file_a'] ?? ''));
        $file_b  = sanitize_text_field((string) ($d['file_b'] ?? ''));
        $root    = get_stylesheet_directory();

        $a = $this->safe_read($file_a, $root);
        if ($a instanceof \WP_Error) {
            return $a;
        }

        // file_b may alternatively be inline content
        if (!empty($d['content_b'])) {
            $content_b = (string) $d['content_b'];
        } else {
            $b = $this->safe_read($file_b, $root);
            if ($b instanceof \WP_Error) {
                return $b;
            }
            $content_b = $b['content'];
        }

        $diff = $this->unified_diff($a['content'], $content_b, $file_a, $file_b ?? 'new');

        return $this->success([
            'file_a'     => $file_a,
            'file_b'     => $file_b ?: 'inline',
            'diff'       => $diff,
            'changed'    => $a['content'] !== $content_b,
        ]);
    }

    // -------------------------------------------------------------------------
    // Directory management (within theme)
    // -------------------------------------------------------------------------

    public function mkdir(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d   = (array) $r->get_json_params();
        $dir = sanitize_text_field((string) ($d['dir'] ?? ''));
        $root = get_stylesheet_directory();

        $full = $root . '/' . ltrim($dir, '/');
        $resolved = dirname($full);

        if (!str_starts_with((string) realpath($root), $root)) {
            return $this->error('Invalid path', 403);
        }

        if (!wp_mkdir_p($full)) {
            return $this->error('Failed to create directory', 500);
        }

        $this->log('mkdir', 'filesystem', 0, ['dir' => $dir], 3);
        return $this->success(['created' => true, 'dir' => $dir]);
    }

    public function rmdir_safe(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $dir  = sanitize_text_field((string) ($d['dir'] ?? ''));
        $root = get_stylesheet_directory();

        $full = realpath($root . '/' . ltrim($dir, '/'));
        if (!$full || !str_starts_with($full, $root) || $full === $root) {
            return $this->error('Invalid path', 403);
        }
        if (!is_dir($full)) {
            return $this->error('Directory not found', 404);
        }

        // Only allow removing empty directories
        $files = scandir($full);
        $non_dots = array_diff($files ?: [], ['.', '..']);
        if (!empty($non_dots)) {
            return $this->error('Directory is not empty; remove files first', 409);
        }

        rmdir($full);

        $this->log('rmdir', 'filesystem', 0, ['dir' => $dir], 3);
        return $this->success(['removed' => true, 'dir' => $dir]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Safely resolve and read a file within $root.
     *
     * @return array{file: string, content: string, size: int, modified: string, checksum: string}|\WP_Error
     */
    private function safe_read(string $relative, string $root): array|\WP_Error {
        if ($relative === '') {
            return $this->error('file is required');
        }

        $full = realpath($root . '/' . $relative);
        if (!$full || !str_starts_with($full, $root)) {
            return $this->error('Invalid or disallowed path', 403);
        }
        if (!is_file($full)) {
            return $this->error('File not found', 404);
        }

        $content = file_get_contents($full);
        if ($content === false) {
            return $this->error('Could not read file', 500);
        }

        return [
            'file'     => $relative,
            'content'  => $content,
            'size'     => strlen($content),
            'modified' => gmdate('c', filemtime($full) ?: 0),
            'checksum' => md5($content),
        ];
    }

    /** Validate a write operation; return \WP_Error or null on success. */
    private function validate_write(string $file, string $content, string $root): ?\WP_Error {
        if ($file === '') {
            return $this->error('file is required');
        }
        if ($content === '') {
            return $this->error('content is required');
        }
        if (strlen($content) > self::MAX_WRITE_BYTES) {
            return $this->error('Content exceeds 2 MB limit', 422);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            return $this->error(".{$ext} files are not writable for security reasons", 403);
        }

        if (!in_array($ext, self::WRITABLE_EXTENSIONS, true)) {
            $allowed = implode(', .', self::WRITABLE_EXTENSIONS);
            return $this->error("File type .{$ext} is not allowed. Allowed: .{$allowed}", 403);
        }

        $full = $this->resolve_path($file, $root);
        if ($full === null) {
            return $this->error('Invalid path', 403);
        }

        return null;
    }

    /**
     * Resolve a relative path against $root and ensure it stays within $root.
     * Creates parent directories as needed.
     *
     * @return string|null Full path, or null on traversal attempt.
     */
    private function resolve_path(string $relative, string $root): ?string {
        $target_dir = dirname($root . '/' . ltrim($relative, '/'));
        if (!is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $resolved_dir = realpath($target_dir);
        if (!$resolved_dir || !str_starts_with($resolved_dir, $root)) {
            return null;
        }

        return $resolved_dir . '/' . basename($relative);
    }

    /**
     * Recursive directory scanner.
     *
     * @param  string   $dir         Directory to scan.
     * @param  string   $base        Root for relative path calculation.
     * @param  string[] $extensions  If non-empty, only these extensions are returned.
     * @return array<int, array{file: string, size: int, ext: string, modified: string}>
     */
    private function scan_dir(string $dir, string $base, array $extensions = []): array {
        if (!is_dir($dir)) {
            return [];
        }

        $results = [];
        $entries = scandir($dir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . '/' . $entry;
            $rel  = ltrim(str_replace($base, '', $full), '/');

            if (is_dir($full)) {
                $results = array_merge($results, $this->scan_dir($full, $base, $extensions));
            } elseif (is_file($full)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!empty($extensions) && !in_array($ext, $extensions, true)) {
                    continue;
                }
                $results[] = [
                    'file'     => $rel,
                    'size'     => filesize($full) ?: 0,
                    'ext'      => $ext,
                    'modified' => gmdate('c', filemtime($full) ?: 0),
                ];
            }
        }

        return $results;
    }

    /**
     * Generate a simple unified diff of two strings.
     */
    private function unified_diff(string $a, string $b, string $name_a = 'a', string $name_b = 'b'): string {
        $lines_a = explode("\n", $a);
        $lines_b = explode("\n", $b);

        $header   = "--- {$name_a}\n+++ {$name_b}\n";
        $diff     = '';
        $max      = max(count($lines_a), count($lines_b));

        for ($i = 0; $i < $max; $i++) {
            $la = $lines_a[$i] ?? null;
            $lb = $lines_b[$i] ?? null;

            if ($la === $lb) {
                $diff .= ' ' . ($la ?? '') . "\n";
            } elseif ($la !== null && $lb !== null) {
                $diff .= '-' . $la . "\n" . '+' . $lb . "\n";
            } elseif ($la !== null) {
                $diff .= '-' . $la . "\n";
            } else {
                $diff .= '+' . ($lb ?? '') . "\n";
            }
        }

        return $diff !== '' ? $header . $diff : '(no changes)';
    }
}
