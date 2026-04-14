<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Execution;

use RJV_AGI_Bridge\AuditLog;

/**
 * Approval Workflow System
 *
 * Critical actions require explicit approval before execution.
 * All actions are previewable and reversible.
 */
final class ApprovalWorkflow {
    private static ?self $instance = null;
    private string $table_name;
    private array $approval_rules = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rjv_agi_approval_queue';
        $this->register_default_rules();
    }

    /**
     * Create approval queue table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rjv_agi_approval_queue';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(100) NOT NULL,
            action_data LONGTEXT NOT NULL,
            preview_data LONGTEXT NULL,
            initiated_by VARCHAR(100) NOT NULL DEFAULT 'agi',
            initiator_type ENUM('agi', 'agent', 'system') NOT NULL DEFAULT 'agi',
            agent_id VARCHAR(100) NULL,
            status ENUM('pending', 'approved', 'rejected', 'expired', 'executed') NOT NULL DEFAULT 'pending',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            requires_role VARCHAR(50) NOT NULL DEFAULT 'administrator',
            expires_at DATETIME NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            rejection_reason VARCHAR(500) NULL,
            executed_at DATETIME NULL,
            execution_result LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_action (action_type),
            INDEX idx_priority (priority),
            INDEX idx_expires (expires_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Register approval rules
     */
    private function register_default_rules(): void {
        // Define which actions require approval and by whom
        $this->approval_rules = [
            'delete_post' => [
                'requires_approval' => true,
                'required_role' => 'editor',
                'expires_in' => 24 * HOUR_IN_SECONDS,
            ],
            'delete_page' => [
                'requires_approval' => true,
                'required_role' => 'editor',
                'expires_in' => 24 * HOUR_IN_SECONDS,
            ],
            'bulk_delete' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 12 * HOUR_IN_SECONDS,
            ],
            'activate_theme' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 48 * HOUR_IN_SECONDS,
            ],
            'toggle_plugin' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 24 * HOUR_IN_SECONDS,
            ],
            'update_user' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 24 * HOUR_IN_SECONDS,
            ],
            'db_query' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 1 * HOUR_IN_SECONDS,
            ],
            'file_write' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 12 * HOUR_IN_SECONDS,
            ],
            'goal_execution' => [
                'requires_approval' => function ($data) {
                    // Only require approval for goals with destructive actions
                    $destructive = ['delete_post', 'delete_page', 'bulk_delete', 'file_write'];
                    foreach ($data['actions'] ?? [] as $action) {
                        if (in_array($action['type'] ?? '', $destructive, true)) {
                            return true;
                        }
                    }
                    return false;
                },
                'required_role' => 'administrator',
                'expires_in' => 24 * HOUR_IN_SECONDS,
            ],
            'policy_guardrail_request' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 6 * HOUR_IN_SECONDS,
            ],
            'policy_escalation_request' => [
                'requires_approval' => true,
                'required_role' => 'administrator',
                'expires_in' => 2 * HOUR_IN_SECONDS,
            ],
        ];
    }

    /**
     * Check if action requires approval
     */
    public function requires_approval(string $action_type, array $data = []): bool {
        if (!isset($this->approval_rules[$action_type])) {
            return false;
        }

        $rule = $this->approval_rules[$action_type];
        $requires = $rule['requires_approval'] ?? false;

        if (is_callable($requires)) {
            return $requires($data);
        }

        return (bool) $requires;
    }

    /**
     * Submit action for approval
     */
    public function submit(
        string $action_type,
        array $action_data,
        string $initiated_by = 'agi',
        string $initiator_type = 'agi',
        ?string $agent_id = null
    ): array {
        global $wpdb;

        $rule = $this->approval_rules[$action_type] ?? [];
        $required_role = $rule['required_role'] ?? 'administrator';
        $expires_in = $rule['expires_in'] ?? 24 * HOUR_IN_SECONDS;

        // Generate preview
        $preview = $this->generate_preview($action_type, $action_data);

        $wpdb->insert($this->table_name, [
            'action_type' => $action_type,
            'action_data' => wp_json_encode($action_data),
            'preview_data' => wp_json_encode($preview),
            'initiated_by' => $initiated_by,
            'initiator_type' => $initiator_type,
            'agent_id' => $agent_id,
            'requires_role' => $required_role,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expires_in),
        ]);

        $approval_id = (int) $wpdb->insert_id;

        AuditLog::log('approval_submitted', 'approval', $approval_id, [
            'action_type' => $action_type,
            'initiated_by' => $initiated_by,
            'initiator_type' => $initiator_type,
        ], 2);

        // Notify administrators
        $this->notify_approvers($approval_id, $action_type, $required_role);

        return [
            'success' => true,
            'approval_id' => $approval_id,
            'status' => 'pending',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expires_in),
            'preview' => $preview,
        ];
    }

    /**
     * Approve a pending action
     */
    public function approve(int $approval_id, int $user_id, bool $auto_execute = true): array {
        global $wpdb;

        $item = $this->get_item($approval_id);
        if (!$item) {
            return ['success' => false, 'error' => 'Approval request not found'];
        }

        if ($item['status'] !== 'pending') {
            return ['success' => false, 'error' => "Cannot approve: status is {$item['status']}"];
        }

        // Check if expired
        if (strtotime($item['expires_at']) < time()) {
            $wpdb->update($this->table_name, ['status' => 'expired'], ['id' => $approval_id]);
            return ['success' => false, 'error' => 'Approval request has expired'];
        }

        // Check user capability
        $user = get_userdata($user_id);
        if (!$user || !user_can($user, $item['requires_role'])) {
            return ['success' => false, 'error' => 'Insufficient permissions to approve'];
        }

        // Update status
        $wpdb->update($this->table_name, [
            'status' => 'approved',
            'approved_by' => $user_id,
            'approved_at' => current_time('mysql', true),
        ], ['id' => $approval_id]);

        AuditLog::log('approval_approved', 'approval', $approval_id, [
            'approved_by' => $user_id,
            'action_type' => $item['action_type'],
        ], 2);

        // Execute if requested
        if ($auto_execute) {
            return $this->execute($approval_id);
        }

        return [
            'success' => true,
            'approval_id' => $approval_id,
            'status' => 'approved',
        ];
    }

    /**
     * Reject a pending action
     */
    public function reject(int $approval_id, int $user_id, ?string $reason = null): array {
        global $wpdb;

        $item = $this->get_item($approval_id);
        if (!$item) {
            return ['success' => false, 'error' => 'Approval request not found'];
        }

        if ($item['status'] !== 'pending') {
            return ['success' => false, 'error' => "Cannot reject: status is {$item['status']}"];
        }

        $wpdb->update($this->table_name, [
            'status' => 'rejected',
            'approved_by' => $user_id,
            'approved_at' => current_time('mysql', true),
            'rejection_reason' => $reason ? sanitize_textarea_field($reason) : null,
        ], ['id' => $approval_id]);

        AuditLog::log('approval_rejected', 'approval', $approval_id, [
            'rejected_by' => $user_id,
            'action_type' => $item['action_type'],
            'reason' => $reason,
        ], 2);

        return [
            'success' => true,
            'approval_id' => $approval_id,
            'status' => 'rejected',
        ];
    }

    /**
     * Execute an approved action
     */
    public function execute(int $approval_id): array {
        global $wpdb;

        $item = $this->get_item($approval_id);
        if (!$item) {
            return ['success' => false, 'error' => 'Approval request not found'];
        }

        if ($item['status'] !== 'approved') {
            return ['success' => false, 'error' => 'Action must be approved before execution'];
        }

        $action_data = is_array($item['action_data'])
            ? $item['action_data']
            : (json_decode((string) $item['action_data'], true) ?: []);
        $result = $this->execute_action($item['action_type'], $action_data);

        $wpdb->update($this->table_name, [
            'status' => 'executed',
            'executed_at' => current_time('mysql', true),
            'execution_result' => wp_json_encode($result),
        ], ['id' => $approval_id]);

        AuditLog::log('approval_executed', 'approval', $approval_id, [
            'action_type' => $item['action_type'],
            'success' => $result['success'] ?? false,
        ], 2);

        return [
            'success' => $result['success'] ?? false,
            'approval_id' => $approval_id,
            'result' => $result,
        ];
    }

    /**
     * Get pending approvals
     */
    public function get_pending(array $filters = []): array {
        global $wpdb;

        $where = ['status = %s'];
        $params = ['pending'];

        if (!empty($filters['action_type'])) {
            $where[] = 'action_type = %s';
            $params[] = $filters['action_type'];
        }

        if (!empty($filters['initiator_type'])) {
            $where[] = 'initiator_type = %s';
            $params[] = $filters['initiator_type'];
        }

        $where[] = 'expires_at > %s';
        $params[] = current_time('mysql', true);

        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $params[] = $limit;

        $sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' AND ', $where) . " ORDER BY priority DESC, created_at ASC LIMIT %d";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        return array_map(function ($row) {
            $row['action_data'] = json_decode($row['action_data'], true);
            $row['preview_data'] = json_decode($row['preview_data'], true);
            return $row;
        }, $results);
    }

    /**
     * Get approval item by ID
     */
    public function get_item(int $approval_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $approval_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['action_data'] = json_decode($row['action_data'], true);
        $row['preview_data'] = json_decode($row['preview_data'], true);
        $row['execution_result'] = $row['execution_result'] ? json_decode($row['execution_result'], true) : null;
        return $row;
    }

    /**
     * Generate action preview
     */
    private function generate_preview(string $action_type, array $action_data): array {
        $preview = [
            'action_type' => $action_type,
            'summary' => '',
            'details' => [],
            'affected_resources' => [],
            'risks' => [],
        ];

        switch ($action_type) {
            case 'delete_post':
            case 'delete_page':
                $post = get_post($action_data['id'] ?? 0);
                if ($post) {
                    $preview['summary'] = "Delete {$post->post_type}: \"{$post->post_title}\"";
                    $preview['affected_resources'][] = [
                        'type' => $post->post_type,
                        'id' => $post->ID,
                        'title' => $post->post_title,
                    ];
                    $preview['risks'][] = 'Content will be permanently deleted if forced';
                }
                break;

            case 'bulk_delete':
                $ids = $action_data['ids'] ?? [];
                $preview['summary'] = "Bulk delete " . count($ids) . " items";
                $preview['risks'][] = 'Multiple items will be affected';
                break;

            case 'activate_theme':
                $theme = wp_get_theme($action_data['slug'] ?? '');
                $preview['summary'] = "Activate theme: " . ($theme->exists() ? $theme->get('Name') : $action_data['slug']);
                $preview['risks'][] = 'Theme change may affect site appearance';
                break;

            case 'toggle_plugin':
                $preview['summary'] = ucfirst($action_data['action'] ?? 'toggle') . " plugin: " . ($action_data['plugin'] ?? '');
                $preview['risks'][] = 'Plugin state change may affect site functionality';
                break;

            case 'file_write':
                $preview['summary'] = "Write to file: " . ($action_data['file'] ?? '');
                $preview['details']['content_length'] = strlen($action_data['content'] ?? '');
                $preview['risks'][] = 'File modification may affect site functionality';
                break;

            case 'goal_execution':
                $preview['summary'] = "Execute goal: " . ($action_data['objective'] ?? '');
                $preview['details']['actions_count'] = count($action_data['actions'] ?? []);
                break;

            case 'policy_guardrail_request':
                $preview['summary'] = 'Execute policy-guarded request';
                $preview['details'] = [
                    'method' => sanitize_text_field((string) ($action_data['method'] ?? '')),
                    'route' => sanitize_text_field((string) ($action_data['route'] ?? '')),
                    'trace_id' => sanitize_text_field((string) ($action_data['trace_id'] ?? '')),
                ];
                $preview['risks'][] = 'Guardrail-protected API request requires explicit execution handoff';
                break;

            case 'policy_escalation_request':
                $preview['summary'] = 'Execute policy-escalated request';
                $preview['details'] = [
                    'method' => sanitize_text_field((string) ($action_data['method'] ?? '')),
                    'route' => sanitize_text_field((string) ($action_data['route'] ?? '')),
                    'trace_id' => sanitize_text_field((string) ($action_data['trace_id'] ?? '')),
                    'rule_id' => sanitize_text_field((string) ($action_data['rule_id'] ?? '')),
                    'policy_reason' => sanitize_text_field((string) ($action_data['policy_reason'] ?? '')),
                ];
                $preview['risks'][] = 'Escalation-level operation requires administrative sign-off and replay context';
                break;
        }

        return $preview;
    }

    /**
     * Execute action based on type
     */
    private function execute_action(string $action_type, array $action_data): array {
        $executor = GoalExecutor::instance();

        switch ($action_type) {
            case 'delete_post':
            case 'delete_page':
                $result = wp_delete_post((int) ($action_data['id'] ?? 0), $action_data['force'] ?? false);
                return ['success' => $result !== false];

            case 'bulk_delete':
                $deleted = 0;
                foreach ($action_data['ids'] ?? [] as $id) {
                    if (wp_delete_post((int) $id, $action_data['force'] ?? false)) {
                        $deleted++;
                    }
                }
                return ['success' => true, 'deleted' => $deleted];

            case 'activate_theme':
                $theme = wp_get_theme($action_data['slug'] ?? '');
                if (!$theme->exists()) {
                    return ['success' => false, 'error' => 'Theme not found'];
                }
                switch_theme($action_data['slug']);
                return ['success' => true];

            case 'toggle_plugin':
                if (!function_exists('activate_plugin')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                if ($action_data['action'] === 'activate') {
                    $result = activate_plugin($action_data['plugin']);
                    return ['success' => !is_wp_error($result), 'error' => is_wp_error($result) ? $result->get_error_message() : null];
                } else {
                    deactivate_plugins($action_data['plugin']);
                    return ['success' => true];
                }

            case 'goal_execution':
                return $executor->execute($action_data);

            case 'policy_guardrail_request':
                return [
                    'success' => true,
                    'approved' => true,
                    'handoff_required' => true,
                    'message' => 'Request approved. Re-submit original request with approval context.',
                ];

            case 'policy_escalation_request':
                return [
                    'success' => true,
                    'approved' => true,
                    'handoff_required' => true,
                    'escalated' => true,
                    'message' => 'Escalated request approved. Re-submit original request with approval context.',
                ];

            default:
                return ['success' => false, 'error' => "Unknown action type: {$action_type}"];
        }
    }

    /**
     * Notify approvers of pending action
     */
    private function notify_approvers(int $approval_id, string $action_type, string $required_role): void {
        // Send email to users with the required role
        $users = get_users(['role' => $required_role, 'number' => 10]);
        $admin_url = admin_url("admin.php?page=rjv-agi-approvals&action=view&id={$approval_id}");

        foreach ($users as $user) {
            $subject = sprintf(
                '[%s] Action Awaiting Approval: %s',
                get_bloginfo('name'),
                ucwords(str_replace('_', ' ', $action_type))
            );

            $message = sprintf(
                "An action requires your approval.\n\nAction: %s\nApproval ID: %d\n\nReview and approve at:\n%s",
                ucwords(str_replace('_', ' ', $action_type)),
                $approval_id,
                $admin_url
            );

            wp_mail($user->user_email, $subject, $message);
        }
    }

    /**
     * Cleanup expired approvals
     */
    public function cleanup(): int {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} SET status = 'expired' WHERE status = 'pending' AND expires_at < %s",
            current_time('mysql', true)
        ));
    }

    /**
     * Add custom approval rule
     */
    public function add_rule(string $action_type, array $rule): void {
        $this->approval_rules[$action_type] = $rule;
    }
}
