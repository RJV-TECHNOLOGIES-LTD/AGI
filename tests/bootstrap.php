<?php
/**
 * PHPUnit bootstrap for RJV AGI Bridge.
 *
 * These tests operate outside of WordPress, so we define lightweight stubs
 * for the WP functions and constants that the classes under test reference.
 * Full integration tests require a WordPress test suite (wp-phpunit/wp-phpunit).
 */

declare(strict_types=1);

// ── Constants ──────────────────────────────────────────────────────────────────

define('ABSPATH', dirname(__DIR__) . '/');
define('RJV_AGI_VERSION', '3.2.0');
define('RJV_AGI_PLUGIN_DIR', dirname(__DIR__) . '/');

if (!defined('RJV_AGI_LOG_TABLE')) {
    define('RJV_AGI_LOG_TABLE', 'rjv_agi_audit_log');
}

if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'unit-test-auth-key-not-for-production');
}

if (!defined('SECURE_AUTH_KEY')) {
    define('SECURE_AUTH_KEY', 'unit-test-secure-auth-key');
}

// ── WordPress function stubs ───────────────────────────────────────────────────

$GLOBALS['_options'] = [];

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed {
        return $GLOBALS['_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool {
        $GLOBALS['_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        unset($GLOBALS['_options'][$option]);
        return true;
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url(): string { return 'https://test.example.com'; }
}

if (!function_exists('site_url')) {
    function site_url(): string { return 'https://test.example.com'; }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string { return trim(strip_tags($str)); }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0): string|false { return json_encode($data, $flags); }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true): string {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool { return false; }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed { return is_string($value) ? stripslashes($value) : $value; }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string { return filter_var($url, FILTER_SANITIZE_URL) ?: ''; }
}

if (!function_exists('add_action')) {
    function add_action(string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1): true { return true; }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1): true { return true; }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl(): bool { return false; }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed {
        return parse_url($url, $component);
    }
}

// ── Autoloader ────────────────────────────────────────────────────────────────

spl_autoload_register(function (string $class): void {
    $prefix = 'RJV_AGI_Bridge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = RJV_AGI_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
