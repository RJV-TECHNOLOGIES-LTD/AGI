<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * Forms Integration
 *
 * Provides unified read access to form submissions from Contact Form 7,
 * WPForms, and Gravity Forms, plus AI-powered auto-reply generation.
 * Auto-detects which form plugin(s) are active at runtime.
 */
class Forms extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/forms', [
            ['methods' => 'GET', 'callback' => [$this, 'list_forms'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/forms/entries', [
            ['methods' => 'GET', 'callback' => [$this, 'list_entries'], 'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['form_id' => ['default' => 0], 'per_page' => ['default' => 20], 'page' => ['default' => 1], 'plugin' => ['default' => '']]],
        ]);
        register_rest_route($this->namespace, '/forms/entries/(?P<id>\d+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get_entry'],    'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['plugin' => ['default' => '']]],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_entry'], 'permission_callback' => [Auth::class, 'tier3'],
             'args' => ['plugin' => ['default' => '']]],
        ]);
        register_rest_route($this->namespace, '/forms/entries/(?P<id>\d+)/generate-reply', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_reply'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
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

    // -------------------------------------------------------------------------
    // Active plugins discovery
    // -------------------------------------------------------------------------

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
