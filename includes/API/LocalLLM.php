<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\Settings;
use RJV_AGI_Bridge\Agent\AgentRuntime;
use RJV_AGI_Bridge\LocalLLM\LocalLLMClient;

/**
 * Local LLM REST API Controller
 *
 * Exposes four endpoints that let the cloud AGI dispatch instruction packages
 * to the on-server Ollama instance and let admins/monitors inspect health and
 * task history.
 *
 * Routes:
 *   POST /local-llm/dispatch                 – Dispatch an instruction package (tier2)
 *   GET  /local-llm/status                   – Ollama health + config (tier1)
 *   GET  /local-llm/tasks                    – List historical tasks (tier1)
 *   GET  /local-llm/tasks/{task_id}          – Get a specific task record (tier1)
 */
class LocalLLM extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/local-llm/dispatch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'dispatch'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        register_rest_route($this->namespace, '/local-llm/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'status'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($this->namespace, '/local-llm/tasks', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_tasks'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($this->namespace, '/local-llm/tasks/(?P<task_id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_task'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * POST /local-llm/dispatch
     *
     * Body (JSON):
     * {
     *   "instructions": [{ "action": "...", "description": "..." }, ...],
     *   "scope":        { "allowed_action_types": ["read_post", "update_seo"] },
     *   "constraints":  { "forbidden_actions": [], "max_ops": 1 },
     *   "name":         "optional human-readable label"
     * }
     *
     * On success returns:
     * {
     *   "success":  true,
     *   "data":     { "task_id": "llm_...", "agent_id": "agent_...", "action": {...}, "result": {...} },
     *   "elapsed_ms": <int>
     * }
     */
    public function dispatch(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!Settings::get_bool('local_llm_enabled')) {
            return $this->error(
                'Local LLM is not enabled. Enable it under Settings → Local LLM.',
                503,
                'feature_disabled'
            );
        }

        $body         = (array) $r->get_json_params();
        $instructions = (array) ($body['instructions'] ?? []);
        $scope        = (array) ($body['scope']        ?? []);
        $constraints  = (array) ($body['constraints']  ?? []);
        $name         = sanitize_text_field((string) ($body['name'] ?? 'local_llm_task'));

        if (empty($instructions)) {
            return $this->error(
                'instructions is required and must be a non-empty array',
                422,
                'validation_error'
            );
        }

        $this->start_timer();

        // Deploy a local_llm agent via AgentRuntime so the full lifecycle is
        // recorded in the agents table and linked to downstream AuditLog entries.
        $runtime = AgentRuntime::instance();
        $deploy  = $runtime->deploy([
            'name'        => $name,
            'type'        => 'local_llm',
            'scope'       => $scope,
            'tools'       => ['local_llm_dispatch'],
            'constraints' => $constraints,
            'task'        => ['instructions' => $instructions],
            'created_by'  => 'agi',
        ]);

        if (!($deploy['success'] ?? false)) {
            return $this->error($deploy['error'] ?? 'Agent deployment failed', 500);
        }

        $agent_id = $deploy['agent_id'];
        $runtime->start($agent_id);

        // Dispatch instruction package to the local LLM
        $client = LocalLLMClient::instance();
        $result = $client->dispatch($instructions, $scope, $constraints, $agent_id);

        $elapsed = $this->elapsed_ms();

        // Close the agent — result is stored in llm_tasks; agent records the lifecycle
        $runtime->stop($agent_id, ($result['success'] ?? false) ? 'completed' : 'failed');

        if (!($result['success'] ?? false)) {
            return $this->error(
                $result['error'] ?? 'Local LLM dispatch failed',
                500,
                'llm_error',
                ['task_id' => $result['task_id'] ?? null]
            );
        }

        $this->log('local_llm_dispatch', 'llm_task', 0, [
            'agent_id'   => $agent_id,
            'task_id'    => $result['task_id'],
            'action'     => $result['action']['action'] ?? 'unknown',
            'elapsed_ms' => $elapsed,
        ], 2);

        return $this->success([
            'task_id'  => $result['task_id'],
            'agent_id' => $agent_id,
            'action'   => $result['action'],
            'result'   => $result['result'],
        ], 200, ['elapsed_ms' => $elapsed]);
    }

    /**
     * GET /local-llm/status
     *
     * Returns enabled flag, configured endpoint, configured model, and
     * (when enabled) the live health-check against the Ollama daemon.
     */
    public function status(\WP_REST_Request $r): \WP_REST_Response {
        $client  = LocalLLMClient::instance();
        $enabled = Settings::get_bool('local_llm_enabled');

        $status = [
            'enabled'  => $enabled,
            'endpoint' => Settings::get_string('local_llm_endpoint', 'http://127.0.0.1:11434'),
            'model'    => Settings::get_string('local_llm_model', 'phi3:mini'),
            'timeout'  => Settings::get_int('local_llm_timeout', 60),
        ];

        if ($enabled) {
            $health = $client->health_check();
            $status = array_merge($status, $health);
        }

        return $this->success($status);
    }

    /**
     * GET /local-llm/tasks
     *
     * Query params: agent_id, status (pending|running|completed|failed), limit (max 200).
     */
    public function list_tasks(\WP_REST_Request $r): \WP_REST_Response {
        $filters = array_filter([
            'agent_id' => sanitize_text_field((string) ($r->get_param('agent_id') ?? '')),
            'status'   => sanitize_key((string) ($r->get_param('status') ?? '')),
            'limit'    => min((int) ($r->get_param('limit') ?? 50), 200),
        ]);

        $tasks = LocalLLMClient::instance()->list_tasks($filters);

        return $this->success(['tasks' => $tasks, 'count' => count($tasks)]);
    }

    /**
     * GET /local-llm/tasks/{task_id}
     */
    public function get_task(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $task_id = sanitize_text_field((string) $r->get_param('task_id'));
        $task    = LocalLLMClient::instance()->get_task($task_id);

        if (!$task) {
            return $this->error("Task '{$task_id}' not found.", 404, 'not_found');
        }

        return $this->success($task);
    }
}
