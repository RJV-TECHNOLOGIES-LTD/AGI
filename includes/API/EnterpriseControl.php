<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\Bridge\CapabilityGate;
use RJV_AGI_Bridge\Execution\ExecutionLedger;
use RJV_AGI_Bridge\Governance\PolicyEngine;
use RJV_AGI_Bridge\Governance\ProgramRegistry;
use RJV_AGI_Bridge\Governance\ContractManager;
use RJV_AGI_Bridge\Governance\UpgradeSafety;
use RJV_AGI_Bridge\Observability\ReliabilityMonitor;
use RJV_AGI_Bridge\Security\ComplianceManager;

/**
 * Enterprise control-plane API for governance, capabilities, and observability.
 */
final class EnterpriseControl extends Base {
    public function register_routes(): void {
        // Program scope and targets
        register_rest_route($this->namespace, '/program/scope', [
            ['methods' => 'GET', 'callback' => [$this, 'get_program_scope'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_program_scope'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/program/targets', [
            ['methods' => 'GET', 'callback' => [$this, 'get_program_targets'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_program_targets'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/program/contracts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_program_contracts'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/program/milestones', [
            ['methods' => 'GET', 'callback' => [$this, 'list_milestones'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'add_milestone'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/program/audit', [
            'methods' => 'GET',
            'callback' => [$this, 'get_program_audit'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Governance policies
        register_rest_route($this->namespace, '/governance/policies', [
            ['methods' => 'GET', 'callback' => [$this, 'get_policies'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_policies'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/governance/evaluate', [
            'methods' => 'POST',
            'callback' => [$this, 'evaluate_policy'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/governance/contracts', [
            ['methods' => 'GET', 'callback' => [$this, 'get_contract'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_contract'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/governance/deprecations', [
            ['methods' => 'GET', 'callback' => [$this, 'list_deprecations'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'replace_deprecations'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/governance/upgrade/status', [
            'methods' => 'GET',
            'callback' => [$this, 'upgrade_status'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Capabilities by environment
        register_rest_route($this->namespace, '/capabilities/effective', [
            'methods' => 'GET',
            'callback' => [$this, 'get_effective_capabilities'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/capabilities/overrides', [
            ['methods' => 'GET', 'callback' => [$this, 'get_capability_overrides'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_capability_overrides'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/capabilities/plans', [
            ['methods' => 'GET', 'callback' => [$this, 'get_plan_overrides'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_plan_overrides'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Observability and reliability
        register_rest_route($this->namespace, '/observability/slo', [
            'methods' => 'GET',
            'callback' => [$this, 'get_slo_status'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/observability/drift', [
            'methods' => 'GET',
            'callback' => [$this, 'get_drift_report'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/observability/baseline', [
            'methods' => 'POST',
            'callback' => [$this, 'snapshot_baseline'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);
        register_rest_route($this->namespace, '/observability/anomalies', [
            'methods' => 'GET',
            'callback' => [$this, 'get_anomalies'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/observability/error-budget', [
            'methods' => 'GET',
            'callback' => [$this, 'get_error_budget'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/observability/alerts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_alerts'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/observability/playbooks', [
            'methods' => 'GET',
            'callback' => [$this, 'get_playbooks'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/observability/release-gates', [
            'methods' => 'GET',
            'callback' => [$this, 'get_release_gates'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Deterministic execution ledger
        register_rest_route($this->namespace, '/execution/ledger', [
            'methods' => 'GET',
            'callback' => [$this, 'list_execution_ledger'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
        register_rest_route($this->namespace, '/execution/ledger/(?P<execution_id>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'replay_execution'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // Security/compliance baseline
        register_rest_route($this->namespace, '/security/threat-controls', [
            ['methods' => 'GET', 'callback' => [$this, 'get_threat_controls'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_threat_controls'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/security/compliance', [
            ['methods' => 'GET', 'callback' => [$this, 'get_compliance_controls'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_compliance_controls'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/security/legal-hold', [
            'methods' => 'POST',
            'callback' => [$this, 'apply_legal_hold'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);
        register_rest_route($this->namespace, '/security/rotate-secret', [
            'methods' => 'POST',
            'callback' => [$this, 'rotate_secret'],
            'permission_callback' => [Auth::class, 'tier3'],
        ]);
        register_rest_route($this->namespace, '/security/compliance/export', [
            'methods' => 'GET',
            'callback' => [$this, 'export_compliance_snapshot'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
    }

    public function get_program_scope(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['taxonomy' => ProgramRegistry::instance()->get_scope_taxonomy()]);
    }

    public function update_program_scope(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $data = $request->get_json_params();
        $result = ProgramRegistry::instance()->update_scope_taxonomy((array) ($data['taxonomy'] ?? []));
        if (!$result['success']) {
            return $this->error($result['error'], 400);
        }
        return $this->success($result);
    }

    public function get_program_targets(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['targets' => ProgramRegistry::instance()->get_targets()]);
    }

    public function update_program_targets(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $data = $request->get_json_params();
        $result = ProgramRegistry::instance()->update_targets((array) ($data['targets'] ?? []));
        if (!$result['success']) {
            return $this->error($result['error'], 400);
        }
        return $this->success($result);
    }

    public function get_program_contracts(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['contracts' => ProgramRegistry::instance()->get_contract()]);
    }

    public function get_program_audit(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['audit' => ProgramRegistry::instance()->architecture_audit()]);
    }

    public function list_milestones(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['milestones' => ProgramRegistry::instance()->list_milestones()]);
    }

    public function add_milestone(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $result = ProgramRegistry::instance()->add_milestone((array) $request->get_json_params());
        if (!$result['success']) {
            return $this->error($result['error'], 400);
        }
        return $this->success($result, 201);
    }

    public function get_policies(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['policies' => PolicyEngine::instance()->get_policies()]);
    }

    public function update_policies(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $result = PolicyEngine::instance()->update_policies((array) $request->get_json_params());
        if (!$result['success']) {
            return $this->error($result['error'], 400);
        }
        return $this->success($result);
    }

    public function evaluate_policy(\WP_REST_Request $request): \WP_REST_Response {
        $payload = (array) $request->get_json_params();
        $targetRoute = sanitize_text_field((string) ($payload['route'] ?? ''));
        $targetMethod = sanitize_text_field((string) ($payload['method'] ?? 'GET'));
        $virtual = new \WP_REST_Request($targetMethod, $targetRoute);
        return $this->success(['evaluation' => PolicyEngine::instance()->evaluate($virtual)]);
    }

    public function get_contract(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['contract' => ContractManager::instance()->get_contract()]);
    }

    public function update_contract(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(ContractManager::instance()->update_contract((array) $request->get_json_params()));
    }

    public function list_deprecations(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['deprecations' => ContractManager::instance()->list_deprecations()]);
    }

    public function replace_deprecations(\WP_REST_Request $request): \WP_REST_Response {
        $data = (array) $request->get_json_params();
        return $this->success(ContractManager::instance()->replace_deprecations((array) ($data['deprecations'] ?? [])));
    }

    public function upgrade_status(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['upgrade' => UpgradeSafety::instance()->status()]);
    }

    public function get_effective_capabilities(\WP_REST_Request $request): \WP_REST_Response {
        $environment = sanitize_key((string) $request->get_param('environment'));
        return $this->success([
            'environment' => $environment !== '' ? $environment : (function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production'),
            'capabilities' => CapabilityGate::instance()->get_effective_capabilities($environment !== '' ? $environment : null),
        ]);
    }

    public function get_capability_overrides(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['overrides' => CapabilityGate::instance()->get_environment_overrides()]);
    }

    public function update_capability_overrides(\WP_REST_Request $request): \WP_REST_Response {
        $updated = CapabilityGate::instance()->update_environment_overrides((array) $request->get_json_params());
        return $this->success(['overrides' => $updated]);
    }

    public function get_plan_overrides(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['plans' => CapabilityGate::instance()->get_plan_overrides()]);
    }

    public function update_plan_overrides(\WP_REST_Request $request): \WP_REST_Response {
        $updated = CapabilityGate::instance()->update_plan_overrides((array) $request->get_json_params());
        return $this->success(['plans' => $updated]);
    }

    public function get_slo_status(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['slo' => ReliabilityMonitor::instance()->slo_status()]);
    }

    public function get_drift_report(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['drift' => ReliabilityMonitor::instance()->drift_report()]);
    }

    public function snapshot_baseline(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(ReliabilityMonitor::instance()->snapshot_baseline(), 201);
    }

    public function get_anomalies(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['anomalies' => ReliabilityMonitor::instance()->anomaly_report()]);
    }

    public function get_error_budget(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['error_budget' => ReliabilityMonitor::instance()->error_budget_status()]);
    }

    public function get_alerts(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['alerts' => ReliabilityMonitor::instance()->alerts()]);
    }

    public function get_playbooks(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['playbooks' => ReliabilityMonitor::instance()->remediation_playbooks()]);
    }

    public function get_release_gates(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['release_gates' => ReliabilityMonitor::instance()->release_gates_status()]);
    }

    public function list_execution_ledger(\WP_REST_Request $request): \WP_REST_Response {
        $limit = (int) ($request->get_param('limit') ?? 50);
        return $this->success(['executions' => ExecutionLedger::instance()->list_recent($limit)]);
    }

    public function replay_execution(\WP_REST_Request $request): \WP_REST_Response {
        $id = sanitize_key((string) $request->get_param('execution_id'));
        return $this->success(['replay' => ExecutionLedger::instance()->replay_execution($id)]);
    }

    public function get_threat_controls(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['threat_controls' => ComplianceManager::instance()->threat_controls()]);
    }

    public function update_threat_controls(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(ComplianceManager::instance()->update_threat_controls((array) $request->get_json_params()));
    }

    public function get_compliance_controls(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['compliance_controls' => ComplianceManager::instance()->compliance_controls()]);
    }

    public function update_compliance_controls(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(ComplianceManager::instance()->update_compliance_controls((array) $request->get_json_params()));
    }

    public function apply_legal_hold(\WP_REST_Request $request): \WP_REST_Response {
        $payload = (array) $request->get_json_params();
        return $this->success(ComplianceManager::instance()->apply_legal_hold((bool) ($payload['enabled'] ?? false), (string) ($payload['reason'] ?? '')));
    }

    public function rotate_secret(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $payload = (array) $request->get_json_params();
        $result = ComplianceManager::instance()->rotate_secret(
            sanitize_key((string) ($payload['secret_key'] ?? '')),
            (string) ($payload['new_secret'] ?? ''),
            'api_user_' . get_current_user_id()
        );
        if (($result['success'] ?? false) !== true) {
            return $this->error((string) ($result['error'] ?? 'Rotation failed'), 400);
        }
        return $this->success($result);
    }

    public function export_compliance_snapshot(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['snapshot' => ComplianceManager::instance()->export_compliance_snapshot()]);
    }
}
