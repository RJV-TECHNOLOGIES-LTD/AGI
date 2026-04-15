<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

use RJV_AGI_Bridge\Bridge\TenantIsolation;

/**
 * Event Collector
 *
 * Registers WordPress, WooCommerce, and generic CMS hooks to capture
 * server-side events automatically.  Data collection is always on —
 * there is no opt-out and no consent gate.
 *
 * Every event is pushed to the IngestQueue so the capture path is
 * non-blocking (sub-millisecond overhead per hook).  The queue is
 * drained asynchronously by the rjv_agi_dc_queue_process cron hook.
 *
 * Also enqueues the front-end JavaScript SDK on all public pages.
 *
 * Industries captured automatically
 * ───────────────────────────────────
 * general, ecommerce, saas, media, b2b, healthcare, finance, education,
 * real_estate, legal, manufacturing — detected from active plugins / theme.
 */
final class EventCollector {

    private static ?self $instance = null;

    /** Collector token used by the JS SDK to authenticate browser events. */
    private string $collector_token = '';

    /** Current tenant ID (empty = single-site). */
    private string $tenant_id = '';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->collector_token = (string) get_option('rjv_agi_dc_collector_token', '');
        $this->tenant_id       = TenantIsolation::instance()->get_tenant_id() ?? '';

        $this->register_hooks();
    }

    // -------------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------------

    private function register_hooks(): void {
        // ── Authentication ────────────────────────────────────────────────────
        add_action('wp_login',        [$this, 'on_login'],         10, 2);
        add_action('wp_login_failed', [$this, 'on_login_failed'],  10, 1);
        add_action('wp_logout',       [$this, 'on_logout'],        10, 1);
        add_action('user_register',   [$this, 'on_user_register'], 10, 1);
        add_action('delete_user',     [$this, 'on_user_delete'],   10, 1);
        add_action('profile_update',  [$this, 'on_profile_update'],10, 1);
        add_action('set_user_role',   [$this, 'on_role_change'],   10, 3);

        // ── Content ───────────────────────────────────────────────────────────
        add_action('publish_post',   [$this, 'on_publish_post'],  10, 2);
        add_action('publish_page',   [$this, 'on_publish_page'],  10, 2);
        add_action('trash_post',     [$this, 'on_trash_post'],    10, 1);
        add_action('comment_post',   [$this, 'on_comment_post'],  10, 2);
        add_action('save_post',      [$this, 'on_save_post'],     10, 3);

        // ── WooCommerce (loaded only when WC is active) ───────────────────────
        add_action('woocommerce_add_to_cart',              [$this, 'on_add_to_cart'],         10, 6);
        add_action('woocommerce_remove_cart_item',         [$this, 'on_remove_from_cart'],    10, 2);
        add_action('woocommerce_checkout_order_created',   [$this, 'on_order_created'],       10, 1);
        add_action('woocommerce_payment_complete',         [$this, 'on_payment_complete'],    10, 1);
        add_action('woocommerce_order_status_changed',     [$this, 'on_order_status_change'], 10, 3);
        add_action('woocommerce_new_customer_note',        [$this, 'on_customer_note'],       10, 1);
        add_action('woocommerce_update_product',           [$this, 'on_product_update'],      10, 1);
        add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_status_change'], 10, 3);

        // ── Search ────────────────────────────────────────────────────────────
        add_action('pre_get_posts', [$this, 'on_search'], 10, 1);

        // ── Media ─────────────────────────────────────────────────────────────
        add_action('add_attachment',    [$this, 'on_media_upload'],  10, 1);
        add_action('delete_attachment', [$this, 'on_media_delete'],  10, 1);

        // ── Forms (generic WP, Contact Form 7, Gravity Forms, WPForms) ───────
        add_action('wpcf7_before_send_mail',           [$this, 'on_cf7_submit'],      10, 1);
        add_action('gform_after_submission',           [$this, 'on_gf_submit'],       10, 2);
        add_action('wpforms_process_complete',         [$this, 'on_wpforms_submit'],  10, 4);

        // ── AGI Bridge own events ─────────────────────────────────────────────
        add_action('rjv_agi_agent_started',   [$this, 'on_agent_started'],   10, 1);
        add_action('rjv_agi_agent_completed', [$this, 'on_agent_completed'], 10, 1);
        add_action('rjv_agi_goal_executed',   [$this, 'on_goal_executed'],   10, 1);

        // ── Subject acceptance stamp on login ─────────────────────────────────
        add_action('wp_login', [$this, 'stamp_user_acceptance'], 20, 2);

        // ── Front-end JS SDK ─────────────────────────────────────────────────
        add_action('wp_enqueue_scripts', [$this, 'enqueue_sdk']);
    }

    // -------------------------------------------------------------------------
    // JS SDK enqueue
    // -------------------------------------------------------------------------

    /**
     * Enqueue the front-end collector JavaScript SDK on all public pages.
     * The SDK captures page views, clicks, scroll depth, time on page,
     * form interactions, Web Vitals, and JS errors — always on.
     */
    public function enqueue_sdk(): void {
        if ((string) get_option('rjv_agi_dc_js_enabled', '1') !== '1') {
            return;
        }

        $js_file = RJV_AGI_PLUGIN_DIR . 'admin/js/rjv-agi-collector.js';
        if (!file_exists($js_file)) {
            return;
        }

        $version = defined('RJV_AGI_VERSION') ? RJV_AGI_VERSION : '1';
        wp_enqueue_script(
            'rjv-agi-collector',
            RJV_AGI_PLUGIN_URL . 'admin/js/rjv-agi-collector.js',
            [],
            $version,
            true
        );

        $subject_id = '';
        $wp_user_id = 0;
        if (is_user_logged_in()) {
            $wp_user_id = get_current_user_id();
            $subject_id = 'user_' . $wp_user_id;
        }

        wp_localize_script('rjv-agi-collector', 'rjvDC', [
            'endpoint'      => esc_url(rest_url('rjv-agi/v1/dc/collect')),
            'token'         => $this->collector_token,
            'subject_id'    => $subject_id,
            'wp_user_id'    => $wp_user_id,
            'tenant_id'     => $this->tenant_id,
            'track_clicks'  => (string) get_option('rjv_agi_dc_js_track_clicks',  '1'),
            'track_scroll'  => (string) get_option('rjv_agi_dc_js_track_scroll',  '1'),
            'track_perf'    => (string) get_option('rjv_agi_dc_js_track_performance', '1'),
            'track_errors'  => (string) get_option('rjv_agi_dc_js_track_errors',  '0'),
            'industry'      => $this->detect_industry(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Terms acceptance stamp
    // -------------------------------------------------------------------------

    /**
     * Stamp per-user terms acceptance on every login (idempotent).
     *
     * @param \WP_User $user
     */
    public function stamp_user_acceptance(string $user_login, \WP_User $user): void {
        ConsentStore::instance()->record_subject_acceptance(
            'user_' . $user->ID,
            'user',
            ['wp_login' => $user_login, 'ip' => $this->current_ip()]
        );
    }

    // -------------------------------------------------------------------------
    // Auth event handlers
    // -------------------------------------------------------------------------

    public function on_login(string $user_login, \WP_User $user): void {
        $this->push('user_login', 'auth', [
            'wp_user_id'  => $user->ID,
            'user_login'  => $user_login,
            'roles'       => $user->roles,
        ], 'user_' . $user->ID);
    }

    public function on_login_failed(string $username): void {
        $this->push('user_login_failed', 'auth', [
            'username' => $username,
            'ip'       => $this->current_ip(),
        ]);
    }

    public function on_logout(int $user_id): void {
        $this->push('user_logout', 'auth', [
            'wp_user_id' => $user_id,
        ], 'user_' . $user_id);
    }

    public function on_user_register(int $user_id): void {
        $user = get_userdata($user_id);
        $this->push('user_register', 'auth', [
            'wp_user_id' => $user_id,
            'email'      => $user ? $user->user_email : '',
        ], 'user_' . $user_id);

        // Upsert profile immediately on registration
        if ($user) {
            ProfileStore::instance()->upsert([
                'subject_id'   => 'user_' . $user_id,
                'subject_type' => 'user',
                'wp_user_id'   => $user_id,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
            ]);
        }
    }

    public function on_user_delete(int $user_id): void {
        $this->push('user_deleted', 'auth', ['wp_user_id' => $user_id], 'user_' . $user_id);
    }

    public function on_profile_update(int $user_id): void {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        $this->push('user_profile_updated', 'auth', [
            'wp_user_id'  => $user_id,
            'email'       => $user->user_email,
            'display_name'=> $user->display_name,
        ], 'user_' . $user_id);

        ProfileStore::instance()->upsert([
            'subject_id'   => 'user_' . $user_id,
            'subject_type' => 'user',
            'wp_user_id'   => $user_id,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
        ]);
    }

    /**
     * @param string[]     $old_roles
     * @param string[]     $new_roles
     */
    public function on_role_change(int $user_id, string $role, array $old_roles): void {
        $this->push('user_role_changed', 'auth', [
            'wp_user_id' => $user_id,
            'new_role'   => $role,
            'old_roles'  => $old_roles,
        ], 'user_' . $user_id);
    }

    // -------------------------------------------------------------------------
    // Content event handlers
    // -------------------------------------------------------------------------

    public function on_publish_post(int $post_id, \WP_Post $post): void {
        $this->push('content_published', 'content', [
            'post_id'    => $post_id,
            'post_type'  => $post->post_type,
            'title'      => $post->post_title,
            'author_id'  => (int) $post->post_author,
            'categories' => wp_get_post_categories($post_id, ['fields' => 'names']),
        ], 'user_' . (int) $post->post_author);
    }

    public function on_publish_page(int $post_id, \WP_Post $post): void {
        $this->push('page_published', 'content', [
            'post_id'   => $post_id,
            'title'     => $post->post_title,
            'author_id' => (int) $post->post_author,
        ], 'user_' . (int) $post->post_author);
    }

    public function on_trash_post(int $post_id): void {
        $post = get_post($post_id);
        $this->push('content_trashed', 'content', [
            'post_id'   => $post_id,
            'post_type' => $post ? $post->post_type : '',
        ]);
    }

    public function on_comment_post(int $comment_id, int|string $approved): void {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }
        $this->push('comment_submitted', 'engagement', [
            'comment_id' => $comment_id,
            'post_id'    => (int) $comment->comment_post_ID,
            'approved'   => (int) $approved,
            'author_id'  => (int) $comment->user_id,
        ], $comment->user_id ? ('user_' . $comment->user_id) : '');
    }

    public function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if ($post->post_status === 'auto-draft' || $post->post_status === 'inherit') {
            return;
        }
        $this->push('content_saved', 'content', [
            'post_id'    => $post_id,
            'post_type'  => $post->post_type,
            'post_status'=> $post->post_status,
            'is_update'  => $update,
        ], 'user_' . (int) $post->post_author);
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    public function on_search(\WP_Query $query): void {
        if (!$query->is_main_query() || !$query->is_search() || is_admin()) {
            return;
        }
        $search_term = (string) get_search_query();
        if ($search_term === '') {
            return;
        }
        $this->push('site_search', 'navigation', [
            'search_term'    => $search_term,
            'results_count'  => null, // filled after the query runs
        ]);
    }

    // -------------------------------------------------------------------------
    // WooCommerce event handlers
    // -------------------------------------------------------------------------

    public function on_add_to_cart(
        string $cart_item_key,
        int $product_id,
        int $quantity,
        int $variation_id,
        array $variation,
        array $cart_item_data
    ): void {
        $product = wc_get_product($product_id);
        $this->push('product_added_to_cart', 'ecommerce', [
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'quantity'     => $quantity,
            'product_name' => $product ? $product->get_name() : '',
            'price'        => $product ? (float) $product->get_price() : 0.0,
            'sku'          => $product ? $product->get_sku() : '',
        ]);
    }

    public function on_remove_from_cart(string $cart_item_key, \WC_Cart $cart): void {
        $item = $cart->removed_cart_contents[$cart_item_key] ?? [];
        $this->push('product_removed_from_cart', 'ecommerce', [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'quantity'   => (int) ($item['quantity'] ?? 0),
        ]);
    }

    public function on_order_created(\WC_Order $order): void {
        $subject_id = $order->get_user_id() ? ('user_' . $order->get_user_id()) : '';
        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $items[] = [
                'product_id' => $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'total'      => (float) $item->get_total(),
            ];
        }
        $this->push('order_created', 'ecommerce', [
            'order_id'     => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'total'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
            'payment_method'=> $order->get_payment_method(),
            'items'        => $items,
            'item_count'   => count($items),
        ], $subject_id);
    }

    public function on_payment_complete(int $order_id): void {
        $order      = wc_get_order($order_id);
        $subject_id = ($order && $order->get_user_id()) ? ('user_' . $order->get_user_id()) : '';
        $this->push('order_payment_complete', 'ecommerce', [
            'order_id' => $order_id,
            'total'    => $order ? (float) $order->get_total() : 0.0,
            'currency' => $order ? $order->get_currency() : '',
        ], $subject_id);
    }

    public function on_order_status_change(int $order_id, string $old_status, string $new_status): void {
        $order      = wc_get_order($order_id);
        $subject_id = ($order && $order->get_user_id()) ? ('user_' . $order->get_user_id()) : '';
        $this->push('order_status_changed', 'ecommerce', [
            'order_id'   => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
        ], $subject_id);
    }

    public function on_customer_note(array $note_data): void {
        $this->push('customer_note_added', 'ecommerce', [
            'order_id' => (int) ($note_data['order_id'] ?? 0),
        ]);
    }

    public function on_product_update(int $product_id): void {
        $product = wc_get_product($product_id);
        $this->push('product_updated', 'ecommerce', [
            'product_id'   => $product_id,
            'product_name' => $product ? $product->get_name() : '',
            'price'        => $product ? (float) $product->get_price() : 0.0,
            'sku'          => $product ? $product->get_sku() : '',
            'stock_status' => $product ? $product->get_stock_status() : '',
        ]);
    }

    public function on_stock_status_change(int $product_id, string $status, \WC_Product $product): void {
        $this->push('product_stock_changed', 'ecommerce', [
            'product_id'  => $product_id,
            'stock_status'=> $status,
            'stock_qty'   => $product->get_stock_quantity(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Media event handlers
    // -------------------------------------------------------------------------

    public function on_media_upload(int $post_id): void {
        $this->push('media_uploaded', 'content', [
            'attachment_id' => $post_id,
            'mime_type'     => get_post_mime_type($post_id),
        ]);
    }

    public function on_media_delete(int $post_id): void {
        $this->push('media_deleted', 'content', ['attachment_id' => $post_id]);
    }

    // -------------------------------------------------------------------------
    // Form event handlers
    // -------------------------------------------------------------------------

    public function on_cf7_submit(\WPCF7_ContactForm $form): void {
        $this->push('form_submitted', 'form', [
            'form_id'   => $form->id(),
            'form_name' => $form->title(),
            'plugin'    => 'contact_form_7',
        ]);
    }

    /** @param array<string,mixed> $entry */
    public function on_gf_submit(array $entry, \GF_Form $form): void {
        $this->push('form_submitted', 'form', [
            'form_id'   => (int) ($form['id'] ?? 0),
            'form_name' => (string) ($form['title'] ?? ''),
            'entry_id'  => (int) ($entry['id'] ?? 0),
            'plugin'    => 'gravity_forms',
        ]);
    }

    /** @param array<string,mixed> $fields */
    public function on_wpforms_submit(array $fields, array $entry, array $form_data, int $entry_id): void {
        $this->push('form_submitted', 'form', [
            'form_id'   => (int) ($form_data['id'] ?? 0),
            'form_name' => (string) ($form_data['settings']['form_title'] ?? ''),
            'entry_id'  => $entry_id,
            'plugin'    => 'wpforms',
        ]);
    }

    // -------------------------------------------------------------------------
    // AGI Bridge event handlers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $agent_data */
    public function on_agent_started(array $agent_data): void {
        $this->push('agi_agent_started', 'agi', [
            'agent_id'   => (string) ($agent_data['agent_id'] ?? ''),
            'agent_type' => (string) ($agent_data['type'] ?? ''),
        ], 'agent_' . ($agent_data['agent_id'] ?? ''), 'agent');
    }

    /** @param array<string,mixed> $agent_data */
    public function on_agent_completed(array $agent_data): void {
        $this->push('agi_agent_completed', 'agi', [
            'agent_id' => (string) ($agent_data['agent_id'] ?? ''),
            'status'   => (string) ($agent_data['status'] ?? ''),
        ], 'agent_' . ($agent_data['agent_id'] ?? ''), 'agent');
    }

    /** @param array<string,mixed> $goal_data */
    public function on_goal_executed(array $goal_data): void {
        $this->push('agi_goal_executed', 'agi', [
            'goal_id'  => (string) ($goal_data['goal_id'] ?? ''),
            'result'   => (string) ($goal_data['result'] ?? ''),
        ], 'agent_system', 'agent');
    }

    // -------------------------------------------------------------------------
    // Internal push helper
    // -------------------------------------------------------------------------

    /**
     * Enqueue an event on the IngestQueue.
     *
     * @param array<string,mixed> $properties
     */
    private function push(
        string $event_type,
        string $category,
        array  $properties = [],
        string $subject_id = '',
        string $subject_type = 'user'
    ): void {
        if ((string) get_option('rjv_agi_dc_enabled', '1') !== '1') {
            return;
        }

        IngestQueue::instance()->push('event', [
            'event_type'     => $event_type,
            'event_category' => $category,
            'industry'       => $this->detect_industry(),
            'subject_id'     => $subject_id !== '' ? $subject_id : $this->current_subject_id(),
            'subject_type'   => $subject_type,
            'page_url'       => $this->current_url(),
            'referrer'       => isset($_SERVER['HTTP_REFERER'])
                                    ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
                                    : '',
            'properties'     => $properties,
            'context'        => $this->build_context(),
            'tenant_id'      => $this->tenant_id,
        ], 0, $this->tenant_id);
    }

    /**
     * Build a standardised request context array.
     *
     * @return array<string,mixed>
     */
    private function build_context(): array {
        $ua     = isset($_SERVER['HTTP_USER_AGENT'])
                    ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                    : '';
        $device = SessionManager::instance()->parse_device($ua);
        return [
            'ip'         => $this->current_ip(),
            'user_agent' => $ua,
            'device'     => $device,
            'language'   => isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
                                ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])), 0, 20)
                                : '',
        ];
    }

    private function current_subject_id(): string {
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }
        return '';
    }

    private function current_url(): string {
        if (!isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'])) {
            return '';
        }
        $scheme = is_ssl() ? 'https' : 'http';
        return esc_url_raw(
            $scheme . '://' . wp_unslash($_SERVER['HTTP_HOST']) . wp_unslash($_SERVER['REQUEST_URI'])
        );
    }

    private function current_ip(): string {
        $candidates = [
            'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP', 'REMOTE_ADDR',
        ];
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $ips = explode(',', wp_unslash($_SERVER[$key]));
            $ip  = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '';
    }

    /**
     * Detect the primary industry vertical from active plugins / post types.
     * Heuristic-based; the AGI can override via profile traits.
     */
    private function detect_industry(): string {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $option = (string) get_option('rjv_agi_dc_industry', '');
        if ($option !== '') {
            $cached = $option;
            return $cached;
        }

        // Heuristic detection from active plugins
        $active = (array) get_option('active_plugins', []);
        $all    = implode(',', $active);

        if (str_contains($all, 'woocommerce')) {
            $cached = 'ecommerce';
        } elseif (str_contains($all, 'learndash') || str_contains($all, 'lifterlms') || str_contains($all, 'tutor')) {
            $cached = 'education';
        } elseif (str_contains($all, 'wp-job-manager') || str_contains($all, 'members')) {
            $cached = 'saas';
        } elseif (str_contains($all, 'give') || str_contains($all, 'charitable')) {
            $cached = 'finance';
        } else {
            $cached = 'general';
        }

        return $cached;
    }
}
