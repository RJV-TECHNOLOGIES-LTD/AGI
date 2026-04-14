<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * Forms Integration
 *
 * Full administration surface for Contact Form 7, WPForms, and Gravity Forms:
 * form CRUD, notifications, confirmations, spam management, entry export,
 * form duplication, and AI-powered auto-reply generation.
 * Auto-detects which form plugin(s) are active at runtime.
 */
class Forms extends Base {

    public function register_routes(): void {
        // Forms CRUD
        register_rest_route($this->namespace, '/forms', [
            ['methods' => 'GET',  'callback' => [$this, 'list_forms'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_form'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/forms/(?P<form_id>[a-zA-Z0-9_-]+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_form'],    'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['plugin' => ['default' => '']]],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_form'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_form'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/forms/(?P<form_id>[a-zA-Z0-9_-]+)/duplicate', [
            ['methods' => 'POST', 'callback' => [$this, 'duplicate_form'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Entries
        register_rest_route($this->namespace, '/forms/entries', [
            ['methods' => 'GET', 'callback' => [$this, 'list_entries'], 'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['form_id' => ['default' => 0], 'per_page' => ['default' => 20], 'page' => ['default' => 1], 'plugin' => ['default' => ''], 'search' => ['default' => ''], 'status' => ['default' => 'active']]],
        ]);
        register_rest_route($this->namespace, '/forms/entries/(?P<id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_entry'],    'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['plugin' => ['default' => '']]],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_entry'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_entry'], 'permission_callback' => [Auth::class, 'tier3'],
             'args' => ['plugin' => ['default' => '']]],
        ]);
        register_rest_route($this->namespace, '/forms/entries/(?P<id>\d+)/mark-spam', [
            ['methods' => 'POST', 'callback' => [$this, 'mark_spam'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/forms/entries/(?P<id>\d+)/unstar', [
            ['methods' => 'POST', 'callback' => [$this, 'unstar_entry'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/forms/entries/(?P<id>\d+)/generate-reply', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_reply'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Entry export
        register_rest_route($this->namespace, '/forms/(?P<form_id>[a-zA-Z0-9_-]+)/export-entries', [
            ['methods' => 'POST', 'callback' => [$this, 'export_entries'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // GF Notifications & Confirmations
        register_rest_route($this->namespace, '/forms/(?P<form_id>[a-zA-Z0-9_-]+)/notifications', [
            ['methods' => 'GET',  'callback' => [$this, 'list_notifications'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_notification'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/forms/(?P<form_id>[a-zA-Z0-9_-]+)/notifications/(?P<notif_id>[a-zA-Z0-9_-]+)', [
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_notification'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_notification'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/forms/(?P<form_id>[a-zA-Z0-9_-]+)/confirmations', [
            ['methods' => 'GET',  'callback' => [$this, 'list_confirmations'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'update_confirmation'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // CF7 shortcode helper
        register_rest_route($this->namespace, '/forms/(?P<form_id>[a-zA-Z0-9_-]+)/shortcode', [
            ['methods' => 'GET', 'callback' => [$this, 'get_shortcode'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);

        // Active plugins discovery
        register_rest_route($this->namespace, '/forms/active-plugins', [
            ['methods' => 'GET', 'callback' => [$this, 'active_plugins'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Forms list
    // -------------------------------------------------------------------------

    public function list_forms(\WP_REST_Request $r): \WP_REST_Response {
        $forms = [];

        // Gravity Forms
        if ($this->is_gf_active()) {
            foreach (\GFAPI::get_forms() as $form) {
                $forms[] = [
                    'id'      => (int) $form['id'],
                    'title'   => $form['title'],
                    'plugin'  => 'gravityforms',
                    'entries' => (int) \GFAPI::count_entries((int) $form['id']),
                ];
            }
        }

        // WPForms
        if ($this->is_wpforms_active() && function_exists('wpforms')) {
            $wpf_forms = wpforms()->form->get('', ['fields' => 'ID,post_title', 'posts_per_page' => 200]);
            if (is_array($wpf_forms)) {
                foreach ($wpf_forms as $form) {
                    $forms[] = [
                        'id'     => $form->ID,
                        'title'  => $form->post_title,
                        'plugin' => 'wpforms',
                    ];
                }
            }
        }

        // Contact Form 7
        if ($this->is_cf7_active()) {
            $cf7_forms = get_posts(['post_type' => 'wpcf7_contact_form', 'numberposts' => 200, 'post_status' => 'publish']);
            foreach ($cf7_forms as $form) {
                $forms[] = [
                    'id'     => $form->ID,
                    'title'  => $form->post_title,
                    'plugin' => 'cf7',
                ];
            }
        }

        $this->log('forms_list', 'form', 0, ['count' => count($forms)]);

        return $this->success(['forms' => $forms]);
    }

    // -------------------------------------------------------------------------
    // Entries
    // -------------------------------------------------------------------------

    public function list_entries(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = (int) $r['form_id'];
        $plugin  = sanitize_key((string) $r['plugin']);
        $pp      = min((int) $r['per_page'], 100);
        $page    = max(1, (int) $r['page']);
        $offset  = ($page - 1) * $pp;

        // Gravity Forms
        if ($plugin === 'gravityforms' || ($plugin === '' && $this->is_gf_active())) {
            if (!$this->is_gf_active()) {
                return $this->error('Gravity Forms is not active', 503);
            }

            $criteria = $form_id ? ['field_filters' => []] : [];
            $entries  = \GFAPI::get_entries($form_id ?: 0, $criteria, ['key' => 'id', 'direction' => 'DESC'], ['offset' => $offset, 'page_size' => $pp], $total);

            return $this->success([
                'entries' => array_map([$this, 'fmt_gf_entry'], is_array($entries) ? $entries : []),
                'total'   => (int) $total,
                'page'    => $page,
                'per_page'=> $pp,
                'plugin'  => 'gravityforms',
            ]);
        }

        // WPForms
        if ($plugin === 'wpforms' || ($plugin === '' && $this->is_wpforms_active())) {
            if (!$this->is_wpforms_active()) {
                return $this->error('WPForms is not active', 503);
            }

            if (!function_exists('wpforms')) {
                return $this->error('WPForms is not fully initialised', 503);
            }

            $entries = wpforms()->entry->get_entries([
                'form_id'  => $form_id ?: null,
                'number'   => $pp,
                'offset'   => $offset,
                'order'    => 'DESC',
            ]);

            $total = wpforms()->entry->get_entries(['form_id' => $form_id ?: null, 'number' => -1], true);

            return $this->success([
                'entries' => array_map([$this, 'fmt_wpf_entry'], is_array($entries) ? $entries : []),
                'total'   => (int) $total,
                'page'    => $page,
                'per_page'=> $pp,
                'plugin'  => 'wpforms',
            ]);
        }

        return $this->error('No supported form plugin is active', 503);
    }

    public function get_entry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $plugin = sanitize_key((string) $r['plugin']);

        if ($plugin === 'gravityforms' || ($plugin === '' && $this->is_gf_active())) {
            if (!$this->is_gf_active()) {
                return $this->error('Gravity Forms is not active', 503);
            }

            $entry = \GFAPI::get_entry($id);
            if (is_wp_error($entry)) {
                return $this->error('Entry not found', 404);
            }

            return $this->success($this->fmt_gf_entry($entry));
        }

        if ($plugin === 'wpforms' || ($plugin === '' && $this->is_wpforms_active())) {
            if (!$this->is_wpforms_active() || !function_exists('wpforms')) {
                return $this->error('WPForms is not active', 503);
            }

            $entry = wpforms()->entry->get($id);
            if (!$entry) {
                return $this->error('Entry not found', 404);
            }

            return $this->success($this->fmt_wpf_entry($entry));
        }

        return $this->error('No supported form plugin is active', 503);
    }

    public function delete_entry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $plugin = sanitize_key((string) $r['plugin']);

        if ($plugin === 'gravityforms' || ($plugin === '' && $this->is_gf_active())) {
            if (!$this->is_gf_active()) {
                return $this->error('Gravity Forms is not active', 503);
            }

            $result = \GFAPI::delete_entry($id);
            if (is_wp_error($result)) {
                return $this->error($result->get_error_message(), 500);
            }

            $this->log('forms_delete_entry', 'form_entry', $id, ['plugin' => 'gravityforms'], 3);

            return $this->success(['deleted' => true, 'id' => $id]);
        }

        if ($plugin === 'wpforms' || ($plugin === '' && $this->is_wpforms_active())) {
            if (!$this->is_wpforms_active() || !function_exists('wpforms')) {
                return $this->error('WPForms is not active', 503);
            }

            wpforms()->entry->delete($id);
            $this->log('forms_delete_entry', 'form_entry', $id, ['plugin' => 'wpforms'], 3);

            return $this->success(['deleted' => true, 'id' => $id]);
        }

        return $this->error('No supported form plugin is active', 503);
    }

    // -------------------------------------------------------------------------
    // AI auto-reply
    // -------------------------------------------------------------------------

    public function generate_reply(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));

        // Fetch entry
        $entry_text = '';

        if ($plugin === 'gravityforms' || ($plugin === '' && $this->is_gf_active())) {
            if ($this->is_gf_active()) {
                $entry = \GFAPI::get_entry($id);
                if (!is_wp_error($entry)) {
                    $entry_text = $this->entry_to_text($this->fmt_gf_entry($entry));
                }
            }
        }

        if ($entry_text === '' && ($plugin === 'wpforms' || ($plugin === '' && $this->is_wpforms_active()))) {
            if ($this->is_wpforms_active() && function_exists('wpforms')) {
                $entry = wpforms()->entry->get($id);
                if ($entry) {
                    $entry_text = $this->entry_to_text($this->fmt_wpf_entry($entry));
                }
            }
        }

        if ($entry_text === '') {
            return $this->error('Entry not found or no supported form plugin active', 404);
        }

        $tone      = sanitize_text_field((string) ($d['tone'] ?? 'professional'));
        $site_name = get_bloginfo('name');

        $ai  = new Router();
        $res = $ai->complete(
            "You are a helpful customer support representative for {$site_name}. Write clear, {$tone} replies in British English. Do not use placeholder brackets.",
            "Write a reply to the following form submission:\n\n{$entry_text}" . (!empty($d['context']) ? "\n\nAdditional context: " . sanitize_textarea_field((string) $d['context']) : ''),
            ['provider' => $d['provider'] ?? '', 'temperature' => 0.5, 'max_tokens' => 800]
        );

        if (!empty($res['error'])) {
            return $this->error($res['error'], 500);
        }

        $this->log('forms_gen_reply', 'form_entry', $id, ['plugin' => $plugin], 2);

        return $this->success($res);
    }

    // =========================================================================
    // Form CRUD
    // =========================================================================

    public function get_form(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = $r['form_id'];
        $plugin  = sanitize_key((string) ($r['plugin'] ?? ''));

        if (($plugin === '' && $this->is_gf_active()) || $plugin === 'gravityforms') {
            if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);
            $form = \GFAPI::get_form((int) $form_id);
            if (!$form) return $this->error('Form not found', 404);
            return $this->success($form);
        }

        if (($plugin === '' && $this->is_cf7_active()) || $plugin === 'cf7') {
            if (!$this->is_cf7_active()) return $this->error('CF7 is not active', 503);
            $post = get_post((int) $form_id);
            if (!$post || $post->post_type !== 'wpcf7_contact_form') return $this->error('Form not found', 404);
            $cf7  = \WPCF7_ContactForm::get_instance($post->ID);
            return $this->success($this->fmt_cf7($cf7));
        }

        return $this->error('No supported form plugin active', 503);
    }

    public function create_form(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));
        $title  = sanitize_text_field((string) ($d['title'] ?? ''));

        if (empty($title)) return $this->error('title is required');

        // Gravity Forms
        if (($plugin === '' && $this->is_gf_active()) || $plugin === 'gravityforms') {
            if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

            $form = [
                'title'       => $title,
                'description' => sanitize_textarea_field((string) ($d['description'] ?? '')),
                'fields'      => $d['fields'] ?? [],
                'button'      => $d['button'] ?? ['type' => 'text', 'text' => 'Submit'],
                'labelPlacement' => $d['label_placement'] ?? 'top_label',
            ];

            $form_id = \GFAPI::add_form($form);
            if (is_wp_error($form_id)) return $this->error($form_id->get_error_message(), 500);

            $this->log('forms_create', 'form', $form_id, ['plugin' => 'gravityforms', 'title' => $title], 2);
            return $this->success(['form_id' => $form_id, 'title' => $title, 'plugin' => 'gravityforms'], 201);
        }

        // CF7
        if (($plugin === '' && $this->is_cf7_active()) || $plugin === 'cf7') {
            if (!$this->is_cf7_active()) return $this->error('CF7 is not active', 503);

            $post_id = wp_insert_post([
                'post_title'  => $title,
                'post_type'   => 'wpcf7_contact_form',
                'post_status' => 'publish',
            ]);

            if (is_wp_error($post_id)) return $this->error($post_id->get_error_message(), 500);

            // Set form body and mail template if provided
            if (!empty($d['form_body'])) {
                update_post_meta($post_id, '_form', sanitize_textarea_field($d['form_body']));
            }

            $this->log('forms_create', 'form', $post_id, ['plugin' => 'cf7', 'title' => $title], 2);
            return $this->success(['form_id' => $post_id, 'title' => $title, 'plugin' => 'cf7'], 201);
        }

        return $this->error('No supported form plugin active', 503);
    }

    public function update_form(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = $r['form_id'];
        $d       = (array) $r->get_json_params();
        $plugin  = sanitize_key((string) ($d['plugin'] ?? ''));

        // Gravity Forms
        if (($plugin === '' && $this->is_gf_active()) || $plugin === 'gravityforms') {
            if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

            $form = \GFAPI::get_form((int) $form_id);
            if (!$form) return $this->error('Form not found', 404);

            if (isset($d['title']))         $form['title']       = sanitize_text_field($d['title']);
            if (isset($d['description']))   $form['description'] = sanitize_textarea_field($d['description']);
            if (isset($d['fields']))        $form['fields']      = (array) $d['fields'];
            if (isset($d['button']))        $form['button']      = (array) $d['button'];
            if (isset($d['is_active']))     $form['is_active']   = (int) (bool) $d['is_active'];

            $result = \GFAPI::update_form($form);
            if (is_wp_error($result)) return $this->error($result->get_error_message(), 500);

            $this->log('forms_update', 'form', (int) $form_id, ['plugin' => 'gravityforms'], 2);
            return $this->success(['updated' => true, 'form_id' => (int) $form_id]);
        }

        // CF7
        if (($plugin === '' && $this->is_cf7_active()) || $plugin === 'cf7') {
            if (!$this->is_cf7_active()) return $this->error('CF7 is not active', 503);

            $post = get_post((int) $form_id);
            if (!$post || $post->post_type !== 'wpcf7_contact_form') return $this->error('Form not found', 404);

            $update = [];
            if (isset($d['title']))     $update['post_title'] = sanitize_text_field($d['title']);
            if (!empty($update))        wp_update_post(array_merge(['ID' => (int) $form_id], $update));
            if (isset($d['form_body'])) update_post_meta((int) $form_id, '_form', sanitize_textarea_field($d['form_body']));

            $this->log('forms_update', 'form', (int) $form_id, ['plugin' => 'cf7'], 2);
            return $this->success(['updated' => true, 'form_id' => (int) $form_id]);
        }

        return $this->error('No supported form plugin active', 503);
    }

    public function delete_form(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = $r['form_id'];
        $plugin  = sanitize_key((string) ($r->get_param('plugin') ?? ''));

        if (($plugin === '' && $this->is_gf_active()) || $plugin === 'gravityforms') {
            if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);
            $result = \GFAPI::delete_form((int) $form_id);
            if (is_wp_error($result)) return $this->error($result->get_error_message(), 500);
            $this->log('forms_delete', 'form', (int) $form_id, ['plugin' => 'gravityforms'], 3);
            return $this->success(['deleted' => true]);
        }

        if (($plugin === '' && $this->is_cf7_active()) || $plugin === 'cf7') {
            if (!$this->is_cf7_active()) return $this->error('CF7 is not active', 503);
            wp_delete_post((int) $form_id, true);
            $this->log('forms_delete', 'form', (int) $form_id, ['plugin' => 'cf7'], 3);
            return $this->success(['deleted' => true]);
        }

        return $this->error('No supported form plugin active', 503);
    }

    public function duplicate_form(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = $r['form_id'];
        $d       = (array) $r->get_json_params();
        $plugin  = sanitize_key((string) ($d['plugin'] ?? ''));

        if (($plugin === '' && $this->is_gf_active()) || $plugin === 'gravityforms') {
            if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

            $form = \GFAPI::get_form((int) $form_id);
            if (!$form) return $this->error('Form not found', 404);

            unset($form['id']);
            $form['title'] = sanitize_text_field((string) ($d['title'] ?? $form['title'] . ' (Copy)'));

            $new_id = \GFAPI::add_form($form);
            if (is_wp_error($new_id)) return $this->error($new_id->get_error_message(), 500);

            $this->log('forms_duplicate', 'form', $new_id, ['source' => $form_id, 'plugin' => 'gravityforms'], 2);
            return $this->success(['form_id' => $new_id, 'title' => $form['title']], 201);
        }

        if (($plugin === '' && $this->is_cf7_active()) || $plugin === 'cf7') {
            if (!$this->is_cf7_active()) return $this->error('CF7 is not active', 503);

            $post = get_post((int) $form_id);
            if (!$post || $post->post_type !== 'wpcf7_contact_form') return $this->error('Form not found', 404);

            $new_id = wp_insert_post([
                'post_title'  => sanitize_text_field((string) ($d['title'] ?? $post->post_title . ' (Copy)')),
                'post_type'   => 'wpcf7_contact_form',
                'post_status' => 'publish',
            ]);

            // Copy all meta
            foreach (get_post_meta($post->ID) as $key => $values) {
                update_post_meta($new_id, $key, maybe_unserialize($values[0]));
            }

            $this->log('forms_duplicate', 'form', $new_id, ['source' => $form_id, 'plugin' => 'cf7'], 2);
            return $this->success(['form_id' => $new_id], 201);
        }

        return $this->error('No supported form plugin active', 503);
    }

    // =========================================================================
    // Entry update & spam management (GF)
    // =========================================================================

    public function update_entry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $d      = (array) $r->get_json_params();
        $plugin = sanitize_key((string) ($d['plugin'] ?? ''));

        if (($plugin === '' && $this->is_gf_active()) || $plugin === 'gravityforms') {
            if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

            $entry = \GFAPI::get_entry($id);
            if (is_wp_error($entry)) return $this->error('Entry not found', 404);

            if (isset($d['status']))                $entry['status']        = sanitize_key($d['status']);
            if (isset($d['is_starred']))            $entry['is_starred']    = (string) (int) (bool) $d['is_starred'];
            if (isset($d['is_read']))               $entry['is_read']       = (string) (int) (bool) $d['is_read'];
            if (!empty($d['field_values']) && is_array($d['field_values'])) {
                foreach ($d['field_values'] as $field_id => $value) {
                    $entry[(string) $field_id] = $value;
                }
            }

            $result = \GFAPI::update_entry($entry);
            if (is_wp_error($result)) return $this->error($result->get_error_message(), 500);

            $this->log('forms_update_entry', 'form_entry', $id, ['plugin' => 'gravityforms'], 2);
            return $this->success(['updated' => true, 'id' => $id]);
        }

        return $this->error('Entry updates are only supported for Gravity Forms', 503);
    }

    public function mark_spam(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id = (int) $r['id'];

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $entry = \GFAPI::get_entry($id);
        if (is_wp_error($entry)) return $this->error('Entry not found', 404);

        $entry['status'] = 'spam';
        \GFAPI::update_entry($entry);

        $this->log('forms_mark_spam', 'form_entry', $id, ['plugin' => 'gravityforms'], 2);
        return $this->success(['marked_spam' => true, 'id' => $id]);
    }

    public function unstar_entry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id = (int) $r['id'];

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $entry = \GFAPI::get_entry($id);
        if (is_wp_error($entry)) return $this->error('Entry not found', 404);

        $entry['is_starred'] = '0';
        \GFAPI::update_entry($entry);

        return $this->success(['unstarred' => true, 'id' => $id]);
    }

    // =========================================================================
    // Entry export
    // =========================================================================

    public function export_entries(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = $r['form_id'];
        $d       = (array) $r->get_json_params();
        $plugin  = sanitize_key((string) ($d['plugin'] ?? ''));
        $format  = sanitize_key((string) ($d['format'] ?? 'json'));

        if (($plugin === '' && $this->is_gf_active()) || $plugin === 'gravityforms') {
            if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

            $search   = $d['search'] ?? [];
            $entries  = \GFAPI::get_entries((int) $form_id, $search, ['key' => 'id', 'direction' => 'DESC'], ['page_size' => 1000]);

            if (is_wp_error($entries)) return $this->error($entries->get_error_message(), 500);

            $data = array_map([$this, 'fmt_gf_entry'], (array) $entries);

            $this->log('forms_export_entries', 'form', (int) $form_id, ['count' => count($data), 'plugin' => 'gravityforms'], 2);
            return $this->success(['entries' => $data, 'count' => count($data), 'format' => $format]);
        }

        return $this->error('No supported form plugin active', 503);
    }

    // =========================================================================
    // GF Notifications
    // =========================================================================

    public function list_notifications(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = $r['form_id'];

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $form = \GFAPI::get_form((int) $form_id);
        if (!$form) return $this->error('Form not found', 404);

        return $this->success([
            'form_id'       => (int) $form_id,
            'notifications' => array_values($form['notifications'] ?? []),
        ]);
    }

    public function create_notification(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = (int) $r['form_id'];

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $form = \GFAPI::get_form($form_id);
        if (!$form) return $this->error('Form not found', 404);

        $d    = (array) $r->get_json_params();
        $id   = 'notification_' . uniqid();
        $form['notifications'][$id] = $this->build_gf_notification($id, $d);

        \GFAPI::update_form($form);
        $this->log('forms_create_notification', 'form', $form_id, ['notif_id' => $id], 2);
        return $this->success(['notif_id' => $id, 'form_id' => $form_id], 201);
    }

    public function update_notification(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id  = (int) $r['form_id'];
        $notif_id = sanitize_key((string) $r['notif_id']);

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $form = \GFAPI::get_form($form_id);
        if (!$form || !isset($form['notifications'][$notif_id])) return $this->error('Notification not found', 404);

        $d = (array) $r->get_json_params();
        $form['notifications'][$notif_id] = array_merge(
            $form['notifications'][$notif_id],
            $this->build_gf_notification($notif_id, $d)
        );

        \GFAPI::update_form($form);
        $this->log('forms_update_notification', 'form', $form_id, ['notif_id' => $notif_id], 2);
        return $this->success(['updated' => true, 'notif_id' => $notif_id]);
    }

    public function delete_notification(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id  = (int) $r['form_id'];
        $notif_id = sanitize_key((string) $r['notif_id']);

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $form = \GFAPI::get_form($form_id);
        if (!$form || !isset($form['notifications'][$notif_id])) return $this->error('Notification not found', 404);

        unset($form['notifications'][$notif_id]);
        \GFAPI::update_form($form);

        $this->log('forms_delete_notification', 'form', $form_id, ['notif_id' => $notif_id], 3);
        return $this->success(['deleted' => true]);
    }

    private function build_gf_notification(string $id, array $d): array {
        return [
            'id'        => $id,
            'name'      => sanitize_text_field((string) ($d['name'] ?? 'Notification')),
            'isActive'  => (bool) ($d['is_active'] ?? true),
            'to'        => sanitize_text_field((string) ($d['to'] ?? '')),
            'toType'    => sanitize_key((string) ($d['to_type'] ?? 'email')),
            'from'      => sanitize_email((string) ($d['from'] ?? get_option('admin_email', ''))),
            'fromName'  => sanitize_text_field((string) ($d['from_name'] ?? get_bloginfo('name'))),
            'replyTo'   => sanitize_text_field((string) ($d['reply_to'] ?? '')),
            'subject'   => sanitize_text_field((string) ($d['subject'] ?? 'New form submission')),
            'message'   => wp_kses_post((string) ($d['message'] ?? '{all_fields}')),
            'event'     => sanitize_key((string) ($d['event'] ?? 'form_submission')),
        ];
    }

    // =========================================================================
    // GF Confirmations
    // =========================================================================

    public function list_confirmations(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = $r['form_id'];

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $form = \GFAPI::get_form((int) $form_id);
        if (!$form) return $this->error('Form not found', 404);

        return $this->success([
            'form_id'       => (int) $form_id,
            'confirmations' => array_values($form['confirmations'] ?? []),
        ]);
    }

    public function update_confirmation(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $form_id = (int) $r['form_id'];

        if (!$this->is_gf_active()) return $this->error('Gravity Forms is not active', 503);

        $form = \GFAPI::get_form($form_id);
        if (!$form) return $this->error('Form not found', 404);

        $d    = (array) $r->get_json_params();
        $id   = sanitize_key((string) ($d['id'] ?? 'default_confirmation'));

        $form['confirmations'][$id] = [
            'id'       => $id,
            'name'     => sanitize_text_field((string) ($d['name'] ?? 'Default')),
            'isActive' => (bool) ($d['is_active'] ?? true),
            'type'     => sanitize_key((string) ($d['type'] ?? 'message')),
            'message'  => wp_kses_post((string) ($d['message'] ?? 'Thank you for your submission.')),
            'url'      => esc_url_raw((string) ($d['url'] ?? '')),
            'pageId'   => (int) ($d['page_id'] ?? 0),
        ];

        \GFAPI::update_form($form);
        $this->log('forms_update_confirmation', 'form', $form_id, ['conf_id' => $id], 2);
        return $this->success(['updated' => true, 'confirmation_id' => $id]);
    }

    // =========================================================================
    // CF7 shortcode
    // =========================================================================

    public function get_shortcode(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_cf7_active()) return $this->error('CF7 is not active', 503);

        $form_id = (int) $r['form_id'];
        $post    = get_post($form_id);
        if (!$post || $post->post_type !== 'wpcf7_contact_form') {
            return $this->error('Form not found', 404);
        }

        $shortcode = '[contact-form-7 id="' . $form_id . '" title="' . esc_attr($post->post_title) . '"]';
        return $this->success(['form_id' => $form_id, 'shortcode' => $shortcode]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fmt_cf7(\WPCF7_ContactForm $cf7): array {
        return [
            'id'        => $cf7->id(),
            'title'     => $cf7->title(),
            'plugin'    => 'cf7',
            'shortcode' => '[contact-form-7 id="' . $cf7->id() . '" title="' . esc_attr($cf7->title()) . '"]',
            'form_body' => $cf7->prop('form'),
            'mail'      => $cf7->prop('mail'),
            'mail_2'    => $cf7->prop('mail_2'),
            'messages'  => $cf7->prop('messages'),
        ];
    }

    // =========================================================================

    public function active_plugins(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success([
            'gravityforms' => $this->is_gf_active(),
            'wpforms'      => $this->is_wpforms_active(),
            'cf7'          => $this->is_cf7_active(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function fmt_gf_entry(array $entry): array {
        $fields = [];
        foreach ($entry as $key => $value) {
            if (is_numeric($key)) {
                $fields[(string) $key] = $value;
            }
        }

        return [
            'id'         => (int) $entry['id'],
            'form_id'    => (int) $entry['form_id'],
            'status'     => $entry['status'] ?? 'active',
            'date'       => $entry['date_created'] ?? '',
            'ip'         => $entry['ip'] ?? '',
            'fields'     => $fields,
            'plugin'     => 'gravityforms',
        ];
    }

    private function fmt_wpf_entry(object $entry): array {
        $fields = [];
        if (!empty($entry->fields)) {
            $decoded = is_string($entry->fields) ? json_decode($entry->fields, true) : (array) $entry->fields;
            if (is_array($decoded)) {
                foreach ($decoded as $id => $field) {
                    $fields[(string) $id] = is_array($field) ? ($field['value'] ?? '') : $field;
                }
            }
        }

        return [
            'id'      => (int) $entry->entry_id,
            'form_id' => (int) $entry->form_id,
            'status'  => $entry->status ?? 'active',
            'date'    => $entry->date ?? '',
            'ip'      => $entry->ip ?? '',
            'fields'  => $fields,
            'plugin'  => 'wpforms',
        ];
    }

    private function entry_to_text(array $entry): string {
        $lines = ["Form entry #{$entry['id']} (submitted: {$entry['date']}):"];
        foreach ($entry['fields'] as $key => $value) {
            $lines[] = "  Field {$key}: {$value}";
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    private function is_gf_active(): bool {
        return class_exists('GFAPI');
    }

    private function is_wpforms_active(): bool {
        return function_exists('wpforms') || class_exists('WPForms');
    }

    private function is_cf7_active(): bool {
        return class_exists('WPCF7') || function_exists('wpcf7');
    }
}
