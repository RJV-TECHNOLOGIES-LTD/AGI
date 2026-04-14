<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\Bridge\CapabilityGate;
use RJV_AGI_Bridge\Governance\PolicyEngine;
use RJV_AGI_Bridge\Governance\ProgramRegistry;
use RJV_AGI_Bridge\Observability\ReliabilityMonitor;

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

    public function get_slo_status(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['slo' => ReliabilityMonitor::instance()->slo_status()]);
    }

    public function get_drift_report(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(['drift' => ReliabilityMonitor::instance()->drift_report()]);
    }

    public function snapshot_baseline(\WP_REST_Request $request): \WP_REST_Response {
        return $this->success(ReliabilityMonitor::instance()->snapshot_baseline(), 201);
    }
}
