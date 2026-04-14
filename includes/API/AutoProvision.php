<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Automation\ProvisioningOrchestrator;
use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Hosting\TunnelHealthMonitor;
use RJV_AGI_Bridge\Security\SecretsVault;

/**
 * AutoProvision REST Controller
 *
 * Single-endpoint orchestration interface for the ProvisioningOrchestrator.
 * Exposes four operations:
 *
 *   POST /rjv-agi/v1/auto-provision/start
 *     Begin a new provisioning run.  Accepts optional credential and
 *     preference fields.  Any provided API keys are stored via SecretsVault
 *     before the run starts.
 *
 *   GET  /rjv-agi/v1/auto-provision/status
 *     Poll the current run's progress (completed steps, failures, percentage).
 *
 *   POST /rjv-agi/v1/auto-provision/resume
 *     Resume a paused or failed run from the last successful step.
 *
 *   POST /rjv-agi/v1/auto-provision/abort
 *     Abort the current run and roll back completed steps.
 *
 *   DELETE /rjv-agi/v1/auto-provision/reset
 *     Wipe all orchestrator state so a fresh run can be initiated.
 *
 * Design principles:
 *   - Minimal required input: all fields are optional and skipped gracefully.
 *   - No sensitive values are echoed back in responses.
 *   - All credential fields are stored via SecretsVault before use.
 *   - Rate-limited to 10 start/resume/abort calls per hour per admin.
 */
class AutoProvision extends Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route($ns, '/auto-provision/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start'],
            'permission_callback' => [$this, 'admin_only'],
            'args'                => $this->start_args(),
        ]);

        register_rest_route($ns, '/auto-provision/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_status'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/auto-provision/resume', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resume'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/auto-provision/abort', [
            'methods'             => 'POST',
            'callback'            => [$this, 'abort'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/auto-provision/reset', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'reset'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/auto-provision/monitor-status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'monitor_status'],
            'permission_callback' => [$this, 'admin_only'],
        ]);

        register_rest_route($ns, '/auto-provision/monitor-reset', [
            'methods'             => 'POST',
            'callback'            => [$this, 'monitor_reset'],
            'permission_callback' => [$this, 'admin_only'],
        ]);
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    /**
     * Start a new provisioning run.
     */
    public function start(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        if (!$this->rate_limit_check('provision_start', 10, 3600)) {
            return $this->error('Rate limit exceeded. A maximum of 10 start requests per hour is allowed.', 429);
        }

        // Persist any provided credentials to the vault before the run
        $vault = SecretsVault::instance();
        $this->store_credentials($req, $vault);

        // Build config from non-sensitive fields
        $config = $this->extract_config($req);

        $orchestrator = new ProvisioningOrchestrator($config);
        $result       = $orchestrator->start($config);

        AuditLog::log('auto_provision_started', 'automation', get_current_user_id(), [], 2);

        return $this->success($this->sanitise_result($result));
    }

    /**
     * Poll the current run status.
     */
    public function get_status(\WP_REST_Request $req): \WP_REST_Response {
        $orchestrator = new ProvisioningOrchestrator();
        return $this->success($orchestrator->get_status());
    }

    /**
     * Resume a paused / failed run.
     */
    public function resume(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        if (!$this->rate_limit_check('provision_start', 10, 3600)) {
            return $this->error('Rate limit exceeded.', 429);
        }

        // Allow updated credentials on resume
        $vault = SecretsVault::instance();
        $this->store_credentials($req, $vault);

        $orchestrator = new ProvisioningOrchestrator($this->extract_config($req));
        return $this->success($this->sanitise_result($orchestrator->resume()));
    }

    /**
     * Abort and roll back.
     */
    public function abort(\WP_REST_Request $req): \WP_REST_Response {
        $orchestrator = new ProvisioningOrchestrator();
        return $this->success($orchestrator->abort());
    }

    /**
     * Reset orchestrator state.
     */
    public function reset(\WP_REST_Request $req): \WP_REST_Response {
        $orchestrator = new ProvisioningOrchestrator();
        $orchestrator->reset();
        AuditLog::log('auto_provision_reset', 'automation', get_current_user_id(), [], 2);
        return $this->success(['reset' => true]);
    }

    /**
     * Health monitor status.
     */
    public function monitor_status(\WP_REST_Request $req): \WP_REST_Response {
        $monitor = new TunnelHealthMonitor();
        return $this->success($monitor->monitor_status());
    }

    /**
     * Reset health monitor failure state.
     */
    public function monitor_reset(\WP_REST_Request $req): \WP_REST_Response {
        $monitor = new TunnelHealthMonitor();
        $monitor->reset_failures();
        return $this->success(['reset' => true]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Store sensitive credential fields in SecretsVault.
     * None of these values are retained in plain-text or echoed.
     */
    private function store_credentials(\WP_REST_Request $req, SecretsVault $vault): void {
        $credential_map = [
            'cloudflare_token'         => 'cloudflare_api_token',
            'google_access_token'      => 'google_access_token',
            'microsoft_access_token'   => 'microsoft_access_token',
            'tunnel_token'             => 'tunnel_named_token',
            'ga4_measurement_id'       => 'ga4_measurement_id',
            'gtm_container_id'         => 'gtm_container_id',
            'clarity_project_id'       => 'clarity_project_id',
        ];
        foreach ($credential_map as $param => $vault_key) {
            $val = (string) ($req->get_param($param) ?? '');
            if ($val !== '') {
                $vault->put($vault_key, $val);
            }
        }
    }

    /**
     * Extract non-sensitive configuration fields.
     */
    private function extract_config(\WP_REST_Request $req): array {
        return array_filter([
            'local_port'             => (int) ($req->get_param('local_port') ?? 80),
            'tunnel_mode'            => sanitize_text_field((string) ($req->get_param('tunnel_mode') ?? 'quick')),
            'tunnel_hostname'        => sanitize_text_field((string) ($req->get_param('tunnel_hostname') ?? '')),
            'cloudflare_domain'      => sanitize_text_field((string) ($req->get_param('cloudflare_domain') ?? '')),
            'cloudflare_account_id'  => sanitize_text_field((string) ($req->get_param('cloudflare_account_id') ?? '')),
        ], fn ($v) => $v !== '' && $v !== 0);
    }

    /**
     * Remove any sensitive or internal fields from the result before returning.
     */
    private function sanitise_result(array $result): array {
        unset($result['config']);
        return $result;
    }

    /**
     * Simple sliding-window rate limiter using WP transients.
     *
     * @param string $key       Unique action identifier.
     * @param int    $max_hits  Maximum allowed calls within the window.
     * @param int    $window    Window length in seconds.
     */
    private function rate_limit_check(string $key, int $max_hits, int $window): bool {
        $user_id      = get_current_user_id();
        $transient    = 'rjv_agi_rl_' . md5($key . '_' . $user_id);
        $hits         = (int) get_transient($transient);
        if ($hits >= $max_hits) {
            return false;
        }
        set_transient($transient, $hits + 1, $window);
        return true;
    }

    /**
     * Argument schema for the start endpoint.
     */
    private function start_args(): array {
        return [
            // Tunnel
            'local_port'            => ['type' => 'integer', 'default' => 80,
                                        'minimum' => 1, 'maximum' => 65535],
            'tunnel_mode'           => ['type' => 'string',  'default' => 'quick',
                                        'enum' => ['quick', 'named']],
            'tunnel_hostname'       => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'tunnel_token'          => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],

            // Cloudflare
            'cloudflare_token'      => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'cloudflare_domain'     => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'cloudflare_account_id' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],

            // Google
            'google_access_token'   => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'ga4_measurement_id'    => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'gtm_container_id'      => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],

            // Microsoft
            'microsoft_access_token'=> ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'clarity_project_id'    => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
        ];
    }
}
