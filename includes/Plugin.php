<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

use RJV_AGI_Bridge\Bridge\PlatformConnector;
use RJV_AGI_Bridge\Bridge\CapabilityGate;
use RJV_AGI_Bridge\Bridge\TenantIsolation;
use RJV_AGI_Bridge\Content\VersionManager;
use RJV_AGI_Bridge\Content\ContentOperations;
use RJV_AGI_Bridge\Design\DesignSystemController;
use RJV_AGI_Bridge\Events\EventDispatcher;
use RJV_AGI_Bridge\Execution\GoalExecutor;
use RJV_AGI_Bridge\Execution\ApprovalWorkflow;
use RJV_AGI_Bridge\Execution\ExecutionLedger;
use RJV_AGI_Bridge\Agent\AgentRuntime;
use RJV_AGI_Bridge\Security\SecurityMonitor;
use RJV_AGI_Bridge\Security\AccessControl;
use RJV_AGI_Bridge\Security\ComplianceManager;
use RJV_AGI_Bridge\Integration\IntegrationManager;
use RJV_AGI_Bridge\Integration\WebhookManager;
use RJV_AGI_Bridge\Performance\PerformanceOptimizer;
use RJV_AGI_Bridge\Governance\ProgramRegistry;
use RJV_AGI_Bridge\Governance\PolicyEngine;
use RJV_AGI_Bridge\Governance\ContractManager;
use RJV_AGI_Bridge\Governance\UpgradeSafety;
use RJV_AGI_Bridge\Observability\ReliabilityMonitor;

/**
 * Main Plugin Class
 *
 * Enterprise AGI control interface for WordPress. Acts as a controlled execution
 * interface between WordPress and the central AGI platform with full governance,
 * isolation, and human oversight.
 */
final class Plugin {
    private static ?self $instance = null;
    private Admin\Dashboard $dashboard;

    // Enterprise modules
    private ?PlatformConnector $platform = null;
    private ?CapabilityGate $gate = null;
    private ?TenantIsolation $tenant = null;
    private ?VersionManager $versions = null;
    private ?ContentOperations $content = null;
    private ?DesignSystemController $design = null;
    private ?EventDispatcher $events = null;
    private ?GoalExecutor $goals = null;
    private ?ApprovalWorkflow $approvals = null;
    private ?ExecutionLedger $ledger = null;
    private ?AgentRuntime $agents = null;
    private ?SecurityMonitor $security = null;
    private ?AccessControl $access = null;
    private ?ComplianceManager $compliance = null;
    private ?IntegrationManager $integrations = null;
    private ?WebhookManager $webhooks = null;
    private ?PerformanceOptimizer $performance = null;
    private ?ProgramRegistry $program = null;
    private ?PolicyEngine $policy = null;
    private ?ContractManager $contract = null;
    private ?UpgradeSafety $upgrade_safety = null;
    private ?ReliabilityMonitor $reliability = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        // Load core files
        $core_files = [
            'Installer', 'Settings', 'Auth', 'AuditLog',
            'AI/Provider', 'AI/OpenAI', 'AI/Anthropic', 'AI/Router',
            'API/Base', 'API/Posts', 'API/Pages', 'API/Media', 'API/Users',
            'API/Options', 'API/Themes', 'API/Plugins', 'API/Menus', 'API/Widgets',
            'API/SEO', 'API/Comments', 'API/Taxonomies', 'API/SiteHealth',
            'API/ContentGen', 'API/Database', 'API/FileSystem', 'API/Cron', 'API/EnterpriseControl',
            'Admin/Dashboard',
        ];

        // Load enterprise modules
        $enterprise_files = [
            'Bridge/PlatformConnector', 'Bridge/CapabilityGate', 'Bridge/TenantIsolation',
            'Content/VersionManager', 'Content/ContentOperations',
            'Design/DesignSystemController',
            'Events/EventDispatcher',
             'Execution/GoalExecutor', 'Execution/ApprovalWorkflow',
             'Execution/ExecutionLedger',
             'Agent/AgentRuntime',
             'Security/SecurityMonitor', 'Security/AccessControl', 'Security/ComplianceManager',
             'Integration/IntegrationManager', 'Integration/WebhookManager',
             'Performance/PerformanceOptimizer',
             'Governance/ProgramRegistry', 'Governance/PolicyEngine', 'Governance/ContractManager', 'Governance/UpgradeSafety',
             'Observability/ReliabilityMonitor',
        ];

        $all_files = array_merge($core_files, $enterprise_files);
        foreach ($all_files as $f) {
            $p = RJV_AGI_PLUGIN_DIR . "includes/{$f}.php";
            if (file_exists($p)) {
                require_once $p;
            }
        }

        // Run installer/upgrader
        Installer::maybe_upgrade();

        // Initialize core components
        $this->dashboard = new Admin\Dashboard();

        // Initialize enterprise modules
        $this->init_enterprise_modules();

        // Register hooks
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this->dashboard, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this->dashboard, 'enqueue_assets']);
        add_filter('rest_pre_dispatch', [$this, 'pre_dispatch'], 5, 3);
        add_filter('rest_pre_dispatch', [$this, 'rate_limit'], 10, 3);
        add_filter('rest_post_dispatch', [$this, 'post_dispatch'], 10, 3);

        // Schedule cron jobs
        $this->schedule_cron_jobs();

        // Platform heartbeat
        add_action('rjv_agi_platform_heartbeat', [$this, 'platform_heartbeat']);
    }

    /**
     * Initialize enterprise modules
     */
    private function init_enterprise_modules(): void {
        // Initialize in dependency order
        $this->tenant = TenantIsolation::instance();
        $this->platform = PlatformConnector::instance();
        $this->gate = CapabilityGate::instance();
        $this->versions = VersionManager::instance();
        $this->content = ContentOperations::instance();
        $this->design = DesignSystemController::instance();
        $this->events = EventDispatcher::instance();
        $this->goals = GoalExecutor::instance();
        $this->approvals = ApprovalWorkflow::instance();
        $this->ledger = ExecutionLedger::instance();
        $this->agents = AgentRuntime::instance();
        $this->security = SecurityMonitor::instance();
        $this->access = AccessControl::instance();
        $this->compliance = ComplianceManager::instance();
        $this->integrations = IntegrationManager::instance();
        $this->webhooks = WebhookManager::instance();
        $this->performance = PerformanceOptimizer::instance();
        $this->program = ProgramRegistry::instance();
        $this->policy = PolicyEngine::instance();
        $this->contract = ContractManager::instance();
        $this->upgrade_safety = UpgradeSafety::instance();
        $this->reliability = ReliabilityMonitor::instance();
    }

    /**
     * Schedule cron jobs
     */
    private function schedule_cron_jobs(): void {
        // Audit log cleanup
        if (!wp_next_scheduled('rjv_agi_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'rjv_agi_log_cleanup');
        }
        add_action('rjv_agi_log_cleanup', [AuditLog::class, 'cleanup']);

        // Content version cleanup
        if (!wp_next_scheduled('rjv_agi_version_cleanup')) {
            wp_schedule_event(time(), 'daily', 'rjv_agi_version_cleanup');
        }
        add_action('rjv_agi_version_cleanup', function () {
            VersionManager::instance()->cleanup(50, 365);
        });

        // Approval expiry cleanup
        if (!wp_next_scheduled('rjv_agi_approval_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'rjv_agi_approval_cleanup');
        }
        add_action('rjv_agi_approval_cleanup', function () {
            ApprovalWorkflow::instance()->cleanup();
        });

        // Platform heartbeat
        if (!wp_next_scheduled('rjv_agi_platform_heartbeat')) {
            wp_schedule_event(time(), 'hourly', 'rjv_agi_platform_heartbeat');
        }

        // Security scan
        if (!wp_next_scheduled('rjv_agi_security_scan')) {
            wp_schedule_event(time(), 'twicedaily', 'rjv_agi_security_scan');
        }
        add_action('rjv_agi_security_scan', function () {
            $monitor = SecurityMonitor::instance();
            $results = $monitor->run_scan();
            $monitor->save_scan($results);
        });
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        // Core API controllers
        $core_controllers = [
            new API\Posts(),
            new API\Pages(),
            new API\Media(),
            new API\Users(),
            new API\Options(),
            new API\Themes(),
            new API\Plugins(),
            new API\Menus(),
            new API\Widgets(),
            new API\SEO(),
            new API\Comments(),
            new API\Taxonomies(),
            new API\SiteHealth(),
            new API\ContentGen(),
            new API\Database(),
            new API\FileSystem(),
            new API\Cron(),
            new API\EnterpriseControl(),
        ];

        foreach ($core_controllers as $controller) {
            $controller->register_routes();
        }

        // Register enterprise API routes
        $this->register_enterprise_routes();
    }

    /**
     * Register enterprise API routes
     */
    private function register_enterprise_routes(): void {
        $namespace = 'rjv-agi/v1';

        // Platform/Bridge routes
        register_rest_route($namespace, '/platform/status', [
            'methods' => 'GET',
            'callback' => [$this, 'api_platform_status'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($namespace, '/platform/capabilities', [
            'methods' => 'GET',
            'callback' => [$this, 'api_capabilities'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Content versioning routes
        register_rest_route($namespace, '/versions/(?P<type>[a-z]+)/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'api_get_versions'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($namespace, '/versions/(?P<version_id>\d+)/revert', [
            'methods' => 'POST',
            'callback' => [$this, 'api_revert_version'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        register_rest_route($namespace, '/versions/compare', [
            'methods' => 'POST',
            'callback' => [$this, 'api_compare_versions'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Goal execution routes
        register_rest_route($namespace, '/goals/execute', [
            'methods' => 'POST',
            'callback' => [$this, 'api_execute_goal'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        register_rest_route($namespace, '/goals/active', [
            'methods' => 'GET',
            'callback' => [$this, 'api_active_goals'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Approval workflow routes
        register_rest_route($namespace, '/approvals', [
            'methods' => 'GET',
            'callback' => [$this, 'api_pending_approvals'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($namespace, '/approvals/(?P<id>\d+)/approve', [
            'methods' => 'POST',
            'callback' => [$this, 'api_approve_action'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        register_rest_route($namespace, '/approvals/(?P<id>\d+)/reject', [
            'methods' => 'POST',
            'callback' => [$this, 'api_reject_action'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        // Agent routes
        register_rest_route($namespace, '/agents', [
            ['methods' => 'GET', 'callback' => [$this, 'api_list_agents'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'api_deploy_agent'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        register_rest_route($namespace, '/agents/(?P<agent_id>[a-zA-Z0-9_-]+)', [
            ['methods' => 'GET', 'callback' => [$this, 'api_get_agent'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'DELETE', 'callback' => [$this, 'api_stop_agent'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        register_rest_route($namespace, '/agents/(?P<agent_id>[a-zA-Z0-9_-]+)/execute', [
            'methods' => 'POST',
            'callback' => [$this, 'api_agent_execute'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        // Security routes
        register_rest_route($namespace, '/security/scan', [
            'methods' => 'POST',
            'callback' => [$this, 'api_security_scan'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        register_rest_route($namespace, '/security/status', [
            'methods' => 'GET',
            'callback' => [$this, 'api_security_status'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Performance routes
        register_rest_route($namespace, '/performance/analyze', [
            'methods' => 'GET',
            'callback' => [$this, 'api_performance_analyze'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($namespace, '/performance/optimize', [
            'methods' => 'POST',
            'callback' => [$this, 'api_performance_optimize'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        // Integration routes
        register_rest_route($namespace, '/integrations', [
            ['methods' => 'GET', 'callback' => [$this, 'api_list_integrations'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'api_create_integration'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Webhook routes
        register_rest_route($namespace, '/webhooks', [
            ['methods' => 'GET', 'callback' => [$this, 'api_list_webhooks'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'api_create_webhook'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Design system routes
        register_rest_route($namespace, '/design/tokens', [
            ['methods' => 'GET', 'callback' => [$this, 'api_get_design_tokens'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'api_update_design_tokens'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        register_rest_route($namespace, '/design/validate-css', [
            'methods' => 'POST',
            'callback' => [$this, 'api_validate_css'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
    }

    // API Handlers

    public function api_platform_status(\WP_REST_Request $r): \WP_REST_Response {
        $connector = PlatformConnector::instance();
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'configured' => $connector->is_configured(),
                'subscription' => $connector->validate_subscription(),
            ],
        ]);
    }

    public function api_capabilities(\WP_REST_Request $r): \WP_REST_Response {
        $gate = CapabilityGate::instance();
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'capabilities' => $gate->get_available(),
                'usage' => $gate->get_usage(),
            ],
        ]);
    }

    public function api_get_versions(\WP_REST_Request $r): \WP_REST_Response {
        $versions = VersionManager::instance()->get_versions(
            $r->get_param('type'),
            (int) $r->get_param('id')
        );
        return new \WP_REST_Response(['success' => true, 'data' => $versions]);
    }

    public function api_revert_version(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = VersionManager::instance()->revert_to_version(
            (int) $r->get_param('version_id'),
            'api',
            'agi'
        );
        if (!$result['success']) {
            return new \WP_Error('revert_failed', $result['error'], ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    public function api_compare_versions(\WP_REST_Request $r): \WP_REST_Response {
        $data = $r->get_json_params();
        $result = VersionManager::instance()->compare_versions(
            (int) ($data['version_a'] ?? 0),
            (int) ($data['version_b'] ?? 0)
        );
        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    public function api_execute_goal(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $goal = $r->get_json_params();

        // Check if approval required
        $approval = ApprovalWorkflow::instance();
        if ($approval->requires_approval('goal_execution', $goal)) {
            $result = $approval->submit('goal_execution', $goal, 'api', 'agi');
            return new \WP_REST_Response([
                'success' => true,
                'data' => ['requires_approval' => true, 'approval' => $result],
            ]);
        }

        $result = GoalExecutor::instance()->execute($goal);
        return new \WP_REST_Response(['success' => $result['success'], 'data' => $result]);
    }

    public function api_active_goals(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => GoalExecutor::instance()->get_active_goals(),
        ]);
    }

    public function api_pending_approvals(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => ApprovalWorkflow::instance()->get_pending(),
        ]);
    }

    public function api_approve_action(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = ApprovalWorkflow::instance()->approve(
            (int) $r->get_param('id'),
            get_current_user_id(),
            true
        );
        if (!$result['success']) {
            return new \WP_Error('approval_failed', $result['error'], ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    public function api_reject_action(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $data = $r->get_json_params();
        $result = ApprovalWorkflow::instance()->reject(
            (int) $r->get_param('id'),
            get_current_user_id(),
            $data['reason'] ?? null
        );
        if (!$result['success']) {
            return new \WP_Error('rejection_failed', $result['error'], ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    public function api_list_agents(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => AgentRuntime::instance()->list_agents(),
        ]);
    }

    public function api_deploy_agent(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = AgentRuntime::instance()->deploy($r->get_json_params());
        if (!$result['success']) {
            return new \WP_Error('deploy_failed', $result['error'], ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result], 201);
    }

    public function api_get_agent(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $agent = AgentRuntime::instance()->get_agent($r->get_param('agent_id'));
        if (!$agent) {
            return new \WP_Error('not_found', 'Agent not found', ['status' => 404]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $agent]);
    }

    public function api_stop_agent(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = AgentRuntime::instance()->stop($r->get_param('agent_id'));
        if (!$result['success']) {
            return new \WP_Error('stop_failed', $result['error'], ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    public function api_agent_execute(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = AgentRuntime::instance()->execute(
            $r->get_param('agent_id'),
            $r->get_json_params()
        );
        if (!$result['success']) {
            return new \WP_Error('execution_failed', $result['error'], ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    public function api_security_scan(\WP_REST_Request $r): \WP_REST_Response {
        $monitor = SecurityMonitor::instance();
        $results = $monitor->run_scan();
        $monitor->save_scan($results);
        return new \WP_REST_Response(['success' => true, 'data' => $results]);
    }

    public function api_security_status(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => SecurityMonitor::instance()->get_status(),
        ]);
    }

    public function api_performance_analyze(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => PerformanceOptimizer::instance()->analyze(),
        ]);
    }

    public function api_performance_optimize(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => PerformanceOptimizer::instance()->optimize_database(),
        ]);
    }

    public function api_list_integrations(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => IntegrationManager::instance()->list_all(),
        ]);
    }

    public function api_create_integration(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = IntegrationManager::instance()->register($r->get_json_params());
        if (!$result['success']) {
            return new \WP_Error('create_failed', $result['error'] ?? 'Unknown error', ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result], 201);
    }

    public function api_list_webhooks(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => WebhookManager::instance()->list_all(),
        ]);
    }

    public function api_create_webhook(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = WebhookManager::instance()->create($r->get_json_params());
        if (!$result['success']) {
            return new \WP_Error('create_failed', $result['error'] ?? 'Unknown error', ['status' => 400]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result], 201);
    }

    public function api_get_design_tokens(\WP_REST_Request $r): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => DesignSystemController::instance()->get_tokens(),
        ]);
    }

    public function api_update_design_tokens(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $result = DesignSystemController::instance()->update_tokens($r->get_json_params());
        if (!$result['success']) {
            return new \WP_Error('update_failed', 'Validation failed', ['status' => 400, 'errors' => $result['errors']]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    public function api_validate_css(\WP_REST_Request $r): \WP_REST_Response {
        $data = $r->get_json_params();
        return new \WP_REST_Response([
            'success' => true,
            'data' => DesignSystemController::instance()->validate_css($data['css'] ?? ''),
        ]);
    }

    /**
     * Pre-dispatch hook for tenant isolation and capability checks
     */
    public function pre_dispatch($result, $server, $request) {
        $route = $request->get_route();
        if (strpos($route, '/rjv-agi/v1/') !== 0) {
            return $result;
        }

        $trace_id = ReliabilityMonitor::instance()->begin_trace($request);

        // Initialize tenant context
        TenantIsolation::instance();

        $policy = $this->has_valid_policy_approval($request, $route)
            ? ['allowed' => true, 'requires_approval' => false, 'policy' => 'approved_override']
            : PolicyEngine::instance()->evaluate($request);
        if (($policy['allowed'] ?? true) !== true) {
            return new \WP_Error('policy_denied', $policy['reason'] ?? 'Request denied by policy', [
                'status' => 403,
                'trace_id' => $trace_id,
            ]);
        }

        if (($policy['requires_approval'] ?? false) === true) {
            $actionType = (($policy['escalated'] ?? false) === true) ? 'policy_escalation_request' : 'policy_guardrail_request';
            $approval = ApprovalWorkflow::instance()->submit($actionType, [
                'route' => $route,
                'method' => $request->get_method(),
                'params' => $request->get_json_params(),
                'trace_id' => $trace_id,
                'rule_id' => (string) ($policy['rule_id'] ?? ''),
                'rule_type' => (string) ($policy['rule_type'] ?? ''),
                'policy_reason' => (string) ($policy['reason'] ?? ''),
            ], 'api', 'agi');

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'requires_approval' => true,
                    'approval' => $approval,
                    'trace_id' => $trace_id,
                ],
            ], 202);
        }

        // Dispatch event for request
        EventDispatcher::instance()->dispatch('api.request', [
            'route' => $route,
            'method' => $request->get_method(),
            'trace_id' => $trace_id,
        ], 3);

        return $result;
    }

    public function post_dispatch($response, $server, $request) {
        $route = $request->get_route();
        if (strpos($route, '/rjv-agi/v1/') !== 0) {
            return $response;
        }

        $response = ReliabilityMonitor::instance()->attach_headers($response);
        return ContractManager::instance()->attach_headers($response, $route, (string) $request->get_method());
    }

    /**
     * Rate limiting
     */
    public function rate_limit($result, $server, $request) {
        $route = $request->get_route();
        if (strpos($route, '/rjv-agi/v1/') !== 0) {
            return $result;
        }

        $key = $request->get_header('X-RJV-AGI-Key');
        if (empty($key)) {
            return $result;
        }

        $tk = 'rjv_rl_' . hash('sha256', $key);
        $count = (int) get_transient($tk);
        $limit = (int) get_option('rjv_agi_rate_limit', 600);

        if ($count >= $limit) {
            return new \WP_Error('rate_limit', 'Rate limit exceeded', ['status' => 429]);
        }

        set_transient($tk, $count + 1, MINUTE_IN_SECONDS);
        return $result;
    }

    /**
     * Platform heartbeat
     */
    public function platform_heartbeat(): void {
        $connector = PlatformConnector::instance();
        if ($connector->is_configured()) {
            $connector->heartbeat();
        }
    }

    private function has_valid_policy_approval(\WP_REST_Request $request, string $route): bool {
        $approval_id = (int) $request->get_header('X-RJV-Approval-ID');
        if ($approval_id <= 0) {
            return false;
        }

        $item = ApprovalWorkflow::instance()->get_item($approval_id);
        if (!$item) {
            return false;
        }

        if (!in_array((string) ($item['action_type'] ?? ''), ['policy_guardrail_request', 'policy_escalation_request'], true)) {
            return false;
        }

        $status = (string) ($item['status'] ?? '');
        if (!in_array($status, ['approved', 'executed'], true)) {
            return false;
        }

        $actionData = is_array($item['action_data'] ?? null)
            ? $item['action_data']
            : (json_decode((string) ($item['action_data'] ?? ''), true) ?: []);

        return (string) ($actionData['route'] ?? '') === $route
            && strtoupper((string) ($actionData['method'] ?? '')) === strtoupper($request->get_method());
    }

    // Accessors for modules

    public function platform(): PlatformConnector {
        return $this->platform ?? PlatformConnector::instance();
    }

    public function gate(): CapabilityGate {
        return $this->gate ?? CapabilityGate::instance();
    }

    public function tenant(): TenantIsolation {
        return $this->tenant ?? TenantIsolation::instance();
    }

    public function versions(): VersionManager {
        return $this->versions ?? VersionManager::instance();
    }

    public function content(): ContentOperations {
        return $this->content ?? ContentOperations::instance();
    }

    public function design(): DesignSystemController {
        return $this->design ?? DesignSystemController::instance();
    }

    public function events(): EventDispatcher {
        return $this->events ?? EventDispatcher::instance();
    }

    public function goals(): GoalExecutor {
        return $this->goals ?? GoalExecutor::instance();
    }

    public function approvals(): ApprovalWorkflow {
        return $this->approvals ?? ApprovalWorkflow::instance();
    }

    public function agents(): AgentRuntime {
        return $this->agents ?? AgentRuntime::instance();
    }

    public function security(): SecurityMonitor {
        return $this->security ?? SecurityMonitor::instance();
    }

    public function access(): AccessControl {
        return $this->access ?? AccessControl::instance();
    }

    public function integrations(): IntegrationManager {
        return $this->integrations ?? IntegrationManager::instance();
    }

    public function webhooks(): WebhookManager {
        return $this->webhooks ?? WebhookManager::instance();
    }

    public function performance(): PerformanceOptimizer {
        return $this->performance ?? PerformanceOptimizer::instance();
    }

    public function program(): ProgramRegistry {
        return $this->program ?? ProgramRegistry::instance();
    }

    public function policy(): PolicyEngine {
        return $this->policy ?? PolicyEngine::instance();
    }

    public function contract(): ContractManager {
        return $this->contract ?? ContractManager::instance();
    }

    public function upgrade_safety(): UpgradeSafety {
        return $this->upgrade_safety ?? UpgradeSafety::instance();
    }

    public function reliability(): ReliabilityMonitor {
        return $this->reliability ?? ReliabilityMonitor::instance();
    }
}
