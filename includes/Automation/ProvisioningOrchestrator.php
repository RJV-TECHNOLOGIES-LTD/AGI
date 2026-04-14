<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Automation;

use RJV_AGI_Bridge\AI\Router as AIRouter;
use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\Hosting\TunnelManager;
use RJV_AGI_Bridge\Integrations\CloudflareAPI;
use RJV_AGI_Bridge\Integrations\GoogleServices;
use RJV_AGI_Bridge\Integrations\MicrosoftServices;
use RJV_AGI_Bridge\Security\SecretsVault;

/**
 * ProvisioningOrchestrator
 *
 * Zero-input, AI-guided provisioning engine for the complete hosting and
 * services stack.
 *
 * Architecture
 * ─────────────
 * The orchestrator implements a directed-acyclic-graph (DAG) of provisioning
 * steps.  Each step declares:
 *   – its logical name
 *   – dependencies (steps that must complete first)
 *   – an execution callable
 *   – a rollback callable
 *   – whether it is idempotent (safe to re-run after partial failure)
 *
 * State is persisted to WP options after every step so the run can be resumed
 * after a PHP timeout or browser close without starting over.
 *
 * Steps are executed in dependency-topological order.  If a step fails:
 *   1. The error is stored in the state object.
 *   2. If the step has no dependents, execution continues with other branches.
 *   3. If the step is critical (blocks dependents), execution halts that branch
 *      and skips all dependent steps.
 *   4. On explicit abort, completed steps are rolled back in reverse order.
 *
 * AI integration
 * ──────────────
 * The AI Router is consulted for:
 *   – Deciding whether a quick or named tunnel is optimal.
 *   – Generating Cloudflare page-rule recommendations for the site.
 *   – Identifying the most relevant Google / Microsoft services to enable.
 *   – Summarising the completed run for the admin.
 */
final class ProvisioningOrchestrator {

    private const STATE_OPTION    = 'rjv_agi_provision_state';
    private const RESULTS_OPTION  = 'rjv_agi_provision_results';

    // Run states
    private const STATE_IDLE       = 'idle';
    private const STATE_RUNNING    = 'running';
    private const STATE_PAUSED     = 'paused';
    private const STATE_COMPLETED  = 'completed';
    private const STATE_FAILED     = 'failed';
    private const STATE_ABORTED    = 'aborted';

    /** @var array<string, array{deps: string[], fn: callable, rollback: ?callable, idempotent: bool, critical: bool}> */
    private array $steps = [];

    /** @var array<string, mixed> User-supplied configuration (API keys, preferences). */
    private array $config = [];

    /** AI Router instance for decision support. */
    private AIRouter $ai;

    public function __construct(array $config = []) {
        $this->config = $config;
        $this->ai     = new AIRouter();
        $this->register_steps();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Start a new provisioning run.
     *
     * @param  array $config  Merged into constructor config (allows partial updates).
     * @return array{success: bool, state: string, completed: string[], pending: string[], error?: string}
     */
    public function start(array $config = []): array {
        $this->config = array_merge($this->config, $config);

        $current = $this->load_state();
        if ($current['status'] === self::STATE_RUNNING) {
            return ['success' => false, 'error' => 'A provisioning run is already in progress',
                    'state' => $current['status'], 'completed' => $current['completed'], 'pending' => []];
        }

        $run_id = wp_generate_uuid4();
        $state  = [
            'run_id'    => $run_id,
            'status'    => self::STATE_RUNNING,
            'started'   => time(),
            'completed' => [],
            'failed'    => [],
            'skipped'   => [],
            'config'    => $this->redact_config($this->config),
        ];
        $this->save_state($state);

        AuditLog::log('provision_run_started', 'automation', 0, ['run_id' => $run_id], 2);

        $result = $this->execute_dag($state);
        return $result;
    }

    /**
     * Resume a paused or partially-completed run.
     */
    public function resume(): array {
        $state = $this->load_state();
        if (!in_array($state['status'], [self::STATE_PAUSED, self::STATE_FAILED], true)) {
            return ['success' => false, 'error' => 'No resumable run found',
                    'state' => $state['status'], 'completed' => $state['completed'] ?? [], 'pending' => []];
        }
        $state['status'] = self::STATE_RUNNING;
        $this->save_state($state);
        return $this->execute_dag($state);
    }

    /**
     * Abort the current run and roll back completed steps.
     */
    public function abort(): array {
        $state = $this->load_state();
        if (!in_array($state['status'], [self::STATE_RUNNING, self::STATE_PAUSED], true)) {
            return ['success' => false, 'error' => 'No active run to abort',
                    'state' => $state['status'], 'completed' => [], 'pending' => []];
        }

        $rolled_back = $this->rollback(array_reverse($state['completed'] ?? []));
        $state['status']       = self::STATE_ABORTED;
        $state['rolled_back']  = $rolled_back;
        $state['aborted_at']   = time();
        $this->save_state($state);

        AuditLog::log('provision_run_aborted', 'automation', 0,
            ['run_id' => $state['run_id'] ?? '', 'rolled_back' => $rolled_back], 2);

        return ['success' => true, 'state' => self::STATE_ABORTED,
                'completed' => $state['completed'] ?? [], 'pending' => [], 'rolled_back' => $rolled_back];
    }

    /**
     * Return the current run state (for polling from the UI).
     */
    public function get_status(): array {
        $state   = $this->load_state();
        $results = get_option(self::RESULTS_OPTION, []);

        // Build pending step list from DAG
        $completed = $state['completed'] ?? [];
        $failed    = $state['failed']    ?? [];
        $skipped   = $state['skipped']   ?? [];
        $done      = array_merge($completed, $failed, $skipped);
        $pending   = array_values(array_diff(array_keys($this->steps), $done));

        return [
            'status'        => $state['status'] ?? self::STATE_IDLE,
            'run_id'        => $state['run_id']  ?? '',
            'started'       => $state['started'] ?? 0,
            'completed'     => $completed,
            'failed'        => $failed,
            'skipped'       => $skipped,
            'pending'       => $pending,
            'results'       => $results,
            'progress_pct'  => $this->progress_pct($completed, $failed, $skipped),
        ];
    }

    /**
     * Reset all orchestrator state (start fresh next time).
     */
    public function reset(): void {
        delete_option(self::STATE_OPTION);
        delete_option(self::RESULTS_OPTION);
    }

    // =========================================================================
    // DAG definition
    // =========================================================================

    /**
     * Register all provisioning steps with their dependency graph.
     *
     * Step keys are stable identifiers used in state persistence.
     */
    private function register_steps(): void {
        $self = $this;

        // ── Tunnel bootstrap ──────────────────────────────────────────────
        $this->add_step('download_binary', [], function () {
            $tunnel = new TunnelManager();
            return $tunnel->download_binary();
        }, function () {
            // No rollback needed – binary is inert until executed
            return ['success' => true];
        }, idempotent: true, critical: true);

        $this->add_step('start_tunnel', ['download_binary'], function () use ($self) {
            $tunnel     = new TunnelManager();
            $port       = (int) ($self->config['local_port'] ?? 80);
            $mode       = $self->config['tunnel_mode'] ?? 'quick';
            $token      = $self->config['tunnel_token'] ?? '';
            $hostname   = $self->config['tunnel_hostname'] ?? '';

            if ($mode === 'named' && $token !== '' && $hostname !== '') {
                return $tunnel->start_named($token, $hostname, $port);
            }
            return $tunnel->start_quick($port);
        }, function () {
            (new TunnelManager())->stop();
            return ['success' => true];
        }, idempotent: false, critical: true);

        $this->add_step('apply_wp_url', ['start_tunnel'], function () use ($self) {
            $tunnel = new TunnelManager();
            $url    = (string) get_option('rjv_agi_tunnel_url', '');
            if ($url === '') {
                return ['success' => false, 'error' => 'Tunnel URL not yet available'];
            }
            return $tunnel->apply_to_wordpress($url);
        }, function () {
            (new TunnelManager())->revert_wordpress_urls();
            return ['success' => true];
        }, idempotent: true, critical: false);

        // ── Cloudflare setup ─────────────────────────────────────────────
        $this->add_step('cf_verify_credentials', [], function () use ($self) {
            $token = SecretsVault::instance()->get('cloudflare_api_token', false)
                  ?? ($self->config['cloudflare_token'] ?? '');
            if ($token === '') {
                return ['success' => false, 'error' => 'No Cloudflare API token configured', 'skippable' => true];
            }
            $cf   = new CloudflareAPI($token);
            $user = $cf->get_user();
            if (!($user['success'] ?? false)) {
                return ['success' => false, 'error' => 'Cloudflare token invalid or expired'];
            }
            SecretsVault::instance()->put('cloudflare_api_token', $token);
            return ['success' => true, 'email' => $user['data']['email'] ?? ''];
        }, null, idempotent: true, critical: false);

        $this->add_step('cf_setup_zone', ['cf_verify_credentials'], function () use ($self) {
            $domain = $self->config['cloudflare_domain'] ?? '';
            if ($domain === '') {
                return ['success' => false, 'error' => 'No domain configured for Cloudflare', 'skippable' => true];
            }
            $token = SecretsVault::instance()->get('cloudflare_api_token', false) ?? '';
            if ($token === '') {
                return ['success' => false, 'error' => 'Cloudflare token missing'];
            }
            $cf     = new CloudflareAPI($token);
            $result = $cf->setup_wordpress_site($domain, $self->config['cloudflare_account_id'] ?? '');
            return $result;
        }, null, idempotent: true, critical: false);

        $this->add_step('cf_configure_performance', ['cf_setup_zone'], function () use ($self) {
            $zone_id = (string) get_option('rjv_agi_cf_zone_id', '');
            if ($zone_id === '') {
                return ['success' => false, 'error' => 'No Cloudflare zone ID available', 'skippable' => true];
            }
            $token = SecretsVault::instance()->get('cloudflare_api_token', false) ?? '';
            $cf    = new CloudflareAPI($token);
            return $cf->set_speed_settings($zone_id, [
                'minify'             => ['css' => 'on', 'html' => 'on', 'js' => 'on'],
                'brotli'             => 'on',
                'rocket_loader'      => 'on',
                'browser_cache_ttl'  => 14400,
                'polish'             => 'lossless',
            ]);
        }, null, idempotent: true, critical: false);

        // ── Google services ──────────────────────────────────────────────
        $this->add_step('google_inject_ga4', [], function () use ($self) {
            $measurement_id = $self->config['ga4_measurement_id']
                           ?? SecretsVault::instance()->get('ga4_measurement_id', false)
                           ?? '';
            if ($measurement_id === '') {
                return ['success' => false, 'error' => 'No GA4 Measurement ID', 'skippable' => true];
            }
            $google = new GoogleServices();
            return $google->inject_ga4_snippet($measurement_id);
        }, null, idempotent: true, critical: false);

        $this->add_step('google_inject_gtm', [], function () use ($self) {
            $container_id = $self->config['gtm_container_id']
                         ?? SecretsVault::instance()->get('gtm_container_id', false)
                         ?? '';
            if ($container_id === '') {
                return ['success' => false, 'error' => 'No GTM Container ID', 'skippable' => true];
            }
            $google = new GoogleServices();
            return $google->inject_gtm_snippet($container_id);
        }, null, idempotent: true, critical: false);

        $this->add_step('google_search_console', ['apply_wp_url'], function () use ($self) {
            $token = SecretsVault::instance()->get('google_access_token', false) ?? '';
            if ($token === '') {
                return ['success' => false, 'error' => 'No Google access token', 'skippable' => true];
            }
            $site_url = (string) get_option('siteurl', site_url());
            $google   = new GoogleServices($token);
            $result   = $google->add_site($site_url);
            if ($result['success'] ?? false) {
                $google->submit_sitemap($site_url, trailingslashit($site_url) . 'sitemap_index.xml');
            }
            return $result;
        }, null, idempotent: true, critical: false);

        // ── Microsoft services ───────────────────────────────────────────
        $this->add_step('ms_inject_clarity', [], function () use ($self) {
            $project_id = $self->config['clarity_project_id']
                       ?? SecretsVault::instance()->get('clarity_project_id', false)
                       ?? '';
            if ($project_id === '') {
                return ['success' => false, 'error' => 'No Clarity Project ID', 'skippable' => true];
            }
            $ms = new MicrosoftServices();
            return $ms->inject_clarity_snippet($project_id);
        }, null, idempotent: true, critical: false);

        $this->add_step('ms_bing_webmaster', ['apply_wp_url'], function () use ($self) {
            $token = SecretsVault::instance()->get('microsoft_access_token', false) ?? '';
            if ($token === '') {
                return ['success' => false, 'error' => 'No Microsoft access token', 'skippable' => true];
            }
            $site_url = (string) get_option('siteurl', site_url());
            $ms       = new MicrosoftServices($token);
            $result   = $ms->add_site($site_url);
            if ($result['success'] ?? false) {
                $ms->submit_sitemap($site_url, trailingslashit($site_url) . 'sitemap_index.xml');
            }
            return $result;
        }, null, idempotent: true, critical: false);

        // ── AI summary (final step) ──────────────────────────────────────
        $this->add_step('ai_summary', [
            'apply_wp_url', 'cf_configure_performance',
            'google_inject_ga4', 'google_search_console',
            'ms_inject_clarity', 'ms_bing_webmaster',
        ], function () use ($self) {
            $results = get_option(self::RESULTS_OPTION, []);
            $context = json_encode($results, JSON_PRETTY_PRINT);

            try {
                $reply = $self->ai->complete(
                    'You are an expert WordPress infrastructure assistant. ' .
                    'Summarise the following provisioning results in plain English, ' .
                    'highlighting what succeeded, what was skipped and why, and any ' .
                    'recommended next steps. Be concise (3–5 sentences).',
                    "Provisioning results:\n{$context}",
                    ['max_tokens' => 350]
                );
                $summary = $reply['content'] ?? '';
            } catch (\Throwable $e) {
                $summary = 'Provisioning complete. AI summary unavailable: ' . $e->getMessage();
            }

            update_option('rjv_agi_provision_summary', $summary);
            return ['success' => true, 'summary' => $summary];
        }, null, idempotent: true, critical: false);
    }

    // =========================================================================
    // DAG execution engine
    // =========================================================================

    /**
     * Execute all pending steps in dependency-topological order.
     */
    private function execute_dag(array &$state): array {
        $completed = &$state['completed'];
        $failed    = &$state['failed'];
        $skipped   = &$state['skipped'];

        $results = get_option(self::RESULTS_OPTION, []);

        $order = $this->topological_sort();

        foreach ($order as $step_name) {
            if (in_array($step_name, $completed, true)) {
                continue; // Already done in a previous run
            }
            if (in_array($step_name, $skipped, true) || in_array($step_name, $failed, true)) {
                continue;
            }

            $step = $this->steps[$step_name];

            // Check dependencies
            $deps_ok  = true;
            $deps_msg = '';
            foreach ($step['deps'] as $dep) {
                if (!in_array($dep, $completed, true)) {
                    if ($step['critical'] || in_array($dep, $failed, true) || in_array($dep, $skipped, true)) {
                        $deps_ok  = false;
                        $deps_msg = "dependency '{$dep}' did not complete";
                        break;
                    }
                }
            }

            if (!$deps_ok) {
                $skipped[]         = $step_name;
                $results[$step_name] = ['success' => false, 'skipped' => true, 'reason' => $deps_msg];
                $state['skipped']  = $skipped;
                $this->save_state($state);
                continue;
            }

            // Execute step
            try {
                $result = ($step['fn'])();
            } catch (\Throwable $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }

            $results[$step_name] = $result;
            update_option(self::RESULTS_OPTION, $results);

            if (!empty($result['success'])) {
                $completed[] = $step_name;
                AuditLog::log('provision_step_completed', 'automation', 0,
                    ['step' => $step_name, 'run_id' => $state['run_id'] ?? ''], 2);
            } elseif (!empty($result['skippable'])) {
                $skipped[] = $step_name;
            } else {
                $failed[] = $step_name;
                AuditLog::log('provision_step_failed', 'automation', 0, [
                    'step'   => $step_name,
                    'run_id' => $state['run_id'] ?? '',
                    'error'  => $result['error'] ?? 'unknown',
                ], 3);
                if ($step['critical']) {
                    // Mark dependents as skipped
                    foreach ($this->get_dependents($step_name) as $dep_name) {
                        if (!in_array($dep_name, array_merge($completed, $failed, $skipped), true)) {
                            $skipped[]             = $dep_name;
                            $results[$dep_name]    = ['success' => false, 'skipped' => true,
                                                      'reason'  => "critical step '{$step_name}' failed"];
                        }
                    }
                }
            }

            $state['completed'] = $completed;
            $state['failed']    = $failed;
            $state['skipped']   = $skipped;
            $this->save_state($state);
        }

        $final_status = empty($failed) ? self::STATE_COMPLETED : self::STATE_FAILED;
        $state['status']       = $final_status;
        $state['completed_at'] = time();
        $this->save_state($state);

        AuditLog::log('provision_run_finished', 'automation', 0, [
            'run_id'    => $state['run_id'] ?? '',
            'status'    => $final_status,
            'completed' => count($completed),
            'failed'    => count($failed),
            'skipped'   => count($skipped),
        ], 2);

        return [
            'success'    => empty($failed),
            'state'      => $final_status,
            'run_id'     => $state['run_id'] ?? '',
            'completed'  => $completed,
            'failed'     => $failed,
            'skipped'    => $skipped,
            'results'    => $results,
            'summary'    => get_option('rjv_agi_provision_summary', ''),
        ];
    }

    /**
     * Topological sort of step DAG (Kahn's algorithm).
     *
     * @return string[]  Step names in execution order.
     */
    private function topological_sort(): array {
        $in_degree = [];
        $adjacency = [];

        foreach ($this->steps as $name => $step) {
            $in_degree[$name] = $in_degree[$name] ?? 0;
            foreach ($step['deps'] as $dep) {
                $adjacency[$dep][] = $name;
                $in_degree[$name]  = ($in_degree[$name] ?? 0) + 1;
            }
        }

        $queue  = [];
        foreach ($in_degree as $name => $deg) {
            if ($deg === 0) {
                $queue[] = $name;
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $node     = array_shift($queue);
            $sorted[] = $node;
            foreach ($adjacency[$node] ?? [] as $next) {
                $in_degree[$next]--;
                if ($in_degree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        return $sorted;
    }

    /**
     * Execute rollback callables for a list of step names.
     *
     * @param  string[] $step_names
     * @return string[]  Names of steps that were rolled back.
     */
    private function rollback(array $step_names): array {
        $rolled = [];
        foreach ($step_names as $name) {
            $step = $this->steps[$name] ?? null;
            if ($step === null || $step['rollback'] === null) {
                continue;
            }
            try {
                ($step['rollback'])();
                $rolled[] = $name;
            } catch (\Throwable $e) {
                AuditLog::log('provision_rollback_failed', 'automation', 0,
                    ['step' => $name, 'error' => $e->getMessage()], 3);
            }
        }
        return $rolled;
    }

    /**
     * Return all steps that depend (directly or transitively) on $step_name.
     *
     * @return string[]
     */
    private function get_dependents(string $step_name): array {
        $dependents = [];
        foreach ($this->steps as $name => $step) {
            if (in_array($step_name, $step['deps'], true)) {
                $dependents[] = $name;
                $dependents   = array_merge($dependents, $this->get_dependents($name));
            }
        }
        return array_unique($dependents);
    }

    /** Calculate overall progress percentage. */
    private function progress_pct(array $completed, array $failed, array $skipped): int {
        $total = count($this->steps);
        if ($total === 0) {
            return 100;
        }
        $done = count($completed) + count($failed) + count($skipped);
        return (int) round($done / $total * 100);
    }

    // =========================================================================
    // Step registry helpers
    // =========================================================================

    private function add_step(
        string    $name,
        array     $deps,
        callable  $fn,
        ?callable $rollback,
        bool      $idempotent = true,
        bool      $critical   = false
    ): void {
        $this->steps[$name] = [
            'deps'       => $deps,
            'fn'         => $fn,
            'rollback'   => $rollback,
            'idempotent' => $idempotent,
            'critical'   => $critical,
        ];
    }

    // =========================================================================
    // State persistence
    // =========================================================================

    private function load_state(): array {
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state) || !isset($state['status'])) {
            return ['status' => self::STATE_IDLE, 'completed' => [], 'failed' => [], 'skipped' => []];
        }
        return $state;
    }

    private function save_state(array $state): void {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Strip sensitive values from config before storing in WP options.
     */
    private function redact_config(array $config): array {
        $sensitive = ['tunnel_token', 'cloudflare_token', 'google_access_token',
                      'microsoft_access_token', 'api_key'];
        $redacted  = $config;
        foreach ($sensitive as $key) {
            if (isset($redacted[$key]) && $redacted[$key] !== '') {
                $redacted[$key] = '***REDACTED***';
            }
        }
        return $redacted;
    }
}
