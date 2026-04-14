<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Advanced Custom Fields (ACF) Integration
 *
 * Full field-group and field administration: list, get, create, update, delete
 * field groups; create individual fields; read/write per-post field values;
 * read/write ACF options-page values; export/import field groups as JSON.
 * Works with both free ACF and ACF Pro. Degrades gracefully when ACF is absent.
 */
class ACF extends Base {

    public function register_routes(): void {
        // Field groups
        register_rest_route($this->namespace, '/acf/field-groups', [
            ['methods' => 'GET',  'callback' => [$this, 'list_field_groups'],  'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_field_group'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/acf/field-groups/(?P<key>[a-zA-Z0-9_-]+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_field_group'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_field_group'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_field_group'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/acf/field-groups/(?P<key>[a-zA-Z0-9_-]+)/duplicate', [
            ['methods' => 'POST', 'callback' => [$this, 'duplicate_field_group'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Fields (within a group)
        register_rest_route($this->namespace, '/acf/field-groups/(?P<key>[a-zA-Z0-9_-]+)/fields', [
            ['methods' => 'GET',  'callback' => [$this, 'list_group_fields'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create_field'],      'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/acf/fields/(?P<field_key>[a-zA-Z0-9_-]+)', [
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_field'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_field'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);

        // Per-post field values
        register_rest_route($this->namespace, '/acf/fields/(?P<post_id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_fields'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_fields'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Options-page values
        register_rest_route($this->namespace, '/acf/options', [
            ['methods' => 'GET',       'callback' => [$this, 'get_options'],    'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['page' => ['default' => 'options']]],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_options'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Export/Import
        register_rest_route($this->namespace, '/acf/export', [
            ['methods' => 'POST', 'callback' => [$this, 'export_field_groups'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/acf/import', [
            ['methods' => 'POST', 'callback' => [$this, 'import_field_groups'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Status
        register_rest_route($this->namespace, '/acf/status', [
            ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Field groups
    // -------------------------------------------------------------------------

    public function list_field_groups(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $groups = acf_get_field_groups();

        $formatted = array_map(function (array $group): array {
            return [
                'key'      => $group['key'],
                'title'    => $group['title'],
                'active'   => (bool) ($group['active'] ?? true),
                'location' => $group['location'] ?? [],
                'fields'   => count(acf_get_fields($group['key']) ?: []),
                'style'    => $group['style'] ?? 'default',
                'position' => $group['position'] ?? 'normal',
            ];
        }, $groups);

        $this->log('acf_list_groups', 'acf', 0, ['count' => count($formatted)]);

        return $this->success(['field_groups' => $formatted]);
    }

    public function get_field_group(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $key   = sanitize_key((string) $r['key']);
        $group = acf_get_field_group($key);

        if (!$group) {
            return $this->error('Field group not found', 404);
        }

        $fields = acf_get_fields($key) ?: [];

        return $this->success([
            'group'  => [
                'key'            => $group['key'],
                'title'          => $group['title'],
                'active'         => (bool) ($group['active'] ?? true),
                'location'       => $group['location'] ?? [],
                'style'          => $group['style'] ?? 'default',
                'position'       => $group['position'] ?? 'normal',
                'label_placement'=> $group['label_placement'] ?? 'top',
                'menu_order'     => (int) ($group['menu_order'] ?? 0),
                'description'    => $group['description'] ?? '',
            ],
            'fields' => array_map([$this, 'fmt_field'], $fields),
        ]);
    }

    public function create_field_group(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $d     = (array) $r->get_json_params();
        $title = sanitize_text_field((string) ($d['title'] ?? ''));
        if (empty($title)) {
            return $this->error('title is required');
        }

        $group = [
            'key'             => 'group_' . uniqid(),
            'title'           => $title,
            'fields'          => [],
            'location'        => $d['location'] ?? [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'active'          => (bool) ($d['active'] ?? true),
            'style'           => sanitize_key((string) ($d['style'] ?? 'default')),
            'position'        => sanitize_key((string) ($d['position'] ?? 'normal')),
            'label_placement' => sanitize_key((string) ($d['label_placement'] ?? 'top')),
            'instruction_placement' => sanitize_key((string) ($d['instruction_placement'] ?? 'label')),
            'menu_order'      => (int) ($d['menu_order'] ?? 0),
            'description'     => sanitize_textarea_field((string) ($d['description'] ?? '')),
        ];

        acf_add_local_field_group($group);
        $saved = acf_update_field_group($group);

        if (!$saved) {
            return $this->error('Failed to save field group', 500);
        }

        $this->log('acf_create_field_group', 'acf', 0, ['key' => $group['key'], 'title' => $title], 2);
        return $this->success(['key' => $group['key'], 'title' => $title], 201);
    }

    public function update_field_group(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $key   = sanitize_key((string) $r['key']);
        $group = acf_get_field_group($key);
        if (!$group) {
            return $this->error('Field group not found', 404);
        }

        $d = (array) $r->get_json_params();

        if (isset($d['title']))          $group['title']           = sanitize_text_field($d['title']);
        if (isset($d['active']))         $group['active']          = (bool) $d['active'];
        if (isset($d['location']))       $group['location']        = (array) $d['location'];
        if (isset($d['style']))          $group['style']           = sanitize_key($d['style']);
        if (isset($d['position']))       $group['position']        = sanitize_key($d['position']);
        if (isset($d['label_placement'])) $group['label_placement'] = sanitize_key($d['label_placement']);
        if (isset($d['menu_order']))     $group['menu_order']      = (int) $d['menu_order'];
        if (isset($d['description']))    $group['description']     = sanitize_textarea_field($d['description']);

        acf_update_field_group($group);
        $this->log('acf_update_field_group', 'acf', 0, ['key' => $key], 2);

        return $this->success(['key' => $key, 'updated' => true]);
    }

    public function delete_field_group(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $key   = sanitize_key((string) $r['key']);
        $group = acf_get_field_group($key);
        if (!$group) {
            return $this->error('Field group not found', 404);
        }

        // The post ID is embedded in the group array as 'ID'
        $post_id = $group['ID'] ?? 0;
        if ($post_id) {
            wp_delete_post($post_id, true);
        }
        acf_delete_field_group($key);

        $this->log('acf_delete_field_group', 'acf', $post_id, ['key' => $key], 3);
        return $this->success(['deleted' => true, 'key' => $key]);
    }

    public function duplicate_field_group(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $key   = sanitize_key((string) $r['key']);
        $group = acf_get_field_group($key);
        if (!$group) {
            return $this->error('Field group not found', 404);
        }

        $d           = (array) $r->get_json_params();
        $new_title   = sanitize_text_field((string) ($d['title'] ?? $group['title'] . ' (Copy)'));
        $new_key     = 'group_' . uniqid();

        $new_group           = $group;
        $new_group['key']    = $new_key;
        $new_group['title']  = $new_title;
        unset($new_group['ID']);

        // Re-key all fields
        $old_fields = acf_get_fields($key) ?: [];
        $new_fields = [];
        foreach ($old_fields as $field) {
            $field['key']    = 'field_' . uniqid();
            $field['parent'] = $new_key;
            $new_fields[]    = $field;
        }
        $new_group['fields'] = $new_fields;

        acf_update_field_group($new_group);

        $this->log('acf_duplicate_field_group', 'acf', 0, ['source' => $key, 'new_key' => $new_key], 2);
        return $this->success(['key' => $new_key, 'title' => $new_title], 201);
    }

    // -------------------------------------------------------------------------
    // Fields within a group
    // -------------------------------------------------------------------------

    public function list_group_fields(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $key    = sanitize_key((string) $r['key']);
        $group  = acf_get_field_group($key);
        if (!$group) {
            return $this->error('Field group not found', 404);
        }

        $fields = acf_get_fields($key) ?: [];
        return $this->success([
            'group_key' => $key,
            'fields'    => array_map([$this, 'fmt_field'], $fields),
        ]);
    }

    public function create_field(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $group_key = sanitize_key((string) $r['key']);
        $group     = acf_get_field_group($group_key);
        if (!$group) {
            return $this->error('Field group not found', 404);
        }

        $d    = (array) $r->get_json_params();
        $name = sanitize_key((string) ($d['name'] ?? ''));
        $type = sanitize_key((string) ($d['type'] ?? 'text'));

        if (empty($name)) {
            return $this->error('name is required');
        }

        $field = [
            'key'          => 'field_' . uniqid(),
            'label'        => sanitize_text_field((string) ($d['label'] ?? ucfirst(str_replace('_', ' ', $name)))),
            'name'         => $name,
            'type'         => $type,
            'parent'       => $group_key,
            'required'     => (bool) ($d['required'] ?? false),
            'instructions' => sanitize_textarea_field((string) ($d['instructions'] ?? '')),
            'default_value'=> $d['default_value'] ?? '',
            'wrapper'      => $d['wrapper'] ?? [],
        ];

        // Type-specific settings
        if ($type === 'select' || $type === 'checkbox' || $type === 'radio') {
            $field['choices'] = $d['choices'] ?? [];
        }
        if ($type === 'relationship' || $type === 'post_object') {
            $field['post_type'] = $d['post_type'] ?? [];
            $field['filters']   = $d['filters'] ?? ['search'];
        }
        if ($type === 'image' || $type === 'file') {
            $field['return_format'] = $d['return_format'] ?? 'array';
            $field['library']       = $d['library'] ?? 'all';
        }
        if ($type === 'repeater' || $type === 'flexible_content') {
            $field['sub_fields'] = $d['sub_fields'] ?? [];
        }

        acf_add_local_field($field);
        $field_id = acf_update_field($field);

        $this->log('acf_create_field', 'acf', 0, ['key' => $field['key'], 'name' => $name, 'type' => $type, 'group' => $group_key], 2);
        return $this->success(['key' => $field['key'], 'name' => $name, 'type' => $type], 201);
    }

    public function update_field(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $field_key = sanitize_key((string) $r['field_key']);
        $field     = acf_get_field($field_key);
        if (!$field) {
            return $this->error('Field not found', 404);
        }

        $d = (array) $r->get_json_params();
        if (isset($d['label']))         $field['label']         = sanitize_text_field($d['label']);
        if (isset($d['instructions']))  $field['instructions']  = sanitize_textarea_field($d['instructions']);
        if (isset($d['required']))      $field['required']      = (bool) $d['required'];
        if (isset($d['default_value'])) $field['default_value'] = $d['default_value'];
        if (isset($d['choices']))       $field['choices']       = (array) $d['choices'];
        if (isset($d['wrapper']))       $field['wrapper']       = (array) $d['wrapper'];
        if (isset($d['return_format'])) $field['return_format'] = sanitize_key($d['return_format']);

        acf_update_field($field);
        $this->log('acf_update_field', 'acf', 0, ['key' => $field_key], 2);
        return $this->success(['key' => $field_key, 'updated' => true]);
    }

    public function delete_field(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $field_key = sanitize_key((string) $r['field_key']);
        $field     = acf_get_field($field_key);
        if (!$field) {
            return $this->error('Field not found', 404);
        }

        acf_delete_field($field_key);
        $this->log('acf_delete_field', 'acf', 0, ['key' => $field_key], 3);
        return $this->success(['deleted' => true, 'key' => $field_key]);
    }

    // -------------------------------------------------------------------------
    // Per-post field values
    // -------------------------------------------------------------------------

    public function get_fields(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $post_id = (int) $r['post_id'];

        if (!get_post($post_id)) {
            return $this->error('Post not found', 404);
        }

        $fields = get_fields($post_id);

        $this->log('acf_get_fields', 'acf', $post_id, []);

        return $this->success([
            'post_id' => $post_id,
            'fields'  => $fields ?: [],
        ]);
    }

    public function update_fields(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $post_id = (int) $r['post_id'];

        if (!get_post($post_id)) {
            return $this->error('Post not found', 404);
        }

        $d = (array) $r->get_json_params();

        if (empty($d['fields']) || !is_array($d['fields'])) {
            return $this->error('fields object is required');
        }

        $updated = [];
        foreach ($d['fields'] as $field_key => $value) {
            $field_key = sanitize_key((string) $field_key);
            if ($field_key === '') {
                continue;
            }

            update_field($field_key, $value, $post_id);
            $updated[] = $field_key;
        }

        $this->log('acf_update_fields', 'acf', $post_id, ['keys' => $updated], 2);

        return $this->success([
            'post_id' => $post_id,
            'updated' => $updated,
            'fields'  => get_fields($post_id) ?: [],
        ]);
    }

    // -------------------------------------------------------------------------
    // Options-page values
    // -------------------------------------------------------------------------

    public function get_options(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $page_slug = sanitize_key((string) ($r['page'] ?? 'options'));
        $fields    = get_fields($page_slug);

        return $this->success([
            'page'   => $page_slug,
            'fields' => $fields ?: [],
        ]);
    }

    public function update_options(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $d         = (array) $r->get_json_params();
        $page_slug = sanitize_key((string) ($d['page'] ?? 'options'));
        $fields    = $d['fields'] ?? [];

        if (!is_array($fields) || empty($fields)) {
            return $this->error('fields object is required');
        }

        $updated = [];
        foreach ($fields as $field_key => $value) {
            $field_key = sanitize_key((string) $field_key);
            if ($field_key === '') continue;

            update_field($field_key, $value, $page_slug);
            $updated[] = $field_key;
        }

        $this->log('acf_update_options', 'acf', 0, ['page' => $page_slug, 'keys' => $updated], 2);
        return $this->success(['page' => $page_slug, 'updated' => $updated]);
    }

    // -------------------------------------------------------------------------
    // Export / Import
    // -------------------------------------------------------------------------

    public function export_field_groups(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $d    = (array) $r->get_json_params();
        $keys = !empty($d['keys']) ? (array) $d['keys'] : null;

        $all_groups = acf_get_field_groups();
        $export     = [];

        foreach ($all_groups as $group) {
            if ($keys !== null && !in_array($group['key'], $keys, true)) {
                continue;
            }
            $group['fields'] = acf_get_fields($group['key']) ?: [];
            $export[]        = $group;
        }

        $this->log('acf_export', 'acf', 0, ['groups' => count($export)], 2);
        return $this->success(['field_groups' => $export, 'count' => count($export)]);
    }

    public function import_field_groups(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (!$this->is_acf_active()) {
            return $this->error('Advanced Custom Fields is not active', 503);
        }

        $d      = (array) $r->get_json_params();
        $groups = $d['field_groups'] ?? [];
        if (empty($groups) || !is_array($groups)) {
            return $this->error('field_groups array is required');
        }

        $imported = 0;
        $errors   = [];

        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['key'])) {
                $errors[] = 'Skipped invalid group';
                continue;
            }
            try {
                acf_update_field_group($group);
                if (!empty($group['fields']) && is_array($group['fields'])) {
                    foreach ($group['fields'] as $field) {
                        acf_update_field($field);
                    }
                }
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $this->log('acf_import', 'acf', 0, ['imported' => $imported, 'errors' => count($errors)], 2);
        return $this->success(['imported' => $imported, 'errors' => $errors]);
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    public function status(\WP_REST_Request $r): \WP_REST_Response {
        $active  = $this->is_acf_active();
        $version = '';

        if ($active && defined('ACF_VERSION')) {
            $version = ACF_VERSION;
        }

        return $this->success([
            'active'       => $active,
            'version'      => $version,
            'pro'          => defined('ACF_PRO'),
            'options_page' => defined('ACF_PRO') && class_exists('acf_plugin_options_page'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function fmt_field(array $field): array {
        $base = [
            'key'           => $field['key'],
            'name'          => $field['name'],
            'label'         => $field['label'],
            'type'          => $field['type'],
            'required'      => (bool) ($field['required'] ?? false),
            'instructions'  => $field['instructions'] ?? '',
            'default_value' => $field['default_value'] ?? '',
        ];

        // Include sub_fields for repeater / flexible_content
        if (!empty($field['sub_fields'])) {
            $base['sub_fields'] = array_map([$this, 'fmt_field'], (array) $field['sub_fields']);
        }

        // Choices for select / radio / checkbox
        if (!empty($field['choices'])) {
            $base['choices'] = $field['choices'];
        }

        return $base;
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    private function is_acf_active(): bool {
        return function_exists('get_fields') && function_exists('update_field') && function_exists('acf_get_field_groups');
    }
}
