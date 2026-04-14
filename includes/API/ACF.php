<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * Advanced Custom Fields (ACF) Integration
 *
 * Exposes ACF field groups and per-post field values for read and write
 * operations. Works with both free ACF and ACF Pro. Degrades gracefully
 * when ACF is not installed.
 */
class ACF extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/acf/field-groups', [
            ['methods' => 'GET', 'callback' => [$this, 'list_field_groups'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/acf/field-groups/(?P<key>[a-zA-Z0-9_-]+)', [
            ['methods' => 'GET', 'callback' => [$this, 'get_field_group'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/acf/fields/(?P<post_id>\d+)', [
            ['methods' => 'GET',       'callback' => [$this, 'get_fields'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_fields'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
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
                'key'      => $group['key'],
                'title'    => $group['title'],
                'active'   => (bool) ($group['active'] ?? true),
                'location' => $group['location'] ?? [],
            ],
            'fields' => array_map([$this, 'fmt_field'], $fields),
        ]);
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
    // Status
    // -------------------------------------------------------------------------

    public function status(\WP_REST_Request $r): \WP_REST_Response {
        $active  = $this->is_acf_active();
        $version = '';

        if ($active && defined('ACF_VERSION')) {
            $version = ACF_VERSION;
        }

        return $this->success([
            'active'  => $active,
            'version' => $version,
            'pro'     => defined('ACF_PRO'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function fmt_field(array $field): array {
        return [
            'key'           => $field['key'],
            'name'          => $field['name'],
            'label'         => $field['label'],
            'type'          => $field['type'],
            'required'      => (bool) ($field['required'] ?? false),
            'instructions'  => $field['instructions'] ?? '',
        ];
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    private function is_acf_active(): bool {
        return function_exists('get_fields') && function_exists('update_field') && function_exists('acf_get_field_groups');
    }
}
