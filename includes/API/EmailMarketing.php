<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Email Marketing Integration
 *
 * Full administration surface for Mailchimp for WP (mc4wp), the Newsletter
 * plugin, and FluentCRM. Covers subscribers, lists/tags/segments, campaigns,
 * sequences/automations, and WooCommerce customer sync.
 * Auto-detects which plugin(s) are active so no manual configuration is needed.
 */
class EmailMarketing extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/email-marketing/status', [
            ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/lists', [
            ['methods' => 'GET',  'callback' => [$this, 'list_subscriber_lists'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_subscriber_list'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/subscribers', [
            ['methods' => 'GET',  'callback' => [$this, 'list_subscribers'],   'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['list_id' => ['default' => 0], 'per_page' => ['default' => 20], 'page' => ['default' => 1], 'plugin' => ['default' => ''], 'search' => ['default' => ''], 'status' => ['default' => '']]],
            ['methods' => 'POST', 'callback' => [$this, 'add_subscriber'],     'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/subscribers/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_subscriber'],    'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['plugin' => ['default' => '']]],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_subscriber'], 'permission_callback' => [Auth::class, 'tier2'],
             'args' => ['plugin' => ['default' => '']]],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_subscriber'], 'permission_callback' => [Auth::class, 'tier3'],
             'args' => ['plugin' => ['default' => '']]],
        ]);
        register_rest_route($this->namespace, '/email-marketing/subscribers/(?P<id>\d+)/tags', [
            ['methods' => 'POST',   'callback' => [$this, 'add_tags_to_subscriber'],    'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE', 'callback' => [$this, 'remove_tags_from_subscriber'],'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/sync-woo-customers', [
            ['methods' => 'POST', 'callback' => [$this, 'sync_woo_customers'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/unsubscribe', [
            ['methods' => 'POST', 'callback' => [$this, 'unsubscribe'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // ── FluentCRM ────────────────────────────────────────────────────────
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/contacts', [
            ['methods' => 'GET',  'callback' => [$this, 'fcrm_list_contacts'],  'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1], 'search' => ['default' => ''], 'status' => ['default' => '']]],
            ['methods' => 'POST', 'callback' => [$this, 'fcrm_create_contact'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/contacts/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'fcrm_get_contact'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'fcrm_update_contact'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'fcrm_delete_contact'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/tags', [
            ['methods' => 'GET',  'callback' => [$this, 'fcrm_list_tags'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'fcrm_create_tag'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/lists', [
            ['methods' => 'GET',  'callback' => [$this, 'fcrm_list_lists'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'fcrm_create_list'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/campaigns', [
            ['methods' => 'GET',  'callback' => [$this, 'fcrm_list_campaigns'],  'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1], 'status' => ['default' => '']]],
            ['methods' => 'POST', 'callback' => [$this, 'fcrm_create_campaign'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/campaigns/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'fcrm_get_campaign'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'fcrm_update_campaign'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'fcrm_delete_campaign'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/campaigns/(?P<id>\d+)/send', [
            ['methods' => 'POST', 'callback' => [$this, 'fcrm_schedule_campaign'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/sequences', [
            ['methods' => 'GET', 'callback' => [$this, 'fcrm_list_sequences'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/automations', [
            ['methods' => 'GET', 'callback' => [$this, 'fcrm_list_automations'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/fluentcrm/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'fcrm_stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);

        // ── Newsletter plugin campaigns ───────────────────────────────────────
        register_rest_route($this->namespace, '/email-marketing/newsletter/campaigns', [
            ['methods' => 'GET',  'callback' => [$this, 'nl_list_campaigns'],  'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 20], 'page' => ['default' => 1], 'status' => ['default' => '']]],
            ['methods' => 'POST', 'callback' => [$this, 'nl_create_campaign'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/newsletter/campaigns/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'nl_get_campaign'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'nl_update_campaign'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/newsletter/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'nl_stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    public function status(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success([
            'mailchimp_for_wp' => $this->is_mc4wp_active(),
            'newsletter'       => $this->is_newsletter_active(),
            'fluentcrm'        => $this->is_fcrm_active(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    public function list_subscriber_lists(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $lists = [];

        if ($this->is_newsletter_active()) {
            $nl_lists = $this->newsletter_get_lists();
            foreach ($nl_lists as $list) {
                $lists[] = [
                    'id'     => $list['id'],
                    'name'   => $list['name'],
                    'plugin' => 'newsletter',
                ];
            }
        }

        if ($this->is_fcrm_active()) {
            $fcrm_lists = $this->fcrm_get_lists_raw();
            foreach ($fcrm_lists as $list) {
                $lists[] = [
                    'id'     => $list->id,
                    'name'   => $list->title,
                    'plugin' => 'fluentcrm',
                ];
            }
        }

        if ($this->is_mc4wp_active()) {
            // mc4wp does not manage lists internally; it syncs to external services
            $lists[] = [
                'id'     => 0,
                'name'   => 'Mailchimp for WP (managed externally)',
                'plugin' => 'mailchimp_for_wp',
            ];
        }

        if (empty($lists)) {
            return $this->error('No supported email marketing plugin is active', 503);
        }

        return $this->success(['lists' => $lists]);
    }

    public function create_subscriber_list(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));
        $name   = sanitize_text_field((string) ($d['name'] ?? ''));

        if (empty($name)) return $this->error('name is required');

        if (($plugin === '' && $this->is_fcrm_active()) || $plugin === 'fluentcrm') {
            if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);

            $list = FluentCrm('db')->table('fc_contact_groups')->insert([
                'title'       => $name,
                'slug'        => sanitize_title($name),
                'description' => sanitize_textarea_field((string) ($d['description'] ?? '')),
                'type'        => 'lists',
                'created_at'  => current_time('mysql', true),
                'updated_at'  => current_time('mysql', true),
            ]);

            $this->log('email_marketing_create_list', 'list', 0, ['name' => $name, 'plugin' => 'fluentcrm'], 2);
            return $this->success(['name' => $name, 'plugin' => 'fluentcrm'], 201);
        }

        return $this->error('List creation is supported for FluentCRM only', 503);
    }

    public function list_subscribers(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $plugin  = sanitize_key((string) $r['plugin']);
        $list_id = (int) $r['list_id'];
        $pp      = min((int) $r['per_page'], 100);
        $page    = max(1, (int) $r['page']);

        if ($plugin === 'newsletter' || ($plugin === '' && $this->is_newsletter_active())) {
            if (!$this->is_newsletter_active()) {
                return $this->error('Newsletter plugin is not active', 503);
            }

            $subscribers = $this->newsletter_get_subscribers($list_id, $pp, $page);

            return $this->success(array_merge($subscribers, ['plugin' => 'newsletter']));
        }

        return $this->error('No supported email marketing plugin is active or plugin parameter is invalid', 503);
    }

    public function get_subscriber(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $plugin = sanitize_key((string) $r['plugin']);

        if ($plugin === 'newsletter' || ($plugin === '' && $this->is_newsletter_active())) {
            if (!$this->is_newsletter_active()) {
                return $this->error('Newsletter plugin is not active', 503);
            }

            $sub = $this->newsletter_get_subscriber($id);
            if (!$sub) {
                return $this->error('Subscriber not found', 404);
            }

            return $this->success($sub);
        }

        return $this->error('No supported email marketing plugin is active', 503);
    }

    public function add_subscriber(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));
        $email  = sanitize_email((string) ($d['email'] ?? ''));

        if (empty($email)) {
            return $this->error('email is required');
        }

        if ($plugin === 'newsletter' || ($plugin === '' && $this->is_newsletter_active())) {
            if (!$this->is_newsletter_active()) {
                return $this->error('Newsletter plugin is not active', 503);
            }

            $result = $this->newsletter_add_subscriber($email, $d);

            $this->log('email_marketing_add', 'subscriber', 0, ['email' => $email, 'plugin' => 'newsletter'], 2);

            return $this->success($result, 201);
        }

        if ($plugin === 'mailchimp_for_wp' || ($plugin === '' && $this->is_mc4wp_active())) {
            if (!$this->is_mc4wp_active()) {
                return $this->error('Mailchimp for WP is not active', 503);
            }

            $result = $this->mc4wp_add_subscriber($email, $d);

            $this->log('email_marketing_add', 'subscriber', 0, ['email' => $email, 'plugin' => 'mailchimp_for_wp'], 2);

            return $this->success($result, 201);
        }

        return $this->error('No supported email marketing plugin is active', 503);
    }

    public function update_subscriber(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($r['plugin'] ?: ($d['plugin'] ?? '')));

        if ($plugin === 'newsletter' || ($plugin === '' && $this->is_newsletter_active())) {
            if (!$this->is_newsletter_active()) {
                return $this->error('Newsletter plugin is not active', 503);
            }

            $result = $this->newsletter_update_subscriber($id, $d);

            if (!$result) {
                return $this->error('Subscriber not found or update failed', 404);
            }

            $this->log('email_marketing_update', 'subscriber', $id, ['plugin' => 'newsletter'], 2);

            return $this->success($result);
        }

        return $this->error('No supported email marketing plugin is active', 503);
    }

    public function delete_subscriber(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $plugin = sanitize_key((string) $r['plugin']);

        if ($plugin === 'newsletter' || ($plugin === '' && $this->is_newsletter_active())) {
            if (!$this->is_newsletter_active()) {
                return $this->error('Newsletter plugin is not active', 503);
            }

            $result = $this->newsletter_delete_subscriber($id);

            if (!$result) {
                return $this->error('Subscriber not found', 404);
            }

            $this->log('email_marketing_delete', 'subscriber', $id, ['plugin' => 'newsletter'], 3);

            return $this->success(['deleted' => true, 'id' => $id]);
        }

        return $this->error('No supported email marketing plugin is active', 503);
    }

    // -------------------------------------------------------------------------
    // WooCommerce customer sync
    // -------------------------------------------------------------------------

    public function sync_woo_customers(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!class_exists('WooCommerce') && !function_exists('WC')) {
            return $this->error('WooCommerce is not active', 503);
        }

        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));
        $list_id= (int) ($d['list_id'] ?? 0);

        if (!$this->is_newsletter_active() && !$this->is_mc4wp_active()) {
            return $this->error('No supported email marketing plugin is active', 503);
        }

        $users = get_users(['role' => 'customer', 'fields' => ['ID', 'user_email', 'display_name'], 'number' => 2000]);

        $synced  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($users as $user) {
            $email = $user->user_email;
            if (empty($email)) {
                $skipped++;
                continue;
            }

            try {
                if ($plugin === 'newsletter' || ($plugin === '' && $this->is_newsletter_active())) {
                    $this->newsletter_add_subscriber($email, [
                        'name'    => $user->display_name,
                        'list_id' => $list_id,
                        'status'  => 'C', // confirmed
                    ]);
                } elseif ($this->is_mc4wp_active()) {
                    $this->mc4wp_add_subscriber($email, ['name' => $user->display_name]);
                }
                $synced++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        $this->log('email_marketing_sync_woo', 'subscriber', 0, ['synced' => $synced, 'skipped' => $skipped, 'errors' => $errors], 2);

        return $this->success([
            'synced'  => $synced,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    // -------------------------------------------------------------------------
    // Newsletter plugin helpers
    // -------------------------------------------------------------------------

    private function newsletter_get_lists(): array {
        if (!class_exists('Newsletter')) {
            return [];
        }

        $nl = \Newsletter::instance();
        if (!method_exists($nl, 'get_lists')) {
            return [['id' => 0, 'name' => 'Default List']];
        }

        return array_map(function (object $list): array {
            return ['id' => (int) $list->id, 'name' => $list->name ?? "List {$list->id}"];
        }, $nl->get_lists());
    }

    private function newsletter_get_subscribers(int $list_id, int $pp, int $page): array {
        if (!class_exists('Newsletter')) {
            return ['subscribers' => [], 'total' => 0, 'page' => $page, 'per_page' => $pp];
        }

        global $wpdb;

        $table  = $wpdb->prefix . 'newsletter';
        $offset = ($page - 1) * $pp;

        $where = "status IN ('C','P')";
        if ($list_id > 0) {
            $col = $this->newsletter_list_column($list_id);
            if ($col === null) {
                return ['subscribers' => [], 'total' => 0, 'page' => $page, 'per_page' => $pp];
            }
            $where .= $wpdb->prepare(" AND {$col} = %d", 1);
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, name, status, created FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            $pp,
            $offset
        ), ARRAY_A) ?: [];

        return [
            'subscribers' => array_map(fn(array $row): array => [
                'id'      => (int) $row['id'],
                'email'   => $row['email'],
                'name'    => $row['name'],
                'status'  => $row['status'],
                'created' => $row['created'],
                'plugin'  => 'newsletter',
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $pp,
        ];
    }

    private function newsletter_get_subscriber(int $id): ?array {
        if (!class_exists('Newsletter')) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'newsletter';
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        if (!$row) {
            return null;
        }

        return [
            'id'      => (int) $row['id'],
            'email'   => $row['email'],
            'name'    => $row['name'],
            'status'  => $row['status'],
            'created' => $row['created'],
            'plugin'  => 'newsletter',
        ];
    }

    private function newsletter_add_subscriber(string $email, array $data): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'newsletter';
        $status = sanitize_key((string) ($data['status'] ?? 'C'));
        $name   = sanitize_text_field((string) ($data['name'] ?? ''));

        // Upsert: check if already exists
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s", $email));

        $row_data = [
            'email'   => $email,
            'name'    => $name,
            'status'  => $status,
            'created' => current_time('mysql', true),
        ];

        if (!empty($data['list_id'])) {
            $col = $this->newsletter_list_column((int) $data['list_id']);
            if ($col !== null) {
                $row_data[$col] = 1;
            }
        }

        if ($existing) {
            unset($row_data['created']);
            $wpdb->update($table, $row_data, ['id' => (int) $existing]);
            return ['id' => (int) $existing, 'email' => $email, 'upserted' => true];
        }

        $wpdb->insert($table, $row_data);

        return ['id' => $wpdb->insert_id, 'email' => $email, 'upserted' => false];
    }

    private function newsletter_update_subscriber(int $id, array $data): ?array {
        global $wpdb;

        $table    = $wpdb->prefix . 'newsletter';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        if (!$existing) {
            return null;
        }

        $update = [];
        if (isset($data['name']))   $update['name']   = sanitize_text_field((string) $data['name']);
        if (isset($data['status'])) $update['status'] = sanitize_key((string) $data['status']);

        if (!empty($update)) {
            $wpdb->update($table, $update, ['id' => $id]);
        }

        return $this->newsletter_get_subscriber($id);
    }

    private function newsletter_delete_subscriber(int $id): bool {
        global $wpdb;

        $table   = $wpdb->prefix . 'newsletter';
        $deleted = $wpdb->delete($table, ['id' => $id]);

        return $deleted !== false && $deleted > 0;
    }

    // -------------------------------------------------------------------------
    // Mailchimp for WP helpers
    // -------------------------------------------------------------------------

    /**
     * Return a validated Newsletter list column name (e.g. "list1", "list2").
     * Returns null when the supplied ID is out of the Newsletter plugin's
     * supported range (1-10), preventing arbitrary column injection.
     */
    private function newsletter_list_column(int $list_id): ?string {
        if ($list_id < 1 || $list_id > 10) {
            return null;
        }

        return "list{$list_id}";
    }

    private function mc4wp_add_subscriber(string $email, array $data): array {
        if (!function_exists('mc4wp_get_api_v3') && !class_exists('MC4WP_API_v3')) {
            // mc4wp manages list subscriptions via its own form submissions;
            // log the intent and return a notice.
            return [
                'email'  => $email,
                'notice' => 'Subscription queued – Mailchimp for WP syncs via its configured list settings',
            ];
        }

        try {
            $api     = mc4wp_get_api_v3();
            $options = mc4wp_get_options();
            $list_id = $options['default_list_id'] ?? '';

            if (empty($list_id)) {
                return ['email' => $email, 'notice' => 'No default Mailchimp list configured in mc4wp settings'];
            }

            $api->add_list_member($list_id, [
                'email_address' => $email,
                'status'        => 'subscribed',
                'merge_fields'  => ['FNAME' => sanitize_text_field((string) ($data['name'] ?? ''))],
            ]);

            return ['email' => $email, 'subscribed' => true, 'list_id' => $list_id];
        } catch (\Throwable $e) {
            return ['email' => $email, 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    private function is_mc4wp_active(): bool {
        return class_exists('MC4WP_MailChimp') || function_exists('mc4wp_get_api_v3') || defined('MC4WP_VERSION');
    }

    private function is_newsletter_active(): bool {
        return class_exists('Newsletter') || defined('NEWSLETTER_VERSION');
    }

    private function is_fcrm_active(): bool {
        return defined('FLUENTCRM') || function_exists('FluentCrm') || class_exists('\FluentCrm\App\Models\Subscriber');
    }

    // =========================================================================
    // Unsubscribe (unified)
    // =========================================================================

    public function unsubscribe(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $email  = sanitize_email((string) ($d['email'] ?? ''));
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));

        if (empty($email)) return $this->error('email is required');

        $results = [];

        if (($plugin === '' || $plugin === 'newsletter') && $this->is_newsletter_active()) {
            global $wpdb;
            $table = $wpdb->prefix . 'newsletter';
            $wpdb->update($table, ['status' => 'U'], ['email' => $email]);
            $results['newsletter'] = 'unsubscribed';
        }

        if (($plugin === '' || $plugin === 'fluentcrm') && $this->is_fcrm_active()) {
            try {
                $contact = \FluentCrm\App\Models\Subscriber::where('email', $email)->first();
                if ($contact) {
                    $contact->status = 'unsubscribed';
                    $contact->save();
                    $results['fluentcrm'] = 'unsubscribed';
                }
            } catch (\Throwable $e) {
                $results['fluentcrm'] = 'error: ' . $e->getMessage();
            }
        }

        if (empty($results)) return $this->error('No supported plugin handled the unsubscribe', 503);

        $this->log('email_marketing_unsubscribe', 'subscriber', 0, ['email' => $email, 'results' => $results], 2);
        return $this->success(['email' => $email, 'results' => $results]);
    }

    // =========================================================================
    // Tag management (subscribers)
    // =========================================================================

    public function add_tags_to_subscriber(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $d      = (array) $r->get_json_params();
        $tags   = array_map('sanitize_text_field', (array) ($d['tags'] ?? []));
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));

        if (empty($tags)) return $this->error('tags array is required');

        if (($plugin === '' && $this->is_fcrm_active()) || $plugin === 'fluentcrm') {
            if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
            try {
                $contact = \FluentCrm\App\Models\Subscriber::find($id);
                if (!$contact) return $this->error('Contact not found', 404);
                $contact->attachTags($tags);
                $this->log('email_marketing_add_tags', 'subscriber', $id, ['tags' => $tags, 'plugin' => 'fluentcrm'], 2);
                return $this->success(['added' => true, 'tags' => $tags]);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage(), 500);
            }
        }

        return $this->error('Tag management is only supported for FluentCRM', 503);
    }

    public function remove_tags_from_subscriber(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $d      = (array) $r->get_json_params();
        $tags   = array_map('sanitize_text_field', (array) ($d['tags'] ?? []));

        if (empty($tags)) return $this->error('tags array is required');

        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);

        try {
            $contact = \FluentCrm\App\Models\Subscriber::find($id);
            if (!$contact) return $this->error('Contact not found', 404);
            $contact->detachTags($tags);
            $this->log('email_marketing_remove_tags', 'subscriber', $id, ['tags' => $tags], 2);
            return $this->success(['removed' => true, 'tags' => $tags]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // FluentCRM – Contacts
    // =========================================================================

    public function fcrm_list_contacts(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);

        $pp     = min((int) ($r['per_page'] ?? 20), 100);
        $page   = max(1, (int) ($r['page'] ?? 1));
        $search = sanitize_text_field((string) ($r['search'] ?? ''));
        $status = sanitize_key((string) ($r['status'] ?? ''));

        try {
            $query = \FluentCrm\App\Models\Subscriber::query();
            if ($search) {
                $query->where('email', 'LIKE', '%' . $search . '%')
                      ->orWhere('first_name', 'LIKE', '%' . $search . '%')
                      ->orWhere('last_name', 'LIKE', '%' . $search . '%');
            }
            if ($status) $query->where('status', $status);

            $total    = $query->count();
            $contacts = $query->orderBy('id', 'DESC')->paginate($pp, ['*'], 'page', $page);

            return $this->success([
                'contacts' => array_map([$this, 'fmt_fcrm_contact'], $contacts->items()),
                'total'    => $total,
                'page'     => $page,
                'per_page' => $pp,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_get_contact(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $contact = \FluentCrm\App\Models\Subscriber::find((int) $r['id']);
            if (!$contact) return $this->error('Contact not found', 404);
            $data              = $this->fmt_fcrm_contact($contact);
            $data['tags']      = $contact->tags()->pluck('title')->toArray();
            $data['lists']     = $contact->lists()->pluck('title')->toArray();
            $data['custom']    = $contact->custom_values ?? [];
            return $this->success($data);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_create_contact(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);

        $d     = (array) $r->get_json_params();
        $email = sanitize_email((string) ($d['email'] ?? ''));
        if (empty($email)) return $this->error('email is required');

        try {
            $payload = [
                'email'      => $email,
                'first_name' => sanitize_text_field((string) ($d['first_name'] ?? '')),
                'last_name'  => sanitize_text_field((string) ($d['last_name'] ?? '')),
                'phone'      => sanitize_text_field((string) ($d['phone'] ?? '')),
                'address_line_1' => sanitize_text_field((string) ($d['address'] ?? '')),
                'city'       => sanitize_text_field((string) ($d['city'] ?? '')),
                'state'      => sanitize_text_field((string) ($d['state'] ?? '')),
                'country'    => sanitize_text_field((string) ($d['country'] ?? '')),
                'status'     => sanitize_key((string) ($d['status'] ?? 'subscribed')),
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ];

            $result = FluentCrmApi('contacts')->createOrUpdate($payload);
            if (!empty($d['tags']))  $result->contact->attachTags((array) $d['tags']);
            if (!empty($d['lists'])) $result->contact->attachLists((array) $d['lists']);

            $this->log('email_marketing_create_contact', 'subscriber', $result->contact->id, ['email' => $email, 'plugin' => 'fluentcrm'], 2);
            return $this->success($this->fmt_fcrm_contact($result->contact), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_update_contact(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $contact = \FluentCrm\App\Models\Subscriber::find((int) $r['id']);
            if (!$contact) return $this->error('Contact not found', 404);

            $d = (array) $r->get_json_params();
            $allowed = ['first_name', 'last_name', 'phone', 'status', 'city', 'state', 'country', 'address_line_1'];
            foreach ($allowed as $key) {
                if (isset($d[$key])) $contact->$key = sanitize_text_field((string) $d[$key]);
            }
            $contact->updated_at = current_time('mysql', true);
            $contact->save();

            if (!empty($d['tags']))  $contact->syncTags((array) $d['tags']);
            if (!empty($d['lists'])) $contact->syncLists((array) $d['lists']);

            $this->log('email_marketing_update_contact', 'subscriber', $contact->id, ['plugin' => 'fluentcrm'], 2);
            return $this->success($this->fmt_fcrm_contact($contact));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_delete_contact(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $contact = \FluentCrm\App\Models\Subscriber::find((int) $r['id']);
            if (!$contact) return $this->error('Contact not found', 404);
            $contact->delete();
            $this->log('email_marketing_delete_contact', 'subscriber', (int) $r['id'], ['plugin' => 'fluentcrm'], 3);
            return $this->success(['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function fmt_fcrm_contact(object $contact): array {
        return [
            'id'         => $contact->id,
            'email'      => $contact->email,
            'first_name' => $contact->first_name ?? '',
            'last_name'  => $contact->last_name  ?? '',
            'phone'      => $contact->phone       ?? '',
            'status'     => $contact->status,
            'city'       => $contact->city        ?? '',
            'country'    => $contact->country     ?? '',
            'created_at' => $contact->created_at,
            'plugin'     => 'fluentcrm',
        ];
    }

    // =========================================================================
    // FluentCRM – Tags & Lists
    // =========================================================================

    public function fcrm_list_tags(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $tags = \FluentCrm\App\Models\Tag::orderBy('title')->get();
            return $this->success([
                'tags' => $tags->map(fn($t) => ['id' => $t->id, 'title' => $t->title, 'slug' => $t->slug])->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_create_tag(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        $d     = (array) $r->get_json_params();
        $title = sanitize_text_field((string) ($d['title'] ?? ''));
        if (empty($title)) return $this->error('title is required');
        try {
            $tag = \FluentCrm\App\Models\Tag::create(['title' => $title, 'slug' => sanitize_title($title)]);
            $this->log('email_marketing_create_tag', 'tag', $tag->id, ['title' => $title, 'plugin' => 'fluentcrm'], 2);
            return $this->success(['id' => $tag->id, 'title' => $title], 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_list_lists(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            return $this->success(['lists' => array_map(fn($l) => ['id' => $l->id, 'title' => $l->title], $this->fcrm_get_lists_raw())]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_create_list(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        $d     = (array) $r->get_json_params();
        $title = sanitize_text_field((string) ($d['title'] ?? ''));
        if (empty($title)) return $this->error('title is required');
        try {
            $list = \FluentCrm\App\Models\Lists::create(['title' => $title, 'slug' => sanitize_title($title)]);
            $this->log('email_marketing_create_list_fcrm', 'list', $list->id, ['title' => $title, 'plugin' => 'fluentcrm'], 2);
            return $this->success(['id' => $list->id, 'title' => $title], 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function fcrm_get_lists_raw(): array {
        if (!$this->is_fcrm_active()) return [];
        try {
            return \FluentCrm\App\Models\Lists::orderBy('title')->get()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // FluentCRM – Campaigns
    // =========================================================================

    public function fcrm_list_campaigns(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);

        $pp     = min((int) ($r['per_page'] ?? 20), 100);
        $page   = max(1, (int) ($r['page'] ?? 1));
        $status = sanitize_key((string) ($r['status'] ?? ''));

        try {
            $query = \FluentCrm\App\Models\Campaign::query();
            if ($status) $query->where('status', $status);

            $total     = $query->count();
            $campaigns = $query->orderBy('id', 'DESC')->paginate($pp, ['*'], 'page', $page);

            return $this->success([
                'campaigns' => array_map([$this, 'fmt_fcrm_campaign'], $campaigns->items()),
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $pp,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_get_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $campaign = \FluentCrm\App\Models\Campaign::with(['email', 'lists', 'tags'])->find((int) $r['id']);
            if (!$campaign) return $this->error('Campaign not found', 404);
            $data             = $this->fmt_fcrm_campaign($campaign);
            $data['email']    = $campaign->email ? $this->fmt_fcrm_email($campaign->email) : null;
            $data['lists']    = $campaign->lists->pluck('title')->toArray();
            $data['tags']     = $campaign->tags->pluck('title')->toArray();
            return $this->success($data);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_create_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        $d = (array) $r->get_json_params();

        $title = sanitize_text_field((string) ($d['title'] ?? ''));
        if (empty($title)) return $this->error('title is required');

        try {
            $campaign = \FluentCrm\App\Models\Campaign::create([
                'title'      => $title,
                'status'     => sanitize_key((string) ($d['status'] ?? 'draft')),
                'subject'    => sanitize_text_field((string) ($d['subject'] ?? '')),
                'from_name'  => sanitize_text_field((string) ($d['from_name'] ?? get_bloginfo('name'))),
                'from_email' => sanitize_email((string) ($d['from_email'] ?? get_option('admin_email', ''))),
                'reply_to'   => sanitize_email((string) ($d['reply_to'] ?? '')),
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]);

            $this->log('email_marketing_create_campaign', 'campaign', $campaign->id, ['title' => $title, 'plugin' => 'fluentcrm'], 2);
            return $this->success($this->fmt_fcrm_campaign($campaign), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_update_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $campaign = \FluentCrm\App\Models\Campaign::find((int) $r['id']);
            if (!$campaign) return $this->error('Campaign not found', 404);

            $d = (array) $r->get_json_params();
            $allowed = ['title', 'status', 'subject', 'from_name', 'from_email', 'reply_to'];
            foreach ($allowed as $k) {
                if (isset($d[$k])) $campaign->$k = sanitize_text_field((string) $d[$k]);
            }
            $campaign->updated_at = current_time('mysql', true);
            $campaign->save();

            $this->log('email_marketing_update_campaign', 'campaign', $campaign->id, ['plugin' => 'fluentcrm'], 2);
            return $this->success($this->fmt_fcrm_campaign($campaign));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_delete_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $campaign = \FluentCrm\App\Models\Campaign::find((int) $r['id']);
            if (!$campaign) return $this->error('Campaign not found', 404);
            $campaign->delete();
            $this->log('email_marketing_delete_campaign', 'campaign', (int) $r['id'], ['plugin' => 'fluentcrm'], 3);
            return $this->success(['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_schedule_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $campaign = \FluentCrm\App\Models\Campaign::find((int) $r['id']);
            if (!$campaign) return $this->error('Campaign not found', 404);

            $d     = (array) $r->get_json_params();
            $when  = sanitize_text_field((string) ($d['scheduled_at'] ?? ''));

            $campaign->status = $when ? 'scheduled' : 'processing';
            if ($when) $campaign->scheduled_at = $when;
            $campaign->updated_at = current_time('mysql', true);
            $campaign->save();

            do_action('fluentcrm_process_scheduled_tasks');

            $this->log('email_marketing_schedule_campaign', 'campaign', $campaign->id, ['scheduled_at' => $when, 'plugin' => 'fluentcrm'], 2);
            return $this->success(['scheduled' => true, 'status' => $campaign->status]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function fmt_fcrm_campaign(object $campaign): array {
        return [
            'id'         => $campaign->id,
            'title'      => $campaign->title,
            'status'     => $campaign->status,
            'subject'    => $campaign->subject ?? '',
            'from_name'  => $campaign->from_name ?? '',
            'from_email' => $campaign->from_email ?? '',
            'scheduled_at'=> $campaign->scheduled_at ?? null,
            'created_at' => $campaign->created_at,
            'plugin'     => 'fluentcrm',
        ];
    }

    private function fmt_fcrm_email(object $email): array {
        return [
            'id'        => $email->id,
            'subject'   => $email->subject ?? '',
            'body'      => $email->body ?? '',
        ];
    }

    // =========================================================================
    // FluentCRM – Sequences & Automations
    // =========================================================================

    public function fcrm_list_sequences(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $sequences = \FluentCrm\App\Models\Sequence::orderBy('id', 'DESC')->get();
            return $this->success([
                'sequences' => $sequences->map(fn($s) => [
                    'id'          => $s->id,
                    'title'       => $s->title,
                    'status'      => $s->status,
                    'created_at'  => $s->created_at,
                ])->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function fcrm_list_automations(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $funnels = \FluentCrm\App\Models\Funnel::orderBy('id', 'DESC')->get();
            return $this->success([
                'automations' => $funnels->map(fn($f) => [
                    'id'         => $f->id,
                    'title'      => $f->title,
                    'status'     => $f->status,
                    'trigger'    => $f->trigger_name ?? '',
                    'created_at' => $f->created_at,
                ])->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // FluentCRM – Stats
    // =========================================================================

    public function fcrm_stats(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_fcrm_active()) return $this->error('FluentCRM is not active', 503);
        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::class;
            return $this->success([
                'total'        => $subscriber::count(),
                'subscribed'   => $subscriber::where('status', 'subscribed')->count(),
                'unsubscribed' => $subscriber::where('status', 'unsubscribed')->count(),
                'pending'      => $subscriber::where('status', 'pending')->count(),
                'bounced'      => $subscriber::where('status', 'bounced')->count(),
                'campaigns'    => \FluentCrm\App\Models\Campaign::count(),
                'sequences'    => class_exists('\FluentCrm\App\Models\Sequence') ? \FluentCrm\App\Models\Sequence::count() : 0,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Newsletter plugin – Campaigns
    // =========================================================================

    public function nl_list_campaigns(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_newsletter_active()) return $this->error('Newsletter plugin is not active', 503);

        global $wpdb;
        $pp     = min((int) ($r['per_page'] ?? 20), 100);
        $page   = max(1, (int) ($r['page'] ?? 1));
        $status = sanitize_key((string) ($r['status'] ?? ''));
        $offset = ($page - 1) * $pp;

        $table = $wpdb->prefix . 'newsletter_emails';
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return $this->error('Newsletter emails table not found', 503);
        }

        $where = "type = 'message'";
        if ($status) $where .= $wpdb->prepare(' AND status = %s', $status);

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT id, subject, status, created, sent, total FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $pp, $offset),
            ARRAY_A
        ) ?: [];

        return $this->success([
            'campaigns' => array_map(fn($row) => [
                'id'      => (int) $row['id'],
                'subject' => $row['subject'],
                'status'  => $row['status'],
                'created' => $row['created'],
                'sent'    => $row['sent'],
                'total'   => (int) $row['total'],
                'plugin'  => 'newsletter',
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $pp,
        ]);
    }

    public function nl_get_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_newsletter_active()) return $this->error('Newsletter plugin is not active', 503);

        global $wpdb;
        $table = $wpdb->prefix . 'newsletter_emails';
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $r['id']), ARRAY_A);

        if (!$row) return $this->error('Campaign not found', 404);

        return $this->success(array_merge($row, ['plugin' => 'newsletter']));
    }

    public function nl_create_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_newsletter_active()) return $this->error('Newsletter plugin is not active', 503);

        global $wpdb;
        $d       = (array) $r->get_json_params();
        $subject = sanitize_text_field((string) ($d['subject'] ?? ''));
        if (empty($subject)) return $this->error('subject is required');

        $table = $wpdb->prefix . 'newsletter_emails';
        $wpdb->insert($table, [
            'subject'  => $subject,
            'message'  => wp_kses_post((string) ($d['message'] ?? '')),
            'status'   => sanitize_key((string) ($d['status'] ?? 'new')),
            'type'     => 'message',
            'created'  => current_time('mysql', true),
        ]);

        $this->log('email_marketing_nl_create_campaign', 'campaign', $wpdb->insert_id, ['subject' => $subject, 'plugin' => 'newsletter'], 2);
        return $this->success(['id' => $wpdb->insert_id, 'subject' => $subject, 'plugin' => 'newsletter'], 201);
    }

    public function nl_update_campaign(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_newsletter_active()) return $this->error('Newsletter plugin is not active', 503);

        global $wpdb;
        $id    = (int) $r['id'];
        $d     = (array) $r->get_json_params();
        $table = $wpdb->prefix . 'newsletter_emails';

        $update = [];
        if (isset($d['subject'])) $update['subject'] = sanitize_text_field($d['subject']);
        if (isset($d['message'])) $update['message'] = wp_kses_post($d['message']);
        if (isset($d['status']))  $update['status']  = sanitize_key($d['status']);

        if (!empty($update)) {
            $wpdb->update($table, $update, ['id' => $id]);
        }

        $this->log('email_marketing_nl_update_campaign', 'campaign', $id, ['plugin' => 'newsletter'], 2);
        return $this->success(['updated' => true, 'id' => $id]);
    }

    public function nl_stats(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_newsletter_active()) return $this->error('Newsletter plugin is not active', 503);

        global $wpdb;
        $sub_table  = $wpdb->prefix . 'newsletter';
        $email_table= $wpdb->prefix . 'newsletter_emails';

        $stats = [
            'total_subscribers'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sub_table}"),
            'confirmed_subscribers'=> (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sub_table} WHERE status = 'C'"),
            'unsubscribed'         => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sub_table} WHERE status = 'U'"),
            'total_campaigns'      => 0,
            'sent_campaigns'       => 0,
        ];

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $email_table))) {
            $stats['total_campaigns'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$email_table} WHERE type = 'message'");
            $stats['sent_campaigns']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$email_table} WHERE type = 'message' AND status = 'sent'");
        }

        return $this->success(array_merge($stats, ['plugin' => 'newsletter']));
    }
}
