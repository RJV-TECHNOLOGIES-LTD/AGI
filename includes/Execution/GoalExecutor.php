<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Execution;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Bridge\CapabilityGate;
use RJV_AGI_Bridge\Content\VersionManager;
use RJV_AGI_Bridge\Execution\ExecutionLedger;

/**
 * Goal-Based Execution Engine
 *
 * Allows the AGI to define objectives and executes sequences of actions
 * to achieve those objectives under controlled conditions.
 */
final class GoalExecutor {
    private static ?self $instance = null;
    private array $active_goals = [];
    private CapabilityGate $gate;
    private VersionManager $versions;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->gate = CapabilityGate::instance();
        $this->versions = VersionManager::instance();
    }

    /**
     * Execute a goal with its action sequence
     */
    public function execute(array $goal): array {
        $goal_id = $goal['id'] ?? wp_generate_uuid4();
        $objective = $goal['objective'] ?? '';
        $actions = $goal['actions'] ?? [];
        $conditions = $goal['conditions'] ?? [];
        $rollback_on_failure = $goal['rollback_on_failure'] ?? true;

        // Validate goal structure
        if (empty($objective) || empty($actions)) {
            return [
                'success' => false,
                'error' => 'Goal must have an objective and actions',
            ];
        }

        // Check preconditions
        $precondition_check = $this->check_conditions($conditions['pre'] ?? []);
        if (!$precondition_check['satisfied']) {
            return [
                'success' => false,
                'error' => 'Preconditions not satisfied',
                'failed_conditions' => $precondition_check['failed'],
            ];
        }

        // Initialize goal tracking
        $this->active_goals[$goal_id] = [
            'id' => $goal_id,
            'objective' => $objective,
            'status' => 'running',
            'started_at' => gmdate('c'),
            'actions_total' => count($actions),
            'actions_completed' => 0,
            'checkpoints' => [],
        ];

        AuditLog::log('goal_started', 'execution', 0, [
            'goal_id' => $goal_id,
            'objective' => $objective,
            'actions_count' => count($actions),
        ], 2);
        $executionId = ExecutionLedger::instance()->start_execution('goal', (string) $goal_id, $goal, ['trace_id' => (string) ($goal['trace_id'] ?? '')]);

        $results = [];
        $failed_at = null;
        $checkpoints = [];

        // Execute actions in sequence
        foreach ($actions as $index => $action) {
            // Create checkpoint before action
            $checkpoint = $this->create_checkpoint($action);
            $checkpoints[$index] = $checkpoint;
            ExecutionLedger::instance()->append_event($executionId, 'action_checkpoint_created', [
                'action_index' => $index,
                'action_type' => (string) ($action['type'] ?? ''),
            ], ['entity_type' => 'goal', 'entity_id' => (string) $goal_id]);

            // Check if action is permitted
            if (!$this->gate->can($action['type'], $action)) {
                $failed_at = $index;
                $results[$index] = [
                    'success' => false,
                    'error' => 'Action not permitted',
                    'action' => $action['type'],
                ];
                ExecutionLedger::instance()->append_event($executionId, 'action_denied', $results[$index], ['entity_type' => 'goal', 'entity_id' => (string) $goal_id, 'status' => 'failed']);
                break;
            }

            // Execute action
            $result = $this->execute_action($action);
            $results[$index] = $result;
            ExecutionLedger::instance()->append_event($executionId, 'action_executed', [
                'action_index' => $index,
                'action_type' => (string) ($action['type'] ?? ''),
                'result' => $result,
            ], ['entity_type' => 'goal', 'entity_id' => (string) $goal_id, 'status' => (($result['success'] ?? false) ? 'recorded' : 'failed')]);

            // Update progress
            $this->active_goals[$goal_id]['actions_completed'] = $index + 1;

            if (!$result['success']) {
                $failed_at = $index;
                break;
            }

            // Check post-action conditions
            if (!empty($conditions['during'])) {
                $during_check = $this->check_conditions($conditions['during']);
                if (!$during_check['satisfied']) {
                    $failed_at = $index;
                    $results[$index]['warning'] = 'During conditions failed';
                    break;
                }
            }
        }

        // Handle failure with rollback
        if ($failed_at !== null && $rollback_on_failure) {
            $rollback_result = $this->rollback($checkpoints, $failed_at);
            $this->active_goals[$goal_id]['status'] = 'rolled_back';
            $this->active_goals[$goal_id]['rollback'] = $rollback_result;

            AuditLog::log('goal_failed', 'execution', 0, [
                'goal_id' => $goal_id,
                'failed_at_action' => $failed_at,
                'rolled_back' => $rollback_result['success'],
            ], 2, 'error');
            ExecutionLedger::instance()->complete_execution($executionId, false, [
                'goal_id' => $goal_id,
                'failed_at_action' => $failed_at,
                'rolled_back' => $rollback_result['success'],
            ], ['entity_type' => 'goal', 'entity_id' => (string) $goal_id]);

            return [
                'success' => false,
                'goal_id' => $goal_id,
                'objective' => $objective,
                'failed_at_action' => $failed_at,
                'results' => $results,
                'rolled_back' => $rollback_result['success'],
            ];
        }

        // Check post-conditions
        $postcondition_check = $this->check_conditions($conditions['post'] ?? []);

        $this->active_goals[$goal_id]['status'] = $postcondition_check['satisfied'] ? 'completed' : 'completed_with_warnings';
        $this->active_goals[$goal_id]['completed_at'] = gmdate('c');

        AuditLog::log('goal_completed', 'execution', 0, [
            'goal_id' => $goal_id,
            'objective' => $objective,
            'actions_completed' => count($results),
            'postconditions_satisfied' => $postcondition_check['satisfied'],
        ], 2);
        ExecutionLedger::instance()->complete_execution($executionId, $failed_at === null, [
            'goal_id' => $goal_id,
            'actions_completed' => count($results),
            'postconditions' => $postcondition_check,
        ], ['entity_type' => 'goal', 'entity_id' => (string) $goal_id]);

        return [
            'success' => $failed_at === null,
            'goal_id' => $goal_id,
            'objective' => $objective,
            'results' => $results,
            'postconditions' => $postcondition_check,
        ];
    }

    /**
     * Execute a single action
     */
    private function execute_action(array $action): array {
        $type = $action['type'] ?? '';
        $params = $action['params'] ?? [];
        $timeout = $action['timeout'] ?? 30;

        $start = microtime(true);

        try {
            $result = match ($type) {
                'create_post' => $this->action_create_post($params),
                'update_post' => $this->action_update_post($params),
                'delete_post' => $this->action_delete_post($params),
                'create_page' => $this->action_create_page($params),
                'update_page' => $this->action_update_page($params),
                'upload_media' => $this->action_upload_media($params),
                'update_option' => $this->action_update_option($params),
                'set_theme_mod' => $this->action_set_theme_mod($params),
                'add_menu_item' => $this->action_add_menu_item($params),
                'apply_seo' => $this->action_apply_seo($params),
                'wait' => $this->action_wait($params),
                'conditional' => $this->action_conditional($params),
                default => ['success' => false, 'error' => "Unknown action type: {$type}"],
            };

            $result['duration_ms'] = (int) ((microtime(true) - $start) * 1000);
            return $result;

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    /**
     * Check conditions
     */
    private function check_conditions(array $conditions): array {
        $failed = [];

        foreach ($conditions as $condition) {
            $satisfied = $this->evaluate_condition($condition);
            if (!$satisfied) {
                $failed[] = $condition;
            }
        }

        return [
            'satisfied' => empty($failed),
            'failed' => $failed,
        ];
    }

    /**
     * Evaluate a single condition
     */
    private function evaluate_condition(array $condition): bool {
        $type = $condition['type'] ?? '';
        $params = $condition['params'] ?? [];

        return match ($type) {
            'post_exists' => get_post($params['id'] ?? 0) !== null,
            'post_status' => get_post_status($params['id'] ?? 0) === ($params['status'] ?? 'publish'),
            'option_equals' => get_option($params['option'] ?? '') === ($params['value'] ?? null),
            'user_can' => current_user_can($params['capability'] ?? 'edit_posts'),
            'theme_active' => get_stylesheet() === ($params['theme'] ?? ''),
            'plugin_active' => is_plugin_active($params['plugin'] ?? ''),
            default => true,
        };
    }

    /**
     * Create a checkpoint for rollback
     */
    private function create_checkpoint(array $action): array {
        $checkpoint = [
            'action_type' => $action['type'],
            'created_at' => gmdate('c'),
            'state' => [],
        ];

        // Capture relevant state based on action type
        switch ($action['type']) {
            case 'update_post':
            case 'update_page':
                $post_id = $action['params']['id'] ?? 0;
                if ($post_id && ($post = get_post($post_id))) {
                    $checkpoint['state']['post'] = [
                        'ID' => $post->ID,
                        'post_title' => $post->post_title,
                        'post_content' => $post->post_content,
                        'post_status' => $post->post_status,
                        'post_excerpt' => $post->post_excerpt,
                    ];
                }
                break;

            case 'update_option':
                $option = $action['params']['option'] ?? '';
                if ($option) {
                    $checkpoint['state']['option'] = [
                        'name' => $option,
                        'value' => get_option($option),
                    ];
                }
                break;

            case 'set_theme_mod':
                $mod = $action['params']['name'] ?? '';
                if ($mod) {
                    $checkpoint['state']['theme_mod'] = [
                        'name' => $mod,
                        'value' => get_theme_mod($mod),
                    ];
                }
                break;
        }

        return $checkpoint;
    }

    /**
     * Rollback to checkpoints
     */
    private function rollback(array $checkpoints, int $failed_at): array {
        $rolled_back = 0;

        // Rollback in reverse order
        for ($i = $failed_at; $i >= 0; $i--) {
            if (!isset($checkpoints[$i])) {
                continue;
            }

            $checkpoint = $checkpoints[$i];
            $state = $checkpoint['state'];

            try {
                // Restore post state
                if (!empty($state['post'])) {
                    wp_update_post($state['post']);
                    $rolled_back++;
                }

                // Restore option state
                if (!empty($state['option'])) {
                    update_option($state['option']['name'], $state['option']['value']);
                    $rolled_back++;
                }

                // Restore theme mod state
                if (!empty($state['theme_mod'])) {
                    set_theme_mod($state['theme_mod']['name'], $state['theme_mod']['value']);
                    $rolled_back++;
                }
            } catch (\Throwable $e) {
                // Log but continue rollback
                AuditLog::log('rollback_error', 'execution', 0, [
                    'checkpoint' => $i,
                    'error' => $e->getMessage(),
                ], 1, 'error');
            }
        }

        return [
            'success' => true,
            'rolled_back_count' => $rolled_back,
        ];
    }

    // Action implementations

    private function action_create_post(array $params): array {
        $post_data = [
            'post_title' => sanitize_text_field($params['title'] ?? ''),
            'post_content' => wp_kses_post($params['content'] ?? ''),
            'post_status' => sanitize_text_field($params['status'] ?? 'draft'),
            'post_type' => 'post',
        ];
        $id = wp_insert_post($post_data, true);
        if (is_wp_error($id)) {
            return ['success' => false, 'error' => $id->get_error_message()];
        }
        return ['success' => true, 'post_id' => $id];
    }

    private function action_update_post(array $params): array {
        $post_data = ['ID' => (int) ($params['id'] ?? 0)];
        if (isset($params['title'])) {
            $post_data['post_title'] = sanitize_text_field($params['title']);
        }
        if (isset($params['content'])) {
            $post_data['post_content'] = wp_kses_post($params['content']);
        }
        if (isset($params['status'])) {
            $post_data['post_status'] = sanitize_text_field($params['status']);
        }
        $result = wp_update_post($post_data, true);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        return ['success' => true, 'post_id' => $post_data['ID']];
    }

    private function action_delete_post(array $params): array {
        $force = $params['force'] ?? false;
        $result = wp_delete_post((int) ($params['id'] ?? 0), $force);
        return ['success' => $result !== false];
    }

    private function action_create_page(array $params): array {
        $page_data = [
            'post_title' => sanitize_text_field($params['title'] ?? ''),
            'post_content' => wp_kses_post($params['content'] ?? ''),
            'post_status' => sanitize_text_field($params['status'] ?? 'draft'),
            'post_type' => 'page',
            'post_parent' => (int) ($params['parent'] ?? 0),
        ];
        $id = wp_insert_post($page_data, true);
        if (is_wp_error($id)) {
            return ['success' => false, 'error' => $id->get_error_message()];
        }
        return ['success' => true, 'page_id' => $id];
    }

    private function action_update_page(array $params): array {
        return $this->action_update_post($params);
    }

    private function action_upload_media(array $params): array {
        $url = $params['url'] ?? '';
        if (empty($url)) {
            return ['success' => false, 'error' => 'URL required'];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url(esc_url_raw($url), 30);
        if (is_wp_error($tmp)) {
            return ['success' => false, 'error' => $tmp->get_error_message()];
        }

        $file = [
            'name' => sanitize_file_name($params['filename'] ?? basename(wp_parse_url($url, PHP_URL_PATH))),
            'tmp_name' => $tmp,
        ];
        $id = media_handle_sideload($file, 0);
        if (is_wp_error($id)) {
            @unlink($tmp);
            return ['success' => false, 'error' => $id->get_error_message()];
        }

        return ['success' => true, 'attachment_id' => $id];
    }

    private function action_update_option(array $params): array {
        $option = sanitize_key($params['option'] ?? '');
        if (empty($option)) {
            return ['success' => false, 'error' => 'Option name required'];
        }

        // Whitelist of allowed options
        $allowed = ['blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format', 'posts_per_page'];
        if (!in_array($option, $allowed, true)) {
            return ['success' => false, 'error' => 'Option not allowed'];
        }

        update_option($option, sanitize_text_field($params['value'] ?? ''));
        return ['success' => true, 'option' => $option];
    }

    private function action_set_theme_mod(array $params): array {
        $name = sanitize_key($params['name'] ?? '');
        if (empty($name)) {
            return ['success' => false, 'error' => 'Mod name required'];
        }
        set_theme_mod($name, $params['value']);
        return ['success' => true, 'mod' => $name];
    }

    private function action_add_menu_item(array $params): array {
        $menu_id = (int) ($params['menu_id'] ?? 0);
        if (!$menu_id) {
            return ['success' => false, 'error' => 'Menu ID required'];
        }

        $item_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title' => sanitize_text_field($params['title'] ?? ''),
            'menu-item-url' => esc_url_raw($params['url'] ?? ''),
            'menu-item-status' => 'publish',
            'menu-item-type' => 'custom',
        ]);

        if (is_wp_error($item_id)) {
            return ['success' => false, 'error' => $item_id->get_error_message()];
        }
        return ['success' => true, 'item_id' => $item_id];
    }

    private function action_apply_seo(array $params): array {
        $post_id = (int) ($params['post_id'] ?? 0);
        if (!$post_id) {
            return ['success' => false, 'error' => 'Post ID required'];
        }

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

    private function action_wait(array $params): array {
        $seconds = min((int) ($params['seconds'] ?? 1), 10);
        sleep($seconds);
        return ['success' => true, 'waited' => $seconds];
    }

    private function action_conditional(array $params): array {
        $condition = $params['condition'] ?? [];
        $then_action = $params['then'] ?? null;
        $else_action = $params['else'] ?? null;

        $satisfied = $this->evaluate_condition($condition);
        $action = $satisfied ? $then_action : $else_action;

        if ($action === null) {
            return ['success' => true, 'condition_result' => $satisfied, 'action_taken' => 'none'];
        }

        $result = $this->execute_action($action);
        $result['condition_result'] = $satisfied;
        return $result;
    }

    /**
     * Get active goals
     */
    public function get_active_goals(): array {
        return $this->active_goals;
    }

    /**
     * Get goal status
     */
    public function get_goal_status(string $goal_id): ?array {
        return $this->active_goals[$goal_id] ?? null;
    }

    /**
     * Cancel a running goal
     */
    public function cancel_goal(string $goal_id): bool {
        if (!isset($this->active_goals[$goal_id])) {
            return false;
        }

        $this->active_goals[$goal_id]['status'] = 'cancelled';
        $this->active_goals[$goal_id]['cancelled_at'] = gmdate('c');

        AuditLog::log('goal_cancelled', 'execution', 0, ['goal_id' => $goal_id], 2);
        return true;
    }
}
