<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Tests;

require_once __DIR__ . '/support/FakeWpdb.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RJV_AGI_Bridge\API\EnterpriseControl;
use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\Bridge\CapabilityGate;
use RJV_AGI_Bridge\Execution\ApprovalWorkflow;
use RJV_AGI_Bridge\Execution\ExecutionLedger;
use RJV_AGI_Bridge\Execution\GoalExecutor;
use RJV_AGI_Bridge\Governance\PolicyEngine;
use RJV_AGI_Bridge\Installer;
use RJV_AGI_Bridge\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * RR-PACK-1/2/3: authenticated fail-opens on the existing gates.
 */
final class FailClosedGatesTest extends TestCase {
    private FakeWpdb $wpdb;

    protected function setUp(): void {
        $GLOBALS['_options'] = [];
        $GLOBALS['_transients'] = [];
        $GLOBALS['_wp_mutations'] = [];
        $GLOBALS['_registered_routes'] = [];

        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        foreach ([
            CapabilityGate::class,
            GoalExecutor::class,
            PolicyEngine::class,
            ApprovalWorkflow::class,
            ExecutionLedger::class,
            \RJV_AGI_Bridge\Bridge\PlatformConnector::class,
            \RJV_AGI_Bridge\Bridge\TenantIsolation::class,
            \RJV_AGI_Bridge\Events\EventDispatcher::class,
            \RJV_AGI_Bridge\Observability\ReliabilityMonitor::class,
        ] as $class) {
            $this->reset_singleton($class);
        }
    }

    public function test_unmapped_actions_are_denied_and_audited(): void {
        $gate = CapabilityGate::instance();

        foreach (['set_theme_mod', 'add_menu_item', 'apply_seo', 'update_option', 'not_a_real_action'] as $action) {
            $this->assertFalse($gate->can($action), $action);
        }

        $denies = array_values(array_filter(
            $this->wpdb->audit_entries(),
            static fn(array $entry): bool => $entry['action'] === 'capability_denied' && $entry['status'] === 'error'
        ));
        $this->assertCount(5, $denies);
        foreach ($denies as $entry) {
            $this->assertSame('unmapped_action', $entry['details']['reason'] ?? null);
        }
    }

    public function test_inert_actions_are_allowlisted_and_mutating_goal_types_are_not_default_allowed(): void {
        $gate = CapabilityGate::instance();
        $this->assertTrue($gate->can('wait'));
        $this->assertTrue($gate->can('conditional'));

        $mapped = $this->mapped_actions($gate);
        $this->assertContains('create_post', $mapped);
        $this->assertContains('deploy_agent', $mapped);
        $this->assertNotContains('set_theme_mod', $mapped);
        $this->assertNotContains('add_menu_item', $mapped);
        $this->assertNotContains('apply_seo', $mapped);
        $this->assertNotContains('wait', $mapped);
        $this->assertNotContains('conditional', $mapped);

        $source = (string) file_get_contents(dirname(__DIR__) . '/includes/Execution/GoalExecutor.php');
        preg_match_all("/'([a-z0-9_]+)' => \\\$this->action_/", $source, $matches);
        $executorTypes = $matches[1];
        $this->assertNotEmpty($executorTypes);

        foreach ($executorTypes as $type) {
            if (in_array($type, ['wait', 'conditional'], true)) {
                $this->assertTrue($gate->can($type), $type);
                continue;
            }
            if (!in_array($type, $mapped, true)) {
                $this->assertFalse($gate->can($type), $type . ' must fail closed');
            }
        }
    }

    public function test_create_post_and_deploy_agent_stay_plan_gated(): void {
        $gate = CapabilityGate::instance();

        $this->assertTrue($gate->can('create_post'));
        $this->assertFalse($gate->can('deploy_agent'));

        $gate->update_environment_overrides([
            'production' => ['disabled' => ['content_management']],
        ]);
        $this->assertFalse($gate->can('create_post'));

        $this->reset_singleton(CapabilityGate::class);
        $GLOBALS['_options'] = [];
        $gate = CapabilityGate::instance();
        $gate->update_environment_overrides([
            'production' => ['enabled' => ['agent_execution']],
        ]);
        $this->assertTrue($gate->can('deploy_agent'));

        $planDenies = array_values(array_filter(
            $this->wpdb->audit_entries(),
            static fn(array $entry): bool => ($entry['details']['action'] ?? '') === 'deploy_agent'
                && ($entry['details']['reason'] ?? '') !== 'unmapped_action'
                && ($entry['details']['capability'] ?? '') === 'agent_execution'
        ));
        $this->assertNotEmpty($planDenies);
    }

    public function test_goals_execute_denies_unmapped_mutating_types_without_wp_writes(): void {
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $cases = [
            ['type' => 'set_theme_mod', 'params' => ['name' => 'custom_logo', 'value' => 'evil']],
            ['type' => 'add_menu_item', 'params' => ['menu_id' => 3, 'title' => 'Owned', 'url' => 'https://evil.example']],
            ['type' => 'apply_seo', 'params' => ['post_id' => 7, 'title' => 'Owned']],
            ['type' => 'not_a_real_action', 'params' => ['id' => 1]],
            ['type' => 'update_option', 'params' => ['option' => 'blogname', 'value' => 'Owned']],
        ];

        foreach ($cases as $action) {
            $GLOBALS['_wp_mutations'] = [];
            $request = new WP_REST_Request('POST', '/rjv-agi/v1/goals/execute', [
                'objective' => 'mutate via ' . $action['type'],
                'actions' => [$action],
            ]);
            $response = $plugin->api_execute_goal($request);
            $this->assertInstanceOf(WP_REST_Response::class, $response);
            $data = $response->get_data();
            $this->assertFalse($data['success'], $action['type']);
            $this->assertSame('Action not permitted', $data['data']['results'][0]['error'], $action['type']);
            $this->assertSame([], $GLOBALS['_wp_mutations'], $action['type']);
        }

        $this->assertArrayNotHasKey('blogname', $GLOBALS['_options']);
    }

    public function test_goals_execute_still_runs_plan_allowed_create_post_and_inert_wait(): void {
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $request = new WP_REST_Request('POST', '/rjv-agi/v1/goals/execute', [
            'objective' => 'draft a post',
            'actions' => [
                ['type' => 'wait', 'params' => ['seconds' => 0]],
                ['type' => 'create_post', 'params' => ['title' => 'Hello', 'content' => 'Body', 'status' => 'draft']],
            ],
        ]);

        $response = $plugin->api_execute_goal($request);
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame(42, $data['data']['results'][1]['post_id']);
        $this->assertSame('wp_insert_post', $GLOBALS['_wp_mutations'][0][0]);
    }

    public function test_conditional_cannot_smuggle_an_unmapped_mutation(): void {
        $result = GoalExecutor::instance()->execute([
            'objective' => 'smuggle a theme mod',
            'actions' => [[
                'type' => 'conditional',
                'params' => [
                    'condition' => ['type' => 'always'],
                    'then' => [
                        'type' => 'set_theme_mod',
                        'params' => ['name' => 'background_color', 'value' => '#000'],
                    ],
                ],
            ]],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Action not permitted', $result['results'][0]['error']);
        $this->assertSame([], $GLOBALS['_wp_mutations']);

        $GLOBALS['_wp_mutations'] = [];
        $allowed = GoalExecutor::instance()->execute([
            'objective' => 'draft inside a condition',
            'actions' => [[
                'type' => 'conditional',
                'params' => [
                    'condition' => ['type' => 'always'],
                    'then' => [
                        'type' => 'create_post',
                        'params' => ['title' => 'Nested', 'content' => 'Body', 'status' => 'draft'],
                    ],
                ],
            ]],
        ]);
        $this->assertTrue($allowed['success']);
        $this->assertSame(42, $allowed['results'][0]['post_id']);
        $this->assertSame('wp_insert_post', $GLOBALS['_wp_mutations'][0][0]);
    }

    public function test_goals_execute_route_requires_tier2(): void {
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Plugin::class, 'register_enterprise_routes');
        $method->invoke($plugin);

        $match = null;
        foreach ($GLOBALS['_registered_routes'] as $route) {
            if ($route['route'] === '/goals/execute') {
                $match = $route['args'];
            }
        }
        $this->assertIsArray($match);
        $this->assertSame('POST', $match['methods']);
        $this->assertSame([Auth::class, 'tier2'], $match['permission_callback']);
    }

    public function test_tier2_policy_put_cannot_disable_enforcement(): void {
        $controller = new EnterpriseControl();
        $controller->register_routes();

        $put = null;
        foreach ($GLOBALS['_registered_routes'] as $route) {
            if ($route['route'] !== '/governance/policies' || !isset($route['args'][0])) {
                continue;
            }
            foreach ($route['args'] as $endpoint) {
                if (($endpoint['methods'] ?? '') === 'PUT') {
                    $put = $endpoint;
                }
            }
        }
        $this->assertSame([Auth::class, 'tier2'], $put['permission_callback']);

        $request = new WP_REST_Request('PUT', '/rjv-agi/v1/governance/policies', [
            'enforcement_enabled' => false,
            'deny_routes' => ['/rjv-agi/v1/blocked'],
        ]);
        $response = $controller->update_policies($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $payload = $response->get_data()['data'];
        $this->assertTrue($payload['policies']['enforcement_enabled']);
        $this->assertTrue($payload['enforcement_disable_rejected']);
        $this->assertTrue(get_option('rjv_agi_policy_rules')['enforcement_enabled']);

        $decision = PolicyEngine::instance()->evaluate(
            new WP_REST_Request('POST', '/rjv-agi/v1/blocked')
        );
        $this->assertFalse($decision['allowed']);
        $this->assertNotSame('disabled', $decision['policy'] ?? '');

        $denies = array_values(array_filter(
            $this->wpdb->audit_entries(),
            static fn(array $entry): bool => $entry['action'] === 'policy_enforcement_disable_denied'
        ));
        $this->assertCount(1, $denies);
        $this->assertSame('error', $denies[0]['status']);
    }

    public function test_poisoned_enforcement_flag_does_not_allow_all(): void {
        update_option('rjv_agi_policy_rules', [
            'enforcement_enabled' => false,
            'deny_routes' => ['/rjv-agi/v1/blocked'],
            'approval_methods' => ['DELETE'],
            'approval_routes' => ['/rjv-agi/v1/plugins'],
            'bypass_routes' => ['/rjv-agi/v1/approvals', '/rjv-agi/v1/health'],
            'rules' => [],
        ]);

        $engine = PolicyEngine::instance();
        $denied = $engine->evaluate(new WP_REST_Request('POST', '/rjv-agi/v1/blocked'));
        $this->assertFalse($denied['allowed']);
        $this->assertSame('deny_routes', $denied['policy']);

        $delete = $engine->evaluate(new WP_REST_Request('DELETE', '/rjv-agi/v1/posts/9'));
        $this->assertTrue($delete['requires_approval']);
        $this->assertNotSame('disabled', $delete['policy'] ?? '');

        $health = $engine->evaluate(new WP_REST_Request('GET', '/rjv-agi/v1/health'));
        $this->assertTrue($health['allowed']);
        $this->assertSame('bypass', $health['policy']);
        $this->assertFalse($health['requires_approval']);
    }

    public function test_installer_default_policy_stays_enforced(): void {
        $method = new ReflectionMethod(Installer::class, 'set_defaults');
        $method->invoke(null);

        $stored = get_option('rjv_agi_policy_rules');
        $this->assertTrue($stored['enforcement_enabled']);
        $this->assertTrue(PolicyEngine::instance()->defaults()['enforcement_enabled']);
        $this->assertTrue(PolicyEngine::instance()->get_policies()['enforcement_enabled']);
    }

    public function test_enforced_policy_still_allows_unrestricted_reads(): void {
        $decision = PolicyEngine::instance()->evaluate(new WP_REST_Request('GET', '/rjv-agi/v1/posts'));
        $this->assertTrue($decision['allowed']);
        $this->assertFalse($decision['requires_approval']);
        $this->assertNotSame('disabled', $decision['policy'] ?? '');
    }

    public function test_approval_override_is_one_shot_and_bound_to_body_and_route(): void {
        update_option('rjv_agi_policy_rules', [
            'enforcement_enabled' => true,
            'approval_routes' => ['/rjv-agi/v1/menus'],
            'approval_methods' => ['DELETE'],
            'deny_routes' => ['/rjv-agi/v1/blocked'],
            'bypass_routes' => ['/rjv-agi/v1/approvals', '/rjv-agi/v1/health'],
            'rules' => [],
        ]);

        $plugin = $this->plugin();
        $body = ['title' => 'Menu', 'url' => 'https://example.com/a'];
        $request = new WP_REST_Request('POST', '/rjv-agi/v1/menus', $body);

        $pending = $plugin->pre_dispatch('PASS', null, $request);
        $this->assertInstanceOf(WP_REST_Response::class, $pending);
        $this->assertSame(202, $pending->get_status());
        $approvalId = (int) $pending->get_data()['data']['approval']['approval_id'];
        $this->assertGreaterThan(0, $approvalId);

        $item = ApprovalWorkflow::instance()->get_item($approvalId);
        $this->assertSame('pending', $item['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $item['action_data']['request_hash']);

        $approved = ApprovalWorkflow::instance()->approve($approvalId, 1, true);
        $this->assertTrue($approved['success']);
        $this->assertSame('approved', $approved['status']);
        $this->assertSame('approved', ApprovalWorkflow::instance()->get_item($approvalId)['status']);

        $replay = new WP_REST_Request('POST', '/rjv-agi/v1/menus', [
            'url' => 'https://example.com/a',
            'title' => 'Menu',
        ]);
        $replay->set_header('X-RJV-Approval-ID', (string) $approvalId);
        $first = $plugin->pre_dispatch('PASS', null, $replay);
        $this->assertSame('PASS', $first);
        $this->assertSame('executed', ApprovalWorkflow::instance()->get_item($approvalId)['status']);

        $second = $plugin->pre_dispatch('PASS', null, $replay);
        $this->assertInstanceOf(WP_REST_Response::class, $second);
        $this->assertSame(202, $second->get_status());
        $this->assertNotSame($approvalId, (int) $second->get_data()['data']['approval']['approval_id']);
        $this->assertTrue($this->audit_has_reason('approval_override_denied', 'status_not_approved'));
    }

    public function test_approval_override_rejects_altered_body_without_consuming(): void {
        $approvalId = $this->approved_menu_override(['title' => 'Menu', 'url' => 'https://example.com/a']);
        $plugin = $this->plugin();

        $altered = new WP_REST_Request('POST', '/rjv-agi/v1/menus', [
            'title' => 'Menu',
            'url' => 'https://example.com/b',
        ]);
        $altered->set_header('X-RJV-Approval-ID', (string) $approvalId);
        $result = $plugin->pre_dispatch('PASS', null, $altered);

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(202, $result->get_status());
        $this->assertSame('approved', ApprovalWorkflow::instance()->get_item($approvalId)['status']);
        $this->assertTrue($this->audit_has_reason('approval_override_denied', 'body_mismatch'));

        $original = new WP_REST_Request('POST', '/rjv-agi/v1/menus', [
            'title' => 'Menu',
            'url' => 'https://example.com/a',
        ]);
        $original->set_header('X-RJV-Approval-ID', (string) $approvalId);
        $this->assertSame('PASS', $plugin->pre_dispatch('PASS', null, $original));
    }

    public function test_approval_override_rejects_query_param_changes(): void {
        $approvalId = $this->approved_menu_override(['title' => 'Menu']);
        $plugin = $this->plugin();

        $changed = new WP_REST_Request('POST', '/rjv-agi/v1/menus', ['title' => 'Menu'], [], ['extra' => '1']);
        $changed->set_header('X-RJV-Approval-ID', (string) $approvalId);
        $result = $plugin->pre_dispatch('PASS', null, $changed);

        $this->assertNotSame('PASS', $result);
        $this->assertSame('approved', ApprovalWorkflow::instance()->get_item($approvalId)['status']);
        $this->assertTrue($this->audit_has_reason('approval_override_denied', 'body_mismatch'));
    }

    public function test_approval_override_rejects_unrelated_route_and_executed_status(): void {
        $approvalId = $this->approved_menu_override(['title' => 'Menu']);
        $plugin = $this->plugin();

        $other = new WP_REST_Request('POST', '/rjv-agi/v1/blocked', ['title' => 'Menu']);
        $other->set_header('X-RJV-Approval-ID', (string) $approvalId);
        $blocked = $plugin->pre_dispatch('PASS', null, $other);
        $this->assertInstanceOf(WP_Error::class, $blocked);
        $this->assertSame('policy_denied', $blocked->get_error_code());
        $this->assertSame('approved', ApprovalWorkflow::instance()->get_item($approvalId)['status']);
        $this->assertTrue($this->audit_has_reason('approval_override_denied', 'route_mismatch'));

        $GLOBALS['wpdb']->update(
            $GLOBALS['wpdb']->prefix . 'rjv_agi_approval_queue',
            ['status' => 'executed'],
            ['id' => $approvalId]
        );
        $replay = new WP_REST_Request('POST', '/rjv-agi/v1/menus', ['title' => 'Menu']);
        $replay->set_header('X-RJV-Approval-ID', (string) $approvalId);
        $again = $plugin->pre_dispatch('PASS', null, $replay);
        $this->assertNotSame('PASS', $again);
        $this->assertTrue($this->audit_has_reason('approval_override_denied', 'status_not_approved'));
    }

    /**
     * @param array<string, mixed> $json
     */
    private function approved_menu_override(array $json): int {
        update_option('rjv_agi_policy_rules', [
            'enforcement_enabled' => true,
            'approval_routes' => ['/rjv-agi/v1/menus'],
            'approval_methods' => ['DELETE'],
            'deny_routes' => ['/rjv-agi/v1/blocked'],
            'bypass_routes' => ['/rjv-agi/v1/approvals', '/rjv-agi/v1/health'],
            'rules' => [],
        ]);

        $pending = $this->plugin()->pre_dispatch(
            'PASS',
            null,
            new WP_REST_Request('POST', '/rjv-agi/v1/menus', $json)
        );
        $this->assertInstanceOf(WP_REST_Response::class, $pending);
        $approvalId = (int) $pending->get_data()['data']['approval']['approval_id'];
        $approved = ApprovalWorkflow::instance()->approve($approvalId, 1, true);
        $this->assertSame('approved', $approved['status']);
        return $approvalId;
    }

    private function plugin(): Plugin {
        return (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    }

    private function audit_has_reason(string $action, string $reason): bool {
        foreach ($this->wpdb->audit_entries() as $entry) {
            if ($entry['action'] === $action && ($entry['details']['reason'] ?? '') === $reason && $entry['status'] === 'error') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function mapped_actions(CapabilityGate $gate): array {
        $method = new ReflectionMethod(CapabilityGate::class, 'build_capability_map');
        $map = $method->invoke($gate);
        $actions = [];
        foreach ($map as $mapped) {
            foreach ($mapped as $action) {
                $actions[] = $action;
            }
        }
        return $actions;
    }

    private function reset_singleton(string $class): void {
        $property = (new ReflectionClass($class))->getProperty('instance');
        $property->setValue(null, null);
    }
}
