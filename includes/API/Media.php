<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * Media Library API
 *
 * Enterprise media management with:
 *   – Paginated, filterable media listing (type, date range, search)
 *   – File upload and remote sideload
 *   – Metadata update (title, caption, description, alt)
 *   – Thumbnail regeneration
 *   – AI alt-text generation (single and bulk)
 *   – Bulk operations (delete, regenerate)
 *   – Duplicate detection by file hash
 *   – Media statistics
 */
class Media extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/media', [
            ['methods' => 'GET',  'callback' => [$this, 'list_all'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'upload'],   'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/media/sideload', [
            ['methods' => 'POST', 'callback' => [$this, 'sideload'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/media/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/media/bulk', [
            ['methods' => 'POST', 'callback' => [$this, 'bulk'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/media/ai-alt-text', [
            ['methods' => 'POST', 'callback' => [$this, 'ai_alt_text'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/media/ai-alt-text/bulk', [
            ['methods' => 'POST', 'callback' => [$this, 'ai_alt_text_bulk'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/media/duplicates', [
            ['methods' => 'GET', 'callback' => [$this, 'duplicates'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/media/(?P<id>\d+)', [
            ['methods' => 'GET',         'callback' => [$this, 'get'],        'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH',   'callback' => [$this, 'update'],     'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',      'callback' => [$this, 'delete'],     'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/media/(?P<id>\d+)/regenerate', [
            ['methods' => 'POST', 'callback' => [$this, 'regenerate'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        $paging = $this->parse_pagination($r, 200);

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $paging['per_page'],
            'offset'         => $paging['offset'],
            'orderby'        => sanitize_key((string) ($r['orderby'] ?? 'date')),
            'order'          => strtoupper((string) ($r['order']   ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];

        if (!empty($r['search'])) {
            $args['s'] = sanitize_text_field((string) $r['search']);
        }

        if (!empty($r['mime_type'])) {
            $args['post_mime_type'] = sanitize_text_field((string) $r['mime_type']);
        }

        if (!empty($r['date_after'])) {
            $args['date_query'][] = ['after' => sanitize_text_field((string) $r['date_after'])];
        }

        if (!empty($r['date_before'])) {
            $args['date_query'][] = ['before' => sanitize_text_field((string) $r['date_before'])];
        }

        // Size filters (requires joining wp_postmeta)
        $min_bytes = (int) ($r['min_bytes'] ?? 0);
        $max_bytes = (int) ($r['max_bytes'] ?? 0);

        $query   = new \WP_Query($args);
        $items   = array_map([$this, 'format_attachment'], $query->posts);

        if ($min_bytes > 0) {
            $items = array_values(array_filter($items, fn($i) => ($i['filesize'] ?? 0) >= $min_bytes));
        }
        if ($max_bytes > 0) {
            $items = array_values(array_filter($items, fn($i) => ($i['filesize'] ?? 0) <= $max_bytes));
        }

        // Count total without limit
        $count_args              = $args;
        $count_args['posts_per_page'] = -1;
        unset($count_args['offset']);
        $count_args['fields']    = 'ids';
        $total = count((new \WP_Query($count_args))->posts);

        return $this->paginated($items, $total, $paging['page'], $paging['per_page']);
    }

    // -------------------------------------------------------------------------
    // Single attachment
    // -------------------------------------------------------------------------

    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $post = get_post((int) $r['id']);
        if (!$post || $post->post_type !== 'attachment') {
            return $this->error('Attachment not found', 404);
        }
        return $this->success($this->format_attachment($post));
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------

    public function upload(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $files = $r->get_file_params();
        if (empty($files['file'])) {
            return $this->error('No file provided');
        }

        $this->require_media_includes();
        $id = media_handle_upload('file', (int) ($r->get_body_params()['post_id'] ?? 0));

        if (is_wp_error($id)) {
            return $this->error($id->get_error_message(), 500);
        }

        $this->log('upload_media', 'media', $id, ['name' => $files['file']['name'] ?? ''], 2);

        return $this->success($this->format_attachment(get_post($id)), 201);
    }

    // -------------------------------------------------------------------------
    // Sideload from remote URL
    // -------------------------------------------------------------------------

    public function sideload(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d = (array) $r->get_json_params();

        if (empty($d['url'])) {
            return $this->error('url is required');
        }

        $this->require_media_includes();

        $url = esc_url_raw((string) $d['url']);
        $tmp = download_url($url, 60);

        if (is_wp_error($tmp)) {
            return $this->error($tmp->get_error_message(), 502);
        }

        $filename = sanitize_file_name(
            (string) ($d['filename'] ?? basename((string) parse_url($url, PHP_URL_PATH)))
        );

        $file = ['name' => $filename, 'tmp_name' => $tmp];
        $id   = media_handle_sideload($file, (int) ($d['post_id'] ?? 0));

        if (is_wp_error($id)) {
            @unlink($tmp);
            return $this->error($id->get_error_message(), 500);
        }

        if (!empty($d['alt'])) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $d['alt']));
        }

        $this->log('sideload_media', 'media', $id, ['url' => $url], 2);

        return $this->success($this->format_attachment(get_post($id)), 201);
    }

    // -------------------------------------------------------------------------
    // Update metadata
    // -------------------------------------------------------------------------

    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post || $post->post_type !== 'attachment') {
            return $this->error('Attachment not found', 404);
        }

        $d      = (array) $r->get_json_params();
        $update = ['ID' => $id];

        if (isset($d['title']))       { $update['post_title']   = sanitize_text_field((string) $d['title']); }
        if (isset($d['caption']))     { $update['post_excerpt'] = sanitize_textarea_field((string) $d['caption']); }
        if (isset($d['description'])) { $update['post_content'] = wp_kses_post((string) $d['description']); }

        if (count($update) > 1) {
            $res = wp_update_post($update, true);
            if (is_wp_error($res)) {
                return $this->error($res->get_error_message(), 500);
            }
        }

        if (array_key_exists('alt', $d)) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $d['alt']));
        }

        $this->log('update_media', 'media', $id, ['fields' => array_keys($d)], 2);

        return $this->success($this->format_attachment(get_post($id)));
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function delete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post || $post->post_type !== 'attachment') {
            return $this->error('Attachment not found', 404);
        }

        wp_delete_attachment($id, true);

        $this->log('delete_media', 'media', $id, [], 3);

        return $this->success(['deleted' => true, 'id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Regenerate thumbnails
    // -------------------------------------------------------------------------

    public function regenerate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id = (int) $r['id'];

        if (get_post_type($id) !== 'attachment') {
            return $this->error('Attachment not found', 404);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file = get_attached_file($id);
        if (!$file || !file_exists($file)) {
            return $this->error('Attachment file missing from disk', 404);
        }

        $meta = wp_generate_attachment_metadata($id, $file);

        if (empty($meta)) {
            return $this->error('Failed to regenerate metadata', 500);
        }

        wp_update_attachment_metadata($id, $meta);

        $this->log('regenerate_thumbnails', 'media', $id, [], 2);

        return $this->success(['regenerated' => true, 'id' => $id, 'sizes' => array_keys($meta['sizes'] ?? [])]);
    }

    // -------------------------------------------------------------------------
    // Bulk operations
    // -------------------------------------------------------------------------

    public function bulk(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $ids    = array_map('absint', (array) ($d['ids']    ?? []));
        $action = sanitize_key((string) ($d['action'] ?? ''));

        if (empty($ids)) {
            return $this->error('ids is required');
        }

        $valid = ['delete', 'regenerate'];
        if (!in_array($action, $valid, true)) {
            return $this->error('action must be one of: ' . implode(', ', $valid), 422);
        }

        $ids     = array_slice($ids, 0, 100);
        $results = [];

        foreach ($ids as $id) {
            try {
                if ($action === 'delete') {
                    wp_delete_attachment($id, true);
                    $results[] = ['id' => $id, 'done' => true];
                } elseif ($action === 'regenerate') {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    $file = get_attached_file($id);
                    if ($file && file_exists($file)) {
                        $meta = wp_generate_attachment_metadata($id, $file);
                        wp_update_attachment_metadata($id, $meta);
                        $results[] = ['id' => $id, 'done' => true];
                    } else {
                        $results[] = ['id' => $id, 'done' => false, 'error' => 'File missing'];
                    }
                }
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'done' => false, 'error' => $e->getMessage()];
            }
        }

        $this->log('bulk_media', 'media', 0, [
            'action' => $action,
            'count'  => count($ids),
        ], $action === 'delete' ? 3 : 2);

        return $this->success([
            'results' => $results,
            'done'    => count(array_filter($results, fn($i) => $i['done'])),
            'failed'  => count(array_filter($results, fn($i) => !$i['done'])),
        ]);
    }

    // -------------------------------------------------------------------------
    // AI alt-text – single
    // -------------------------------------------------------------------------

    public function ai_alt_text(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d  = (array) $r->get_json_params();
        $id = (int) ($d['id'] ?? 0);

        if (!$id) {
            return $this->error('id is required');
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return $this->error('Attachment not found', 404);
        }

        $url = wp_get_attachment_url($id);
        if (!$url) {
            return $this->error('Cannot determine attachment URL', 500);
        }

        $context = array_filter([
            'title'       => $post->post_title,
            'caption'     => $post->post_excerpt,
            'description' => wp_strip_all_tags($post->post_content),
            'filename'    => basename((string) get_attached_file($id)),
        ]);

        $ai = new Router();

        $result = $ai->complete(
            'You are an accessibility specialist. Write concise, descriptive alt-text for images. Maximum 125 characters. Plain text only, no quotes.',
            "Write alt-text for an image with these details:\n" . implode("\n", array_map(
                fn($k, $v) => ucfirst($k) . ': ' . $v,
                array_keys($context),
                $context
            )) . "\nURL: {$url}",
            ['temperature' => 0.3, 'max_tokens' => 200]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $alt_text = sanitize_text_field(mb_substr($result['content'], 0, 125));

        if (!empty($d['auto_apply'])) {
            update_post_meta($id, '_wp_attachment_image_alt', $alt_text);
        }

        $this->log('ai_alt_text', 'media', $id, ['applied' => !empty($d['auto_apply'])], 2);

        return $this->success([
            'id'         => $id,
            'alt_text'   => $alt_text,
            'applied'    => !empty($d['auto_apply']),
            'tokens'     => $result['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // AI alt-text – bulk (images missing alt)
    // -------------------------------------------------------------------------

    public function ai_alt_text_bulk(\WP_REST_Request $r): \WP_REST_Response {
        $d          = (array) $r->get_json_params();
        $batch_size = min((int) ($d['batch'] ?? 20), 50);
        $auto_apply = !empty($d['auto_apply']);

        // Find images without alt-text
        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $batch_size,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
                ['key' => '_wp_attachment_image_alt', 'value'   => '', 'compare' => '='],
            ],
        ]);

        if (empty($query->posts)) {
            return $this->success(['message' => 'All images have alt-text', 'results' => []]);
        }

        $ai      = new Router();
        $results = [];

        foreach ($query->posts as $post) {
            $url = wp_get_attachment_url($post->ID);
            if (!$url) {
                continue;
            }

            $context = trim(implode(' | ', array_filter([$post->post_title, $post->post_excerpt, basename((string) get_attached_file($post->ID))])));

            $result = $ai->complete(
                'Write concise, descriptive alt-text (max 125 chars). Plain text only, no quotes.',
                "Image details: {$context}\nURL: {$url}",
                ['temperature' => 0.3, 'max_tokens' => 150]
            );

            $alt_text = '';
            $error    = null;

            if (!empty($result['error'])) {
                $error = $result['error'];
            } else {
                $alt_text = sanitize_text_field(mb_substr($result['content'], 0, 125));
                if ($auto_apply) {
                    update_post_meta($post->ID, '_wp_attachment_image_alt', $alt_text);
                }
            }

            $results[] = [
                'id'       => $post->ID,
                'alt_text' => $alt_text,
                'applied'  => $auto_apply && !$error,
                'tokens'   => $result['tokens'] ?? 0,
                'error'    => $error,
            ];
        }

        $this->log('ai_alt_text_bulk', 'media', 0, [
            'count'       => count($results),
            'auto_applied'=> $auto_apply,
        ], 2);

        return $this->success([
            'results'      => $results,
            'total'        => count($results),
            'auto_applied' => $auto_apply,
        ]);
    }

    // -------------------------------------------------------------------------
    // Duplicate detection
    // -------------------------------------------------------------------------

    public function duplicates(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        // Group attachments by their MD5 file hash stored in _wp_file_hash meta
        // or computed on-the-fly for a batch
        $limit = min((int) ($r['limit'] ?? 500), 2000);

        $files = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS file_path
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];

        $hashes     = [];
        $upload_dir = wp_upload_dir();
        $base       = trailingslashit((string) $upload_dir['basedir']);

        foreach ($files as $row) {
            $full = $base . $row['file_path'];
            if (!is_file($full)) {
                continue;
            }
            $hash = md5_file($full);
            if ($hash === false) {
                continue;
            }
            $hashes[$hash][] = ['id' => (int) $row['ID'], 'title' => $row['post_title'], 'path' => $row['file_path']];
        }

        $duplicates = array_values(array_filter($hashes, fn($group) => count($group) > 1));

        return $this->success([
            'duplicate_groups' => $duplicates,
            'group_count'      => count($duplicates),
            'total_duplicates' => array_sum(array_map(fn($g) => count($g) - 1, $duplicates)),
        ]);
    }

    // -------------------------------------------------------------------------
    // Statistics
    // -------------------------------------------------------------------------

    public function stats(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;

        $types = $wpdb->get_results(
            "SELECT post_mime_type, COUNT(*) AS count FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' GROUP BY post_mime_type ORDER BY count DESC",
            ARRAY_A
        ) ?: [];

        $no_alt = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE 'image/%'
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt' AND pm.meta_value != '')"
        );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
        );

        return $this->success([
            'total'           => $total,
            'by_type'         => $types,
            'images_no_alt'   => $no_alt,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function format_attachment(\WP_Post $post): array {
        $meta = wp_get_attachment_metadata($post->ID) ?: [];

        return [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'caption'     => $post->post_excerpt,
            'description' => $post->post_content,
            'alt'         => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
            'url'         => wp_get_attachment_url($post->ID),
            'mime_type'   => $post->post_mime_type,
            'date'        => $post->post_date_gmt,
            'filesize'    => (int) ($meta['filesize'] ?? @filesize((string) get_attached_file($post->ID))),
            'width'       => (int) ($meta['width']    ?? 0),
            'height'      => (int) ($meta['height']   ?? 0),
            'sizes'       => array_keys($meta['sizes'] ?? []),
        ];
    }

    private function require_media_includes(): void {
        foreach (['file', 'image', 'media'] as $inc) {
            require_once ABSPATH . "wp-admin/includes/{$inc}.php";
        }
    }
}
