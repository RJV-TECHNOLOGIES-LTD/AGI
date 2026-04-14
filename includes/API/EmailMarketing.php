<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Email Marketing Integration
 *
 * Provides a unified subscriber management surface for the two most popular
 * email-marketing plugins: Mailchimp for WP (mc4wp) and the Newsletter plugin.
 * Auto-detects which plugin(s) are active so no manual configuration is needed.
 */
class EmailMarketing extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/email-marketing/status', [
            ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/lists', [
            ['methods' => 'GET', 'callback' => [$this, 'list_subscriber_lists'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/email-marketing/subscribers', [
            ['methods' => 'GET',  'callback' => [$this, 'list_subscribers'],   'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['list_id' => ['default' => 0], 'per_page' => ['default' => 20], 'page' => ['default' => 1], 'plugin' => ['default' => '']]],
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
        register_rest_route($this->namespace, '/email-marketing/sync-woo-customers', [
            ['methods' => 'POST', 'callback' => [$this, 'sync_woo_customers'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    public function status(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success([
            'mailchimp_for_wp' => $this->is_mc4wp_active(),
            'newsletter'       => $this->is_newsletter_active(),
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

    // -------------------------------------------------------------------------
    // Subscribers
    // -------------------------------------------------------------------------

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
}
