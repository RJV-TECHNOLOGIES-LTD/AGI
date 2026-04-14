<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

use RJV_AGI_Bridge\Content\VersionManager;
use RJV_AGI_Bridge\Execution\ApprovalWorkflow;
use RJV_AGI_Bridge\Execution\ExecutionLedger;
use RJV_AGI_Bridge\Agent\AgentRuntime;
use RJV_AGI_Bridge\Security\SecurityMonitor;
use RJV_AGI_Bridge\Governance\UpgradeSafety;
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
        $upgrade = UpgradeSafety::instance();
        $run = $upgrade->begin_upgrade($current, RJV_AGI_VERSION);
        if (($run['compatibility']['compatible'] ?? false) !== true) {
            $upgrade->complete_upgrade((string) ($run['id'] ?? ''), false, ['error' => 'Compatibility checks failed']);
            return;
        }
        self::create_tables();
        $upgrade->record_migration((string) $run['id'], 'create_tables', 'completed');
        self::set_defaults();
        $upgrade->record_migration((string) $run['id'], 'set_defaults', 'completed');
        update_option('rjv_agi_version', RJV_AGI_VERSION);
        $upgrade->complete_upgrade((string) $run['id'], true, ['version' => RJV_AGI_VERSION]);
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

        // Deterministic execution ledger table
        ExecutionLedger::create_table();

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

            // Enterprise Program Controls
            'program_scope_taxonomy' => [
                'Core Ops', 'AI Orchestration', 'Security', 'Compliance',
                'Enterprise Integrations', 'Governance', 'Observability', 'Admin UX', 'Platform Controls',
            ],
            'program_targets' => [
                'availability_slo' => 99.9,
                'api_coverage_pct' => 95.0,
                'change_failure_rate_pct' => 5.0,
                'p95_latency_ms' => 800,
                'rollback_readiness_pct' => 100.0,
                'security_patch_sla_hours' => 24,
            ],
            'program_milestones' => [],
            'policy_rules' => [
                'enforcement_enabled' => true,
                'rule_resolution' => 'priority_then_restrictiveness',
                'deny_routes' => [],
                'approval_routes' => ['/rjv-agi/v1/plugins', '/rjv-agi/v1/themes', '/rjv-agi/v1/filesystem', '/rjv-agi/v1/database'],
                'approval_methods' => ['DELETE'],
                'bypass_routes' => ['/rjv-agi/v1/approvals', '/rjv-agi/v1/health'],
                'rules' => [
                    ['id' => 'deny_critical_fs', 'type' => 'deny', 'priority' => 100, 'route_pattern' => '/rjv-agi/v1/filesystem', 'methods' => ['DELETE'], 'reason' => 'Critical filesystem delete denied by default'],
                    ['id' => 'approve_plugins', 'type' => 'approve', 'priority' => 60, 'route_pattern' => '/rjv-agi/v1/plugins*', 'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'], 'reason' => 'Plugin state changes require approval'],
                    ['id' => 'escalate_database', 'type' => 'escalate', 'priority' => 70, 'route_pattern' => '/rjv-agi/v1/database*', 'methods' => ['POST', 'PUT', 'DELETE'], 'reason' => 'Database mutations require escalation'],
                ],
            ],
            'capability_overrides' => [],
            'capability_plan_overrides' => [],
            'api_contract' => [
                'contract_id' => 'rjv-agi-v1',
                'api_version' => 'v1',
                'compatibility_policy' => 'Backward-compatible additive changes only within major version',
                'deprecation_policy' => ['notice_days' => 90, 'sunset_header' => true, 'replacement_required' => true],
            ],
            'api_deprecations' => [],
            'upgrade_history' => [],
            'upgrade_last' => [],
            'upgrade_lock' => ['active' => false, 'until' => ''],
            'threat_model_controls' => [
                'prompt_injection' => ['enabled' => true, 'status' => 'monitoring'],
                'privilege_escalation' => ['enabled' => true, 'status' => 'enforced'],
                'data_exfiltration' => ['enabled' => true, 'status' => 'enforced'],
                'supply_chain' => ['enabled' => true, 'status' => 'monitoring'],
                'audit_tampering' => ['enabled' => true, 'status' => 'enforced'],
            ],
            'compliance_controls' => [
                'retention_days' => 90,
                'legal_hold' => ['enabled' => false, 'reason' => '', 'set_at' => ''],
                'data_residency' => ['region' => 'global', 'strict' => false],
                'exports_enabled' => true,
            ],
            'secret_rotation_log' => [],
            'release_gate_thresholds' => [
                'contract_tests_min' => 95,
                'integration_tests_min' => 90,
                'e2e_tests_min' => 85,
                'load_tests_min' => 90,
                'chaos_tests_min' => 80,
            ],
        ];

        foreach ($defaults as $k => $v) {
            if (get_option("rjv_agi_{$k}") === false) {
                update_option("rjv_agi_{$k}", $v);
            }
        }
    }
}
