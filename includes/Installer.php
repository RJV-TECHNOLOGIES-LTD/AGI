<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

class Installer {
    public static function activate(): void {
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
            INDEX idx_ts (timestamp), INDEX idx_agent (agent_id), INDEX idx_action (action), INDEX idx_tier (tier)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        $defaults = ['api_key'=>wp_generate_password(64,false),'openai_key'=>'','anthropic_key'=>'',
            'default_model'=>'anthropic','openai_model'=>'gpt-4.1-mini','anthropic_model'=>'claude-sonnet-4-20250514',
            'rate_limit'=>600,'audit_enabled'=>'1','allowed_ips'=>''];
        foreach ($defaults as $k=>$v) if(get_option("rjv_agi_{$k}")===false) update_option("rjv_agi_{$k}", $v);
        flush_rewrite_rules();
    }
    public static function deactivate(): void { flush_rewrite_rules(); }
}
