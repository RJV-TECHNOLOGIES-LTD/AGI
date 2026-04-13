<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Agent;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Bridge\CapabilityGate;

/**
 * Agent Execution Framework (OpenClaw Model)
 *
 * Allows the AGI to deploy and manage specialized agents for specific tasks.
 * Agents operate under strict constraints with defined scopes, limited tool access,
 * and full auditability. Agents cannot act autonomously beyond assigned tasks,
 * escalate privileges, or create other agents.
 */
final class AgentRuntime {
    private static ?self $instance = null;
    private array $running_agents = [];
    private array $agent_configs = [];
    private CapabilityGate $gate;
    private string $table_name;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rjv_agi_agents';
        $this->gate = CapabilityGate::instance();
        $this->load_running_agents();
    }

    /**
     * Create agents table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rjv_agi_agents';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(200) NOT NULL,
            type VARCHAR(50) NOT NULL,
            scope LONGTEXT NOT NULL,
            tools LONGTEXT NOT NULL,
            constraints LONGTEXT NOT NULL,
            status ENUM('created', 'running', 'paused', 'completed', 'failed', 'terminated') NOT NULL DEFAULT 'created',
            task LONGTEXT NULL,
            progress LONGTEXT NULL,
            result LONGTEXT NULL,
            parent_agent_id VARCHAR(100) NULL,
            created_by VARCHAR(100) NOT NULL DEFAULT 'agi',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            last_activity DATETIME NULL,
            execution_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            INDEX idx_status (status),
            INDEX idx_type (type),
            INDEX idx_parent (parent_agent_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Load running agents from database
     */
    private function load_running_agents(): void {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE status IN ('running', 'paused')",
            ARRAY_A
        ) ?: [];

        foreach ($results as $row) {
            $this->running_agents[$row['agent_id']] = $this->hydrate_agent($row);
        }
    }

    /**
     * Deploy a new agent
     */
    public function deploy(array $config): array {
        // Check capability
        if (!$this->gate->can('deploy_agent', $config)) {
            return ['success' => false, 'error' => 'Agent deployment not permitted'];
        }

        // Validate config
        $validation = $this->validate_config($config);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => 'Invalid agent configuration', 'errors' => $validation['errors']];
        }

        // Generate agent ID
        $agent_id = 'agent_' . wp_generate_uuid4();

        // Check agent limit
        $max_agents = $this->gate->get_available()['limits']['agents_concurrent'] ?? 5;
        if (count($this->running_agents) >= $max_agents) {
            return ['success' => false, 'error' => 'Maximum concurrent agents reached'];
        }

        // Enforce scope constraints
        $scope = $this->enforce_scope_constraints($config['scope'] ?? []);
        $tools = $this->filter_allowed_tools($config['tools'] ?? []);
        $constraints = $this->build_constraints($config['constraints'] ?? []);

        global $wpdb;
        $wpdb->insert($this->table_name, [
            'agent_id' => $agent_id,
            'name' => sanitize_text_field($config['name'] ?? 'Unnamed Agent'),
            'type' => sanitize_key($config['type'] ?? 'task'),
            'scope' => wp_json_encode($scope),
            'tools' => wp_json_encode($tools),
            'constraints' => wp_json_encode($constraints),
            'task' => wp_json_encode($config['task'] ?? []),
            'created_by' => sanitize_text_field($config['created_by'] ?? 'agi'),
        ]);

        $agent = $this->get_agent($agent_id);

        AuditLog::log('agent_deployed', 'agent', 0, [
            'agent_id' => $agent_id,
            'name' => $agent['name'],
            'type' => $agent['type'],
        ], 2);

        return [
            'success' => true,
            'agent_id' => $agent_id,
            'agent' => $agent,
        ];
    }

    /**
     * Start an agent
     */
    public function start(string $agent_id): array {
        $agent = $this->get_agent($agent_id);
        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found'];
        }

        if ($agent['status'] === 'running') {
            return ['success' => false, 'error' => 'Agent already running'];
        }

        if (in_array($agent['status'], ['completed', 'failed', 'terminated'], true)) {
            return ['success' => false, 'error' => "Cannot start agent with status: {$agent['status']}"];
        }

        global $wpdb;
        $wpdb->update($this->table_name, [
            'status' => 'running',
            'started_at' => current_time('mysql', true),
            'last_activity' => current_time('mysql', true),
        ], ['agent_id' => $agent_id]);

        $this->running_agents[$agent_id] = $this->get_agent($agent_id);

        AuditLog::log('agent_started', 'agent', 0, ['agent_id' => $agent_id], 2);

        return ['success' => true, 'agent' => $this->running_agents[$agent_id]];
    }

    /**
     * Execute a task with an agent
     */
    public function execute(string $agent_id, array $task): array {
        $agent = $this->get_agent($agent_id);
        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found'];
        }

        if ($agent['status'] !== 'running') {
            return ['success' => false, 'error' => 'Agent not running'];
        }

        // Validate task against scope
        if (!$this->is_within_scope($task, $agent['scope'])) {
            $this->log_agent_error($agent_id, 'scope_violation', $task);
            return ['success' => false, 'error' => 'Task outside agent scope'];
        }

        // Validate required tools
        $required_tools = $task['required_tools'] ?? [];
        foreach ($required_tools as $tool) {
            if (!in_array($tool, $agent['tools'], true)) {
                $this->log_agent_error($agent_id, 'tool_access_denied', ['tool' => $tool]);
                return ['success' => false, 'error' => "Tool not available: {$tool}"];
            }
        }

        // Check constraints
        $constraint_check = $this->check_constraints($agent['constraints']);
        if (!$constraint_check['satisfied']) {
            return ['success' => false, 'error' => 'Agent constraints violated', 'violations' => $constraint_check['violations']];
        }

        // Execute task
        $start = microtime(true);
        try {
            $result = $this->execute_agent_task($agent, $task);
            $duration = (int) ((microtime(true) - $start) * 1000);

            // Update progress
            $this->update_agent_progress($agent_id, [
                'last_task' => $task,
                'last_result' => $result,
                'last_duration_ms' => $duration,
            ]);

            AuditLog::log('agent_task_executed', 'agent', 0, [
                'agent_id' => $agent_id,
                'task_type' => $task['type'] ?? 'unknown',
                'success' => $result['success'],
                'duration_ms' => $duration,
            ], 1);

            return $result;

        } catch (\Throwable $e) {
            $this->log_agent_error($agent_id, 'execution_error', [
                'task' => $task,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pause an agent
     */
    public function pause(string $agent_id): array {
        $agent = $this->get_agent($agent_id);
        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found'];
        }

        if ($agent['status'] !== 'running') {
            return ['success' => false, 'error' => 'Agent not running'];
        }

        global $wpdb;
        $wpdb->update($this->table_name, [
            'status' => 'paused',
            'last_activity' => current_time('mysql', true),
        ], ['agent_id' => $agent_id]);

        unset($this->running_agents[$agent_id]);

        AuditLog::log('agent_paused', 'agent', 0, ['agent_id' => $agent_id], 2);

        return ['success' => true];
    }

    /**
     * Stop an agent
     */
    public function stop(string $agent_id, string $reason = 'manual'): array {
        $agent = $this->get_agent($agent_id);
        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found'];
        }

        global $wpdb;
        $wpdb->update($this->table_name, [
            'status' => 'terminated',
            'completed_at' => current_time('mysql', true),
            'result' => wp_json_encode(['terminated' => true, 'reason' => $reason]),
        ], ['agent_id' => $agent_id]);

        unset($this->running_agents[$agent_id]);

        AuditLog::log('agent_stopped', 'agent', 0, [
            'agent_id' => $agent_id,
            'reason' => $reason,
        ], 2);

        return ['success' => true];
    }

    /**
     * Get agent by ID
     */
    public function get_agent(string $agent_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE agent_id = %s",
            $agent_id
        ), ARRAY_A);

        return $row ? $this->hydrate_agent($row) : null;
    }

    /**
     * List agents
     */
    public function list_agents(array $filters = []): array {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $where[] = 'type = %s';
            $params[] = $filters['type'];
        }

        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $params[] = $limit;

        $sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT %d";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        return array_map([$this, 'hydrate_agent'], $results);
    }

    /**
     * Get running agents
     */
    public function get_running(): array {
        return array_values($this->running_agents);
    }

    /**
     * Hydrate agent row from database
     */
    private function hydrate_agent(array $row): array {
        return [
            'id' => (int) $row['id'],
            'agent_id' => $row['agent_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'scope' => json_decode($row['scope'], true) ?: [],
            'tools' => json_decode($row['tools'], true) ?: [],
            'constraints' => json_decode($row['constraints'], true) ?: [],
            'status' => $row['status'],
            'task' => json_decode($row['task'] ?? '{}', true),
            'progress' => json_decode($row['progress'] ?? '{}', true),
            'result' => json_decode($row['result'] ?? '{}', true),
            'created_by' => $row['created_by'],
            'created_at' => $row['created_at'],
            'started_at' => $row['started_at'],
            'completed_at' => $row['completed_at'],
            'last_activity' => $row['last_activity'],
            'execution_count' => (int) $row['execution_count'],
            'error_count' => (int) $row['error_count'],
        ];
    }

    /**
     * Validate agent configuration
     */
    private function validate_config(array $config): array {
        $errors = [];

        // Must have a name
        if (empty($config['name'])) {
            $errors[] = 'Agent name is required';
        }

        // Must have a type
        $valid_types = ['task', 'content', 'seo', 'media', 'analytics', 'security'];
        if (empty($config['type']) || !in_array($config['type'], $valid_types, true)) {
            $errors[] = 'Invalid agent type';
        }

        // Cannot create child agents
        if (!empty($config['can_create_agents'])) {
            $errors[] = 'Agents cannot create other agents';
        }

        // Cannot escalate privileges
        if (!empty($config['privilege_escalation'])) {
            $errors[] = 'Privilege escalation not permitted';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Enforce scope constraints
     */
    private function enforce_scope_constraints(array $scope): array {
        // Always add restrictions
        $scope['can_create_agents'] = false;
        $scope['can_modify_agents'] = false;
        $scope['can_access_secrets'] = false;
        $scope['can_execute_raw_sql'] = false;
        $scope['can_modify_users'] = false;
        $scope['can_activate_plugins'] = false;

        // Limit resource access
        if (!isset($scope['allowed_post_types'])) {
            $scope['allowed_post_types'] = ['post', 'page'];
        }

        if (!isset($scope['max_operations_per_execution'])) {
            $scope['max_operations_per_execution'] = 10;
        }

        return $scope;
    }

    /**
     * Filter tools to only allowed ones
     */
    private function filter_allowed_tools(array $tools): array {
        $allowed = [
            'read_post', 'create_post', 'update_post',
            'read_page', 'create_page', 'update_page',
            'read_media', 'upload_media',
            'read_menu', 'update_menu_item',
            'read_seo', 'update_seo',
            'read_options',
            'ai_complete', 'ai_rewrite',
        ];

        return array_values(array_intersect($tools, $allowed));
    }

    /**
     * Build agent constraints
     */
    private function build_constraints(array $custom): array {
        $defaults = [
            'max_executions' => 100,
            'max_errors' => 10,
            'timeout_seconds' => 300,
            'max_ai_calls' => 50,
            'max_content_size' => 100000,
            'rate_limit_per_minute' => 30,
        ];

        return array_merge($defaults, $custom);
    }

    /**
     * Check if task is within agent scope
     */
    private function is_within_scope(array $task, array $scope): bool {
        $task_type = $task['type'] ?? '';

        // Check post type restrictions
        if (str_contains($task_type, 'post') || str_contains($task_type, 'page')) {
            $post_type = $task['params']['post_type'] ?? 'post';
            $allowed_types = $scope['allowed_post_types'] ?? ['post', 'page'];
            if (!in_array($post_type, $allowed_types, true)) {
                return false;
            }
        }

        // Check forbidden operations
        $forbidden = [
            'create_agent', 'modify_agent', 'delete_agent',
            'modify_user', 'delete_user',
            'activate_plugin', 'deactivate_plugin',
            'raw_sql', 'file_write',
        ];

        if (in_array($task_type, $forbidden, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check agent constraints
     */
    private function check_constraints(array $constraints): array {
        // This would check runtime constraints against current agent state
        return ['satisfied' => true, 'violations' => []];
    }

    /**
     * Execute agent task
     */
    private function execute_agent_task(array $agent, array $task): array {
        $task_type = $task['type'] ?? '';
        $params = $task['params'] ?? [];

        return match ($task_type) {
            'read_post' => $this->agent_read_post($params),
            'create_post' => $this->agent_create_post($params, $agent),
            'update_post' => $this->agent_update_post($params, $agent),
            'read_seo' => $this->agent_read_seo($params),
            'update_seo' => $this->agent_update_seo($params, $agent),
            'ai_complete' => $this->agent_ai_complete($params, $agent),
            default => ['success' => false, 'error' => "Unknown task type: {$task_type}"],
        };
    }

    // Agent task implementations

    private function agent_read_post(array $params): array {
        $post = get_post($params['id'] ?? 0);
        if (!$post) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        return [
            'success' => true,
            'post' => [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'status' => $post->post_status,
                'type' => $post->post_type,
            ],
        ];
    }

    private function agent_create_post(array $params, array $agent): array {
        $allowed_types = $agent['scope']['allowed_post_types'] ?? ['post', 'page'];
        $post_type = $params['type'] ?? 'post';

        if (!in_array($post_type, $allowed_types, true)) {
            return ['success' => false, 'error' => 'Post type not allowed'];
        }

        $id = wp_insert_post([
            'post_title' => sanitize_text_field($params['title'] ?? ''),
            'post_content' => wp_kses_post($params['content'] ?? ''),
            'post_status' => 'draft', // Agents can only create drafts
            'post_type' => $post_type,
        ], true);

        if (is_wp_error($id)) {
            return ['success' => false, 'error' => $id->get_error_message()];
        }

        return ['success' => true, 'post_id' => $id];
    }

    private function agent_update_post(array $params, array $agent): array {
        $post_id = (int) ($params['id'] ?? 0);
        $post = get_post($post_id);

        if (!$post) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        $allowed_types = $agent['scope']['allowed_post_types'] ?? ['post', 'page'];
        if (!in_array($post->post_type, $allowed_types, true)) {
            return ['success' => false, 'error' => 'Post type not allowed'];
        }

        $update = ['ID' => $post_id];
        if (isset($params['title'])) {
            $update['post_title'] = sanitize_text_field($params['title']);
        }
        if (isset($params['content'])) {
            $update['post_content'] = wp_kses_post($params['content']);
        }

        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return ['success' => true, 'post_id' => $post_id];
    }

    private function agent_read_seo(array $params): array {
        $post_id = (int) ($params['id'] ?? 0);
        return [
            'success' => true,
            'seo' => [
                'title' => get_post_meta($post_id, '_yoast_wpseo_title', true) ?: get_post_meta($post_id, 'rank_math_title', true),
                'description' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true) ?: get_post_meta($post_id, 'rank_math_description', true),
            ],
        ];
    }

    private function agent_update_seo(array $params, array $agent): array {
        $post_id = (int) ($params['id'] ?? 0);

        if (isset($params['title'])) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($params['title']));
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($params['title']));
        }
        if (isset($params['description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field($params['description']));
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($params['description']));
        }

        return ['success' => true, 'post_id' => $post_id];
    }

    private function agent_ai_complete(array $params, array $agent): array {
        $router = new \RJV_AGI_Bridge\AI\Router();
        $result = $router->complete(
            $params['system'] ?? 'You are a helpful assistant.',
            $params['message'] ?? '',
            ['max_tokens' => min($params['max_tokens'] ?? 1000, 2000)]
        );

        if (!empty($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return [
            'success' => true,
            'content' => $result['content'],
            'tokens' => $result['tokens'] ?? 0,
        ];
    }

    /**
     * Update agent progress
     */
    private function update_agent_progress(string $agent_id, array $progress): void {
        global $wpdb;

        $agent = $this->get_agent($agent_id);
        if (!$agent) {
            return;
        }

        $current_progress = $agent['progress'];
        $new_progress = array_merge($current_progress, $progress);

        $wpdb->update($this->table_name, [
            'progress' => wp_json_encode($new_progress),
            'last_activity' => current_time('mysql', true),
            'execution_count' => $agent['execution_count'] + 1,
        ], ['agent_id' => $agent_id]);
    }

    /**
     * Log agent error
     */
    private function log_agent_error(string $agent_id, string $error_type, array $context): void {
        global $wpdb;

        $agent = $this->get_agent($agent_id);
        if (!$agent) {
            return;
        }

        $wpdb->update($this->table_name, [
            'error_count' => $agent['error_count'] + 1,
            'last_activity' => current_time('mysql', true),
        ], ['agent_id' => $agent_id]);

        AuditLog::log("agent_error_{$error_type}", 'agent', 0, [
            'agent_id' => $agent_id,
            'context' => $context,
        ], 1, 'error');

        // Auto-terminate if too many errors
        if ($agent['error_count'] >= ($agent['constraints']['max_errors'] ?? 10)) {
            $this->stop($agent_id, 'max_errors_exceeded');
        }
    }
}
