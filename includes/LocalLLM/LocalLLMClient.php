<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\LocalLLM;

use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\Bridge\CapabilityGate;

/**
 * LocalLLMClient
 *
 * Manages communication with a locally-running Ollama instance (or any
 * OpenAI-compatible local server on http://127.0.0.1:11434 by default).
 *
 * Acts as the execution engine for local_llm agent tasks: the cloud AGI
 * pre-authorises an instruction package, this class compiles it into a
 * constrained prompt, calls the local model, validates the response against
 * CapabilityGate, executes the resolved action, and stores the full audit
 * trail in the wp_rjv_agi_llm_tasks table with an AuditLog entry.
 *
 * No action ever bypasses CapabilityGate. The local model can only perform
 * actions the AGI explicitly listed in the allowed scope.
 */
final class LocalLLMClient {

    private static ?self $instance = null;
    private string $table_name;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rjv_agi_llm_tasks';
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    /**
     * Create the llm_tasks table (idempotent via CREATE TABLE IF NOT EXISTS).
     */
    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'rjv_agi_llm_tasks';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id VARCHAR(100) NOT NULL,
            agent_id VARCHAR(100) NOT NULL DEFAULT '',
            instructions LONGTEXT NOT NULL,
            compiled_prompt LONGTEXT NULL,
            model_response LONGTEXT NULL,
            proposed_action LONGTEXT NULL,
            action_validated TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            action_executed TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            result LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            model_used VARCHAR(100) NULL,
            tokens_used INT UNSIGNED NULL,
            execution_time_ms INT UNSIGNED NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            UNIQUE KEY uq_task_id (task_id),
            INDEX idx_agent (agent_id),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check whether the local LLM feature is enabled.
     */
    public function is_enabled(): bool {
        return Settings::get_bool('local_llm_enabled');
    }

    /**
     * Dispatch an instruction package to the local LLM and execute the result.
     *
     * Full pipeline:
     *   1. InstructionCompiler builds a constrained [system, user] prompt.
     *   2. Ollama /api/chat is called with stream=false.
     *   3. The raw response is parsed into a typed action object.
     *   4. CapabilityGate validates the action before execution.
     *   5. The action is executed via the internal action router.
     *   6. Everything is persisted in llm_tasks and AuditLog.
     *
     * @param array  $instructions  Array of instruction objects from the AGI.
     * @param array  $scope         Allowed action scope (allowed_action_types, etc.).
     * @param array  $constraints   Hard constraints for this dispatch.
     * @param string $agent_id      The AgentRuntime agent_id that owns this task.
     * @return array{success: bool, task_id?: string, action?: array, result?: array, error?: string}
     */
    public function dispatch(
        array  $instructions,
        array  $scope        = [],
        array  $constraints  = [],
        string $agent_id     = ''
    ): array {
        if (!$this->is_enabled()) {
            return ['success' => false, 'error' => 'Local LLM is not enabled'];
        }

        $task_id  = 'llm_' . wp_generate_uuid4();
        $start_ms = (int) (microtime(true) * 1000);

        // Persist initial task record
        $this->insert_task($task_id, $agent_id, $instructions);

        // ── Step 1: Compile prompt ────────────────────────────────────────────
        $prompt = InstructionCompiler::compile($instructions, $scope, $constraints);
        $this->update_task($task_id, ['compiled_prompt' => wp_json_encode($prompt)]);

        // ── Step 2: Call Ollama ───────────────────────────────────────────────
        $llm_result = $this->call_ollama($prompt['system'], $prompt['user']);

        if (!$llm_result['success']) {
            $elapsed = (int) (microtime(true) * 1000) - $start_ms;
            $this->fail_task($task_id, $llm_result['error'], $elapsed);
            AuditLog::log('local_llm_failed', 'llm_task', 0, [
                'task_id'  => $task_id,
                'agent_id' => $agent_id,
                'error'    => $llm_result['error'],
            ], 2, 'error', $elapsed);
            return ['success' => false, 'task_id' => $task_id, 'error' => $llm_result['error']];
        }

        $raw_response = $llm_result['content'];
        $tokens_used  = $llm_result['tokens'] ?? null;
        $model_used   = $llm_result['model'] ?? Settings::get_string('local_llm_model', 'phi3:mini');

        $this->update_task($task_id, [
            'model_response' => $raw_response,
            'model_used'     => $model_used,
            'tokens_used'    => $tokens_used,
            'status'         => 'running',
        ]);

        // ── Step 3: Parse response ────────────────────────────────────────────
        $parsed = InstructionCompiler::parse_response($raw_response, $scope);

        if ($parsed === null) {
            $elapsed = (int) (microtime(true) * 1000) - $start_ms;
            $this->fail_task($task_id, 'LLM response could not be parsed as a valid action JSON object', $elapsed);
            AuditLog::log('local_llm_parse_error', 'llm_task', 0, [
                'task_id'  => $task_id,
                'agent_id' => $agent_id,
            ], 2, 'error', $elapsed, $tokens_used, $model_used);
            return ['success' => false, 'task_id' => $task_id, 'error' => 'Invalid LLM response format'];
        }

        $this->update_task($task_id, ['proposed_action' => wp_json_encode($parsed)]);

        // ── Step 4: CapabilityGate validation ─────────────────────────────────
        $gate = CapabilityGate::instance();

        if ($parsed['action'] !== 'noop' && !$gate->can('local_llm_execute', ['action' => $parsed['action']])) {
            $elapsed = (int) (microtime(true) * 1000) - $start_ms;
            $this->fail_task($task_id, 'Action denied by CapabilityGate', $elapsed, ['action_validated' => 0]);
            AuditLog::log('local_llm_action_denied', 'llm_task', 0, [
                'task_id'  => $task_id,
                'agent_id' => $agent_id,
                'action'   => $parsed['action'],
            ], 2, 'error', $elapsed, $tokens_used, $model_used);
            return ['success' => false, 'task_id' => $task_id, 'error' => 'Action denied by capability gate'];
        }

        $this->update_task($task_id, ['action_validated' => 1]);

        // ── Step 5a: noop fast-path ───────────────────────────────────────────
        if ($parsed['action'] === 'noop') {
            $elapsed = (int) (microtime(true) * 1000) - $start_ms;
            $noop_result = ['noop' => true, 'rationale' => $parsed['rationale']];
            $this->update_task($task_id, [
                'action_executed'   => 0,
                'result'            => wp_json_encode($noop_result),
                'status'            => 'completed',
                'execution_time_ms' => $elapsed,
                'completed_at'      => current_time('mysql', true),
            ]);
            AuditLog::log('local_llm_noop', 'llm_task', 0, [
                'task_id'   => $task_id,
                'agent_id'  => $agent_id,
                'rationale' => $parsed['rationale'],
            ], 1, 'success', $elapsed, $tokens_used, $model_used);
            return [
                'success' => true,
                'task_id' => $task_id,
                'action'  => $parsed,
                'result'  => $noop_result,
            ];
        }

        // ── Step 5b: Execute action via CapabilityGate wrapper ────────────────
        $exec_result = $gate->execute(
            'local_llm_execute',
            fn (): array => $this->execute_action($parsed['action'], $parsed['params']),
            ['action' => $parsed['action']]
        );

        $elapsed    = (int) (microtime(true) * 1000) - $start_ms;
        $succeeded  = (bool) ($exec_result['success'] ?? false);
        $new_status = $succeeded ? 'completed' : 'failed';

        $this->update_task($task_id, [
            'action_executed'   => 1,
            'result'            => wp_json_encode($exec_result),
            'status'            => $new_status,
            'execution_time_ms' => $elapsed,
            'completed_at'      => current_time('mysql', true),
        ]);

        AuditLog::log('local_llm_executed', 'llm_task', 0, [
            'task_id'  => $task_id,
            'agent_id' => $agent_id,
            'action'   => $parsed['action'],
            'success'  => $succeeded,
        ], 2, $succeeded ? 'success' : 'error', $elapsed, $tokens_used, $model_used);

        return [
            'success' => $succeeded,
            'task_id' => $task_id,
            'action'  => $parsed,
            'result'  => $exec_result,
        ];
    }

    /**
     * Health check — verify that the Ollama daemon is reachable and list models.
     *
     * @return array{reachable: bool, latency_ms: int, models?: string[], error?: string, http_status?: int}
     */
    public function health_check(): array {
        $endpoint   = rtrim(Settings::get_string('local_llm_endpoint', 'http://127.0.0.1:11434'), '/');
        $start      = microtime(true);

        $response   = wp_remote_get($endpoint . '/api/tags', [
            'timeout'   => 5,
            'sslverify' => false,
        ]);

        $latency_ms = (int) ((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return [
                'reachable'  => false,
                'latency_ms' => $latency_ms,
                'error'      => $response->get_error_message(),
            ];
        }

        $code   = (int) wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $models = [];

        if (is_array($body) && !empty($body['models'])) {
            foreach ($body['models'] as $m) {
                $name = $m['name'] ?? '';
                if ($name !== '') {
                    $models[] = $name;
                }
            }
        }

        return [
            'reachable'   => $code === 200,
            'latency_ms'  => $latency_ms,
            'models'      => $models,
            'http_status' => $code,
        ];
    }

    /**
     * Retrieve a single task record by task_id.
     */
    public function get_task(string $task_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE task_id = %s", $task_id),
            ARRAY_A
        );

        return $row ? $this->hydrate_task($row) : null;
    }

    /**
     * List task records with optional filters.
     *
     * @param array $filters  Supported keys: agent_id, status, limit.
     * @return array<int, array>
     */
    public function list_tasks(array $filters = []): array {
        global $wpdb;

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['agent_id'])) {
            $where[]  = 'agent_id = %s';
            $params[] = $filters['agent_id'];
        }

        if (!empty($filters['status'])) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        $limit    = min((int) ($filters['limit'] ?? 50), 200);
        $params[] = $limit;

        $sql  = 'SELECT * FROM ' . $this->table_name
              . ' WHERE ' . implode(' AND ', $where)
              . ' ORDER BY created_at DESC LIMIT %d';

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        return array_map([$this, 'hydrate_task'], $rows);
    }

    // -------------------------------------------------------------------------
    // Private: Ollama HTTP call
    // -------------------------------------------------------------------------

    /**
     * POST a chat completion request to the Ollama /api/chat endpoint.
     *
     * @return array{success: bool, content?: string, tokens?: int, model?: string, error?: string}
     */
    private function call_ollama(string $system_prompt, string $user_content): array {
        $endpoint = rtrim(Settings::get_string('local_llm_endpoint', 'http://127.0.0.1:11434'), '/');
        $model    = Settings::get_string('local_llm_model', 'phi3:mini');
        $timeout  = Settings::get_int('local_llm_timeout', 60);

        $payload = wp_json_encode([
            'model'   => $model,
            'stream'  => false,
            'options' => [
                'temperature' => Settings::get_float('local_llm_temperature', 0.0),
                'num_predict' => Settings::get_int('local_llm_max_tokens', 512),
            ],
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $user_content],
            ],
        ]);

        $response = wp_remote_post($endpoint . '/api/chat', [
            'timeout'   => $timeout,
            'sslverify' => false,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $payload,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return ['success' => false, 'error' => "Ollama returned HTTP {$code}"];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid JSON response from Ollama'];
        }

        $content = (string) ($data['message']['content'] ?? '');
        if ($content === '') {
            return ['success' => false, 'error' => 'Empty content in Ollama response'];
        }

        $tokens = (int) (($data['prompt_eval_count'] ?? 0) + ($data['eval_count'] ?? 0));

        return [
            'success' => true,
            'content' => $content,
            'tokens'  => $tokens > 0 ? $tokens : null,
            'model'   => $model,
        ];
    }

    // -------------------------------------------------------------------------
    // Private: Action execution
    // -------------------------------------------------------------------------

    /**
     * Route a validated action to its handler.
     *
     * @return array{success: bool, ...}
     */
    private function execute_action(string $action, array $params): array {
        return match ($action) {
            'read_post'   => $this->action_read_post($params),
            'update_post' => $this->action_update_post($params),
            'read_seo'    => $this->action_read_seo($params),
            'update_seo'  => $this->action_update_seo($params),
            'ai_complete' => $this->action_ai_complete($params),
            default       => ['success' => false, 'error' => "Unsupported local action: {$action}"],
        };
    }

    private function action_read_post(array $params): array {
        $post = get_post((int) ($params['id'] ?? 0));
        if (!$post) {
            return ['success' => false, 'error' => 'Post not found'];
        }
        return [
            'success' => true,
            'post'    => [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'status'  => $post->post_status,
                'type'    => $post->post_type,
                'excerpt' => $post->post_excerpt,
            ],
        ];
    }

    private function action_update_post(array $params): array {
        $post_id = (int) ($params['id'] ?? 0);
        if (!get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        $update = ['ID' => $post_id];

        if (isset($params['title'])) {
            $update['post_title'] = sanitize_text_field((string) $params['title']);
        }

        $allowed_statuses = ['draft', 'publish', 'pending', 'private'];
        if (isset($params['status']) && in_array($params['status'], $allowed_statuses, true)) {
            $update['post_status'] = $params['status'];
        }

        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return ['success' => true, 'post_id' => $post_id];
    }

    private function action_read_seo(array $params): array {
        $post_id = (int) ($params['id'] ?? 0);
        if (!get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        // Check each SEO plugin in priority order (matches Base::get_seo pattern)
        $yoast_title = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
        if ($yoast_title !== '') {
            return [
                'success'          => true,
                'seo_title'        => $yoast_title,
                'meta_description' => (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
                'source'           => 'yoast',
            ];
        }

        $rm_title = (string) get_post_meta($post_id, 'rank_math_title', true);
        if ($rm_title !== '') {
            return [
                'success'          => true,
                'seo_title'        => $rm_title,
                'meta_description' => (string) get_post_meta($post_id, 'rank_math_description', true),
                'source'           => 'rank_math',
            ];
        }

        return [
            'success'          => true,
            'seo_title'        => '',
            'meta_description' => '',
            'source'           => 'none',
        ];
    }

    private function action_update_seo(array $params): array {
        $post_id = (int) ($params['id'] ?? 0);
        if (!get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        if (isset($params['title'])) {
            $title = sanitize_text_field((string) $params['title']);
            update_post_meta($post_id, '_yoast_wpseo_title', $title);
            update_post_meta($post_id, 'rank_math_title', $title);
        }

        if (isset($params['description'])) {
            $description = sanitize_textarea_field((string) $params['description']);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
            update_post_meta($post_id, 'rank_math_description', $description);
        }

        return ['success' => true, 'post_id' => $post_id];
    }

    private function action_ai_complete(array $params): array {
        $prompt = sanitize_textarea_field((string) ($params['prompt'] ?? ''));
        if ($prompt === '') {
            return ['success' => false, 'error' => 'prompt is required for ai_complete'];
        }

        if (!class_exists('RJV_AGI_Bridge\AI\Router')) {
            return ['success' => false, 'error' => 'AI Router not available'];
        }

        $router = new \RJV_AGI_Bridge\AI\Router();
        return $router->complete('You are a helpful assistant.', $prompt, [
            'max_tokens'  => 512,
            'temperature' => 0.3,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private: DB helpers
    // -------------------------------------------------------------------------

    private function insert_task(string $task_id, string $agent_id, array $instructions): void {
        global $wpdb;
        $wpdb->insert($this->table_name, [
            'task_id'      => $task_id,
            'agent_id'     => $agent_id,
            'instructions' => wp_json_encode($instructions),
            'status'       => 'pending',
            'created_at'   => current_time('mysql', true),
        ]);
    }

    private function update_task(string $task_id, array $data): void {
        global $wpdb;
        $wpdb->update($this->table_name, $data, ['task_id' => $task_id]);
    }

    /**
     * Mark a task as failed, record error details, and close it.
     */
    private function fail_task(string $task_id, string $error, int $elapsed_ms, array $extra = []): void {
        $this->update_task($task_id, array_merge([
            'status'            => 'failed',
            'error_message'     => $error,
            'execution_time_ms' => $elapsed_ms,
            'completed_at'      => current_time('mysql', true),
        ], $extra));
    }

    private function hydrate_task(array $row): array {
        return [
            'id'                => (int) $row['id'],
            'task_id'           => $row['task_id'],
            'agent_id'          => $row['agent_id'],
            'instructions'      => json_decode($row['instructions'] ?? '[]', true) ?: [],
            'proposed_action'   => json_decode($row['proposed_action'] ?? 'null', true),
            'result'            => json_decode($row['result'] ?? 'null', true),
            'action_validated'  => (bool) $row['action_validated'],
            'action_executed'   => (bool) $row['action_executed'],
            'status'            => $row['status'],
            'model_used'        => $row['model_used'],
            'tokens_used'       => isset($row['tokens_used']) ? (int) $row['tokens_used'] : null,
            'execution_time_ms' => isset($row['execution_time_ms']) ? (int) $row['execution_time_ms'] : null,
            'error_message'     => $row['error_message'],
            'created_at'        => $row['created_at'],
            'completed_at'      => $row['completed_at'],
        ];
    }
}
