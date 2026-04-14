<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Governance;

use RJV_AGI_Bridge\Bridge\TenantIsolation;

/**
 * Enterprise program registry for product scope, acceptance targets, and milestones.
 */
final class ProgramRegistry {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function get_scope_taxonomy(): array {
        $default = [
            'Core Ops',
            'AI Orchestration',
            'Security',
            'Compliance',
            'Enterprise Integrations',
            'Governance',
            'Observability',
            'Admin UX',
            'Platform Controls',
        ];
        $value = TenantIsolation::instance()->get_option('rjv_agi_program_scope_taxonomy', $default);
        return is_array($value) && !empty($value) ? array_values($value) : $default;
    }

    public function update_scope_taxonomy(array $taxonomy): array {
        $clean = [];
        foreach ($taxonomy as $item) {
            $item = sanitize_text_field((string) $item);
            if ($item !== '') {
                $clean[] = $item;
            }
        }
        $clean = array_values(array_unique($clean));
        if (count($clean) < 3) {
            return ['success' => false, 'error' => 'At least 3 taxonomy groups are required'];
        }

        TenantIsolation::instance()->set_option('rjv_agi_program_scope_taxonomy', $clean);
        return ['success' => true, 'taxonomy' => $clean];
    }

    public function get_targets(): array {
        $default = [
            'availability_slo' => 99.9,
            'api_coverage_pct' => 95.0,
            'change_failure_rate_pct' => 5.0,
            'p95_latency_ms' => 800,
            'rollback_readiness_pct' => 100.0,
            'security_patch_sla_hours' => 24,
        ];
        $value = TenantIsolation::instance()->get_option('rjv_agi_program_targets', $default);
        return is_array($value) ? array_merge($default, $value) : $default;
    }

    public function update_targets(array $targets): array {
        $existing = $this->get_targets();
        $updated = [];
        foreach ($existing as $metric => $current) {
            if (!array_key_exists($metric, $targets)) {
                $updated[$metric] = $current;
                continue;
            }
            $value = is_numeric($targets[$metric]) ? (float) $targets[$metric] : null;
            if ($value === null || $value < 0) {
                return ['success' => false, 'error' => "Invalid target for {$metric}"];
            }
            $updated[$metric] = $value;
        }

        TenantIsolation::instance()->set_option('rjv_agi_program_targets', $updated);
        return ['success' => true, 'targets' => $updated];
    }

    public function get_contract(): array {
        return ContractManager::instance()->get_contract();
    }

    public function list_milestones(): array {
        $milestones = TenantIsolation::instance()->get_option('rjv_agi_program_milestones', []);
        return is_array($milestones) ? array_values($milestones) : [];
    }

    public function add_milestone(array $milestone): array {
        $id = sanitize_key((string) ($milestone['id'] ?? ''));
        $title = sanitize_text_field((string) ($milestone['title'] ?? ''));
        $module = sanitize_text_field((string) ($milestone['module'] ?? ''));
        $status = sanitize_key((string) ($milestone['status'] ?? 'planned'));
        $dod = isset($milestone['definition_of_done']) && is_array($milestone['definition_of_done'])
            ? array_values(array_filter(array_map(static fn($v) => sanitize_text_field((string) $v), $milestone['definition_of_done'])))
            : [];

        if ($id === '' || $title === '' || $module === '' || empty($dod)) {
            return ['success' => false, 'error' => 'Milestone id, title, module, and definition_of_done are required'];
        }

        $allowed_status = ['planned', 'in_progress', 'blocked', 'complete'];
        if (!in_array($status, $allowed_status, true)) {
            return ['success' => false, 'error' => 'Invalid milestone status'];
        }

        $milestones = $this->list_milestones();
        foreach ($milestones as $existing) {
            if (($existing['id'] ?? '') === $id) {
                return ['success' => false, 'error' => 'Milestone id already exists'];
            }
        }

        $record = [
            'id' => $id,
            'title' => $title,
            'module' => $module,
            'status' => $status,
            'definition_of_done' => $dod,
            'created_at' => gmdate('c'),
        ];
        $milestones[] = $record;
        TenantIsolation::instance()->set_option('rjv_agi_program_milestones', $milestones);

        return ['success' => true, 'milestone' => $record];
    }

    public function architecture_audit(): array {
        $route_count = 0;
        $routes_by_prefix = [];
        if (function_exists('rest_get_server')) {
            $server = rest_get_server();
            if ($server && method_exists($server, 'get_routes')) {
                $routes = $server->get_routes();
                $route_count = count($routes);
                foreach (array_keys($routes) as $route) {
                    if (strpos($route, '/rjv-agi/v1/') !== 0) {
                        continue;
                    }
                    $parts = explode('/', trim($route, '/'));
                    $prefix = $parts[2] ?? 'root';
                    $routes_by_prefix[$prefix] = ($routes_by_prefix[$prefix] ?? 0) + 1;
                }
            }
        }

        $module_files = $this->module_file_inventory();

        return [
            'generated_at' => gmdate('c'),
            'namespace' => 'rjv-agi/v1',
            'api_routes_registered' => $route_count,
            'route_groups' => $routes_by_prefix,
            'module_inventory' => $module_files,
            'code_paths' => [
                'bootstrap' => 'rjv-agi-bridge.php -> includes/Plugin.php',
                'api_dispatch' => 'Plugin::pre_dispatch -> PolicyEngine/CapabilityGate -> API controller callbacks -> Plugin::post_dispatch',
                'upgrade_path' => 'Installer::maybe_upgrade -> UpgradeSafety -> create_tables/set_defaults',
                'execution_path' => 'GoalExecutor/AgentRuntime/ApprovalWorkflow -> ExecutionLedger -> AuditLog',
            ],
        ];
    }

    private function module_file_inventory(): array {
        $base = trailingslashit(RJV_AGI_PLUGIN_DIR . 'includes');
        $dirs = ['API', 'Bridge', 'Execution', 'Governance', 'Security', 'Observability', 'Integration', 'Content', 'Agent', 'AI', 'Performance', 'Admin', 'Design', 'Events'];
        $inventory = [];
        foreach ($dirs as $dir) {
            $path = $base . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $files = glob($path . '/*.php');
            $inventory[$dir] = is_array($files) ? count($files) : 0;
        }
        return $inventory;
    }
}
