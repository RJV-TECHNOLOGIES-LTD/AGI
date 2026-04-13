<?php
/**
 * Plugin Name:       RJV AGI Bridge
 * Plugin URI:        https://rjvtechnologies.com/agi-bridge
 * Description:       Enterprise AGI control interface for WordPress. Full site control via REST API + dual AI (OpenAI + Anthropic).
 * Version:           2.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            RJV Technologies Ltd
 * Author URI:        https://rjvtechnologies.com
 * License:           Proprietary
 * Text Domain:       rjv-agi-bridge
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

define('RJV_AGI_VERSION', '2.1.0');
define('RJV_AGI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RJV_AGI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RJV_AGI_LOG_TABLE', 'rjv_agi_audit_log');

spl_autoload_register(function (string $class): void {
    $prefix = 'RJV_AGI_Bridge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = RJV_AGI_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require_once $file;
});

add_action('plugins_loaded', function(): void {
    require_once RJV_AGI_PLUGIN_DIR . 'includes/Plugin.php';
    RJV_AGI_Bridge\Plugin::instance();
});

register_activation_hook(__FILE__, ['RJV_AGI_Bridge\\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['RJV_AGI_Bridge\\Installer', 'deactivate']);
