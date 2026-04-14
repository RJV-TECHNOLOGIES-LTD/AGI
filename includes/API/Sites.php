<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Multisite / Network Management API
 *
 * Provides both single-site context info and, on WordPress Multisite
 * networks, full site management (list, create, update, delete, per-site stats).
 */
class Sites extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/sites/current', [
            ['methods' => 'GET', 'callback' => [$this, 'current'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/sites', [
            ['methods' => 'GET',  'callback' => [$this, 'list_all'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create'],   'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/sites/(?P<blog_id>\d+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get_site'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT',    'callback' => [$this, 'update_site'], 'permission_callback' => [Auth::class, 'tier3']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_site'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/sites/(?P<blog_id>\d+)/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'site_stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/sites/(?P<blog_id>\d+)/activate', [
            ['methods' => 'POST', 'callback' => [$this, 'activate_site'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/sites/(?P<blog_id>\d+)/deactivate', [
            ['methods' => 'POST', 'callback' => [$this, 'deactivate_site'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/network', [
            ['methods' => 'GET', 'callback' => [$this, 'network_info'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Current site / context
    // -------------------------------------------------------------------------

    public function current(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        return $this->success([
            'multisite'       => is_multisite(),
            'blog_id'         => get_current_blog_id(),
            'site_url'        => site_url(),
            'home_url'        => home_url(),
            'admin_url'       => admin_url(),
            'network_admin'   => is_network_admin(),
            'blog_name'       => get_option('blogname'),
            'blog_description'=> get_option('blogdescription'),
            'admin_email'     => get_option('admin_email'),
            'language'        => get_locale(),
            'charset'         => get_option('blog_charset'),
            'timezone'        => get_option('timezone_string') ?: 'UTC',
            'gmt_offset'      => (float) get_option('gmt_offset', 0),
            'date_format'     => get_option('date_format'),
            'time_format'     => get_option('time_format'),
            'posts_per_page'  => (int) get_option('posts_per_page'),
            'permalink_structure' => get_option('permalink_structure'),
            'db_prefix'       => $wpdb->prefix,
        ]);
    }

    // -------------------------------------------------------------------------
    // Network info
    // -------------------------------------------------------------------------

    public function network_info(\WP_REST_Request $r): \WP_REST_Response {
        if (!is_multisite()) {
            return $this->success(['multisite' => false]);
        }

        $network = get_network();

        return $this->success([
            'multisite'    => true,
            'network_id'   => $network ? (int) $network->id : null,
            'domain'       => $network ? $network->domain : '',
            'path'         => $network ? $network->path   : '',
            'site_name'    => $network ? $network->site_name : get_option('site_name'),
            'admin_email'  => get_site_option('admin_email'),
            'site_count'   => (int) get_blog_count(),
            'user_count'   => (int) get_user_count(),
        ]);
    }

    // -------------------------------------------------------------------------
    // List all sites (multisite)
    // -------------------------------------------------------------------------

    public function list_all(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->success(['multisite' => false, 'sites' => []]);
        }

        $paging = $this->parse_pagination($r, 200);

        $args = [
            'number'   => $paging['per_page'],
            'offset'   => $paging['offset'],
            'archived' => isset($r['archived']) ? (int) $r['archived'] : null,
            'deleted'  => 0,
            'spam'     => isset($r['spam']) ? (int) $r['spam'] : null,
            'public'   => isset($r['public']) ? (int) $r['public'] : null,
        ];

        // Remove null values
        $args = array_filter($args, fn($v) => $v !== null);

        $sites = get_sites($args);
        $total = (int) get_sites(array_merge($args, ['count' => true, 'number' => 0, 'offset' => 0]));

        $items = array_map([$this, 'format_site'], $sites);

        return $this->paginated($items, $total, $paging['page'], $paging['per_page']);
    }

    // -------------------------------------------------------------------------
    // Get single site
    // -------------------------------------------------------------------------

    public function get_site(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->error('Not a multisite installation', 400);
        }

        $site = get_site((int) $r['blog_id']);
        if (!$site) {
            return $this->error('Site not found', 404);
        }

        return $this->success($this->format_site($site));
    }

    // -------------------------------------------------------------------------
    // Create site (multisite)
    // -------------------------------------------------------------------------

    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->error('Not a multisite installation', 400);
        }

        $d = (array) $r->get_json_params();

        $domain = sanitize_text_field((string) ($d['domain'] ?? ''));
        $path   = sanitize_text_field((string) ($d['path']   ?? '/'));
        $title  = sanitize_text_field((string) ($d['title']  ?? ''));
        $user_id= (int) ($d['user_id'] ?? get_current_user_id());

        if ($domain === '' || $title === '') {
            return $this->error('domain and title are required');
        }

        $network = get_network();
        if (!$network) {
            return $this->error('Network not found', 500);
        }

        $id = wpmu_create_blog($domain, $path, $title, $user_id, [], $network->id);

        if (is_wp_error($id)) {
            return $this->error($id->get_error_message(), 500);
        }

        $this->log('create_site', 'site', (int) $id, [
            'domain' => $domain,
            'title'  => $title,
        ], 3);

        return $this->success($this->format_site(get_site((int) $id)), 201);
    }

    // -------------------------------------------------------------------------
    // Update site
    // -------------------------------------------------------------------------

    public function update_site(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->error('Not a multisite installation', 400);
        }

        $blog_id = (int) $r['blog_id'];
        $site    = get_site($blog_id);

        if (!$site) {
            return $this->error('Site not found', 404);
        }

        $d = (array) $r->get_json_params();

        switch_to_blog($blog_id);

        if (!empty($d['title'])) {
            update_option('blogname', sanitize_text_field((string) $d['title']));
        }
        if (isset($d['description'])) {
            update_option('blogdescription', sanitize_text_field((string) $d['description']));
        }
        if (isset($d['admin_email'])) {
            update_option('admin_email', sanitize_email((string) $d['admin_email']));
        }

        restore_current_blog();

        $this->log('update_site', 'site', $blog_id, ['fields' => array_keys($d)], 3);

        return $this->success($this->format_site(get_site($blog_id)));
    }

    // -------------------------------------------------------------------------
    // Delete site
    // -------------------------------------------------------------------------

    public function delete_site(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->error('Not a multisite installation', 400);
        }

        $blog_id = (int) $r['blog_id'];

        if ($blog_id === get_current_blog_id()) {
            return $this->error('Cannot delete the current site', 400);
        }
        if ($blog_id === 1) {
            return $this->error('Cannot delete the primary site', 400);
        }

        $site = get_site($blog_id);
        if (!$site) {
            return $this->error('Site not found', 404);
        }

        $drop = !empty($r->get_json_params()['drop_tables']);
        wpmu_delete_blog($blog_id, $drop);

        $this->log('delete_site', 'site', $blog_id, ['drop_tables' => $drop], 3);

        return $this->success(['deleted' => true, 'blog_id' => $blog_id]);
    }

    // -------------------------------------------------------------------------
    // Per-site stats
    // -------------------------------------------------------------------------

    public function site_stats(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->error('Not a multisite installation', 400);
        }

        $blog_id = (int) $r['blog_id'];
        $site    = get_site($blog_id);

        if (!$site) {
            return $this->error('Site not found', 404);
        }

        switch_to_blog($blog_id);

        $stats = [
            'blog_id'       => $blog_id,
            'domain'        => $site->domain,
            'posts'         => (int) wp_count_posts()->publish,
            'pages'         => (int) wp_count_posts('page')->publish,
            'comments'      => (int) wp_count_comments()->approved,
            'media'         => (int) wp_count_posts('attachment')->inherit,
            'users'         => (int) count_users()['total_users'],
            'active_plugins'=> count(get_option('active_plugins', [])),
            'theme'         => get_stylesheet(),
        ];

        restore_current_blog();

        return $this->success($stats);
    }

    // -------------------------------------------------------------------------
    // Activate / deactivate
    // -------------------------------------------------------------------------

    public function activate_site(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->error('Not a multisite installation', 400);
        }
        $blog_id = (int) $r['blog_id'];
        update_blog_status($blog_id, 'archived', 0);
        update_blog_status($blog_id, 'deleted',  0);
        $this->log('activate_site', 'site', $blog_id, [], 3);
        return $this->success(['activated' => true, 'blog_id' => $blog_id]);
    }

    public function deactivate_site(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!is_multisite()) {
            return $this->error('Not a multisite installation', 400);
        }
        $blog_id = (int) $r['blog_id'];
        if ($blog_id === 1) {
            return $this->error('Cannot deactivate the primary site', 400);
        }
        update_blog_status($blog_id, 'archived', 1);
        $this->log('deactivate_site', 'site', $blog_id, [], 3);
        return $this->success(['deactivated' => true, 'blog_id' => $blog_id]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function format_site(\WP_Site $site): array {
        return [
            'blog_id'      => (int) $site->blog_id,
            'domain'       => $site->domain,
            'path'         => $site->path,
            'registered'   => $site->registered,
            'last_updated' => $site->last_updated,
            'public'       => (bool) (int) $site->public,
            'archived'     => (bool) (int) $site->archived,
            'mature'       => (bool) (int) $site->mature,
            'spam'         => (bool) (int) $site->spam,
            'deleted'      => (bool) (int) $site->deleted,
            'network_id'   => (int) $site->network_id,
            'site_url'     => get_home_url((int) $site->blog_id),
        ];
    }
}
