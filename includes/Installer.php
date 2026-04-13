<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

use RJV_AGI_Bridge\Content\VersionManager;
use RJV_AGI_Bridge\Execution\ApprovalWorkflow;
use RJV_AGI_Bridge\Agent\AgentRuntime;
use RJV_AGI_Bridge\Security\SecurityMonitor;
use RJV_AGI_Bridge\Integration\IntegrationManager;
use RJV_AGI_Bridge\Integration\WebhookManager;

class Installer {
    public static function activate(): void {
        self::create_tables();
        self::set_defaults();
        update_option('rjv_agi_version', RJV_AGI_VERSION);
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('rjv_agi_log_cleanup');
        wp_clear_scheduled_hook('rjv_agi_version_cleanup');
        wp_clear_scheduled_hook('rjv_agi_approval_cleanup');
        wp_clear_scheduled_hook('rjv_agi_platform_heartbeat');
        wp_clear_scheduled_hook('rjv_agi_security_scan');
        flush_rewrite_rules();
    }

    public static function maybe_upgrade(): void {
        $current = get_option('rjv_agi_version', '0.0.0');
        if (version_compare($current, RJV_AGI_VERSION, '>=')) {
            return;
        }
        self::create_tables();
        update_option('rjv_agi_version', RJV_AGI_VERSION);
    }

    private static function create_tables(): void {
        // Core audit log table
        self::create_audit_log_table();

        // Enterprise module tables
        self::create_enterprise_tables();
    }

    private static function create_audit_log_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . RJV_AGI_LOG_TABLE;
        $c = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            agent_id VARCHAR(100) NOT NULL DEFAULT '',
            action VARCHAR(200) NOT NULL,
            resource_type VARCHAR(100) NOT NULL DEFAULT '',
            resource_id BIGINT UNSIGNED NULL,
            details LONGTEXT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            execution_time_ms INT UNSIGNED NULL,
            tokens_used INT UNSIGNED NULL,
            model_used VARCHAR(100) NULL,
            INDEX idx_ts (timestamp),
            INDEX idx_agent (agent_id),
            INDEX idx_action (action),
            INDEX idx_tier (tier)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_enterprise_tables(): void {
        // Content versioning table
        VersionManager::create_table();

        // Approval workflow table
        ApprovalWorkflow::create_table();

        // Agent runtime table
        AgentRuntime::create_table();

        // Security monitoring table
        SecurityMonitor::create_table();

        // Integration management table
        IntegrationManager::create_table();

        // Webhook management table
        WebhookManager::create_table();
    }

    private static function set_defaults(): void {
        $defaults = [
            // API Authentication
            'api_key' => wp_generate_password(64, false),

            // AI Provider Configuration
            'openai_key' => '',
            'anthropic_key' => '',
            'default_model' => 'anthropic',
            'openai_model' => 'gpt-4.1-mini',
            'anthropic_model' => 'claude-sonnet-4-20250514',

            // Rate Limiting
            'rate_limit' => 600,

            // Audit & Logging
            'audit_enabled' => '1',
            'log_retention_days' => 90,

            // Security
            'allowed_ips' => '',

            // Platform Connection (Enterprise)
            'platform_url' => 'https://platform.rjvtechnologies.com/api/v1',
            'tenant_id' => '',
            'tenant_secret' => '',

            // Event Streaming
            'event_streaming' => '0',

            // Design System
            'design_system_enabled' => '0',

            // Multi-tenant
            'multi_tenant_enabled' => '0',

            // Performance
            'performance_monitoring' => '1',

            // Security Scanning
            'security_scan_enabled' => '1',
        ];

        foreach ($defaults as $k => $v) {
            if (get_option("rjv_agi_{$k}") === false) {
                update_option("rjv_agi_{$k}", $v);
            }
        }
    }
}
