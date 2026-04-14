<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * Comments API
 *
 * Advanced comment management with:
 *   – Filtered listing (by post, status, author, search, date range)
 *   – Pagination
 *   – AI-powered spam/ham classification (batch or per-comment)
 *   – Bulk status operations
 *   – Aggregate statistics
 *   – Individual comment creation
 */
class Comments extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/comments', [
            ['methods' => 'GET',  'callback' => [$this, 'list_all'], 'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'create'],   'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/comments/stats', [
            ['methods' => 'GET', 'callback' => [$this, 'stats'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/comments/bulk', [
            ['methods' => 'POST', 'callback' => [$this, 'bulk'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/comments/ai-moderate', [
            ['methods' => 'POST', 'callback' => [$this, 'ai_moderate'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/comments/(?P<id>\d+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get'],        'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete'],     'permission_callback' => [Auth::class, 'tier3']],
        ]);
        register_rest_route($this->namespace, '/comments/(?P<id>\d+)/status', [
            ['methods' => 'POST', 'callback' => [$this, 'set_status'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/comments/(?P<id>\d+)/reply', [
            ['methods' => 'POST', 'callback' => [$this, 'ai_reply'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
    }

    // -------------------------------------------------------------------------
    // List with advanced filtering
    // -------------------------------------------------------------------------

    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        $paging = $this->parse_pagination($r, 200);

        $args = [
            'number'  => $paging['per_page'],
            'offset'  => $paging['offset'],
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
            'count'   => false,
        ];

        if (!empty($r['post_id'])) {
            $args['post_id'] = (int) $r['post_id'];
        }

        $status = sanitize_key((string) ($r['status'] ?? 'all'));
        $args['status'] = in_array($status, ['approve', 'hold', 'spam', 'trash', 'all'], true) ? $status : 'all';

        if (!empty($r['search'])) {
            $args['search'] = sanitize_text_field((string) $r['search']);
        }

        if (!empty($r['author_email'])) {
            $args['author_email'] = sanitize_email((string) $r['author_email']);
        }

        if (!empty($r['date_after'])) {
            $args['date_query'] = array_merge($args['date_query'] ?? [], [['after' => sanitize_text_field((string) $r['date_after'])]]);
        }
        if (!empty($r['date_before'])) {
            $args['date_query'] = array_merge($args['date_query'] ?? [], [['before' => sanitize_text_field((string) $r['date_before'])]]);
        }

        $comments = get_comments($args);

        // Count query
        $args['count']  = true;
        unset($args['number'], $args['offset']);
        $total = (int) get_comments($args);

        $items = array_map([$this, 'format_comment'], is_array($comments) ? $comments : []);

        return $this->paginated($items, $total, $paging['page'], $paging['per_page']);
    }

    // -------------------------------------------------------------------------
    // Single comment
    // -------------------------------------------------------------------------

    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $comment = get_comment((int) $r['id']);
        if (!$comment) {
            return $this->error('Comment not found', 404);
        }
        return $this->success($this->format_comment($comment));
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $post_id = (int) ($d['post_id'] ?? 0);

        if (!$post_id || !get_post($post_id)) {
            return $this->error('post_id is required and must exist');
        }

        $data = [
            'comment_post_ID'    => $post_id,
            'comment_content'    => wp_kses_post((string) ($d['content'] ?? '')),
            'comment_author'     => sanitize_text_field((string) ($d['author']       ?? 'AGI Bot')),
            'comment_author_email' => sanitize_email((string) ($d['author_email']    ?? '')),
            'comment_author_url' => esc_url_raw((string) ($d['author_url']           ?? '')),
            'comment_approved'   => sanitize_key((string) ($d['status']              ?? '0')),
            'comment_parent'     => (int) ($d['parent'] ?? 0),
        ];

        if (empty($data['comment_content'])) {
            return $this->error('content is required');
        }

        $id = wp_insert_comment($data);
        if (!$id) {
            return $this->error('Failed to create comment', 500);
        }

        $this->log('create_comment', 'comment', (int) $id, ['post_id' => $post_id], 2);

        return $this->success(['id' => (int) $id], 201);
    }

    // -------------------------------------------------------------------------
    // Set status
    // -------------------------------------------------------------------------

    public function set_status(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $status = sanitize_key((string) ($r->get_json_params()['status'] ?? ''));

        $allowed = ['approve', 'hold', 'spam', 'trash'];
        if (!in_array($status, $allowed, true)) {
            return $this->error('status must be one of: ' . implode(', ', $allowed), 422);
        }

        $result = wp_set_comment_status($id, $status);
        if (!$result) {
            return $this->error('Failed to set comment status', 500);
        }

        $this->log('set_comment_status', 'comment', $id, ['status' => $status], 2);

        return $this->success(['updated' => true, 'id' => $id, 'status' => $status]);
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

        $valid_actions = ['approve', 'hold', 'spam', 'trash', 'delete'];
        if (!in_array($action, $valid_actions, true)) {
            return $this->error('action must be one of: ' . implode(', ', $valid_actions), 422);
        }

        $results = [];

        foreach ($ids as $id) {
            if ($action === 'delete') {
                $ok = (bool) wp_delete_comment($id, true);
            } else {
                $ok = (bool) wp_set_comment_status($id, $action);
            }
            $results[] = ['id' => $id, 'done' => $ok];
        }

        $this->log('bulk_comments', 'comment', 0, [
            'action' => $action,
            'count'  => count($ids),
        ], $action === 'delete' ? 3 : 2);

        return $this->success([
            'results' => $results,
            'total'   => count($results),
            'done'    => count(array_filter($results, fn($i) => $i['done'])),
        ]);
    }

    // -------------------------------------------------------------------------
    // AI spam / ham moderation
    // -------------------------------------------------------------------------

    /**
     * Send up to 50 pending comments to AI for spam/ham classification.
     * Optionally auto-apply the verdict.
     */
    public function ai_moderate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d    = (array) $r->get_json_params();
        $auto = !empty($d['auto_apply']);

        $comments = get_comments([
            'status' => 'hold',
            'number' => min((int) ($d['batch'] ?? 20), 50),
        ]);

        if (empty($comments)) {
            return $this->success(['message' => 'No pending comments to moderate', 'results' => []]);
        }

        $ai = new Router();

        $comment_list = implode("\n", array_map(function($c): string {
            $idx = $c->comment_ID;
            return "ID:{$idx}\nAUTHOR:{$c->comment_author}\nEMAIL:{$c->comment_author_email}\nCONTENT:{$c->comment_content}\n---";
        }, $comments));

        $response = $ai->complete(
            'You are a spam detection engine for a WordPress site. Analyse each comment and respond ONLY in JSON format: {"results":[{"id":123,"verdict":"spam|ham","confidence":0.95,"reason":"short explanation"},...]}',
            "Classify these comments:\n\n" . $comment_list,
            ['temperature' => 0.1, 'json_mode' => true, 'max_tokens' => 2000]
        );

        if (!empty($response['error'])) {
            return $this->error($response['error'], 502);
        }

        $parsed  = json_decode($response['content'], true);
        $raw_results = $parsed['results'] ?? [];
        $applied = [];

        foreach ($raw_results as $item) {
            $id      = (int) ($item['id']      ?? 0);
            $verdict = sanitize_key((string) ($item['verdict'] ?? 'ham'));
            $entry   = [
                'id'         => $id,
                'verdict'    => $verdict,
                'confidence' => (float) ($item['confidence'] ?? 0),
                'reason'     => sanitize_text_field((string) ($item['reason'] ?? '')),
                'applied'    => false,
            ];

            if ($auto && $id > 0) {
                if ($verdict === 'spam') {
                    wp_spam_comment($id);
                } elseif ($verdict === 'ham') {
                    wp_set_comment_status($id, 'approve');
                }
                $entry['applied'] = true;
            }

            $applied[] = $entry;
        }

        $this->log('ai_moderate_comments', 'comment', 0, [
            'count'       => count($applied),
            'auto_applied'=> $auto,
            'tokens'      => $response['tokens'] ?? 0,
        ], 2);

        return $this->success([
            'results'      => $applied,
            'auto_applied' => $auto,
            'tokens'       => $response['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // AI auto-reply
    // -------------------------------------------------------------------------

    public function ai_reply(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id      = (int) $r['id'];
        $comment = get_comment($id);

        if (!$comment) {
            return $this->error('Comment not found', 404);
        }

        $d    = (array) $r->get_json_params();
        $tone = sanitize_text_field((string) ($d['tone'] ?? 'professional and helpful'));
        $post = get_post((int) $comment->comment_post_ID);

        $ai       = new Router();
        $response = $ai->complete(
            "You are a community manager replying to a blog comment on behalf of the site owner. Tone: {$tone}. Be concise and genuine. Reply in plain text only.",
            "Post title: " . ($post ? get_the_title($post) : 'Unknown') . "\n\nComment from {$comment->comment_author}:\n{$comment->comment_content}",
            ['temperature' => 0.6, 'max_tokens' => 400]
        );

        if (!empty($response['error'])) {
            return $this->error($response['error'], 502);
        }

        $reply_text = sanitize_textarea_field($response['content']);
        $auto_post  = !empty($d['auto_post']);
        $reply_id   = null;

        if ($auto_post) {
            $reply_id = wp_insert_comment([
                'comment_post_ID'    => (int) $comment->comment_post_ID,
                'comment_content'    => $reply_text,
                'comment_parent'     => $id,
                'comment_approved'   => 1,
                'comment_author'     => get_option('blogname') . ' Team',
                'comment_author_email' => get_option('admin_email'),
            ]);
        }

        $this->log('ai_comment_reply', 'comment', $id, ['auto_post' => $auto_post, 'reply_id' => $reply_id], 2);

        return $this->success([
            'reply'     => $reply_text,
            'posted'    => $auto_post,
            'reply_id'  => $reply_id,
            'tokens'    => $response['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function delete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id = (int) $r['id'];
        if (!get_comment($id)) {
            return $this->error('Comment not found', 404);
        }

        $force = !empty($r->get_json_params()['force']);
        wp_delete_comment($id, $force);

        $this->log('delete_comment', 'comment', $id, ['force' => $force], 3);

        return $this->success(['deleted' => true, 'id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Statistics
    // -------------------------------------------------------------------------

    public function stats(\WP_REST_Request $r): \WP_REST_Response {
        $counts = wp_count_comments();

        return $this->success([
            'approved'  => (int) $counts->approved,
            'pending'   => (int) $counts->moderated,
            'spam'      => (int) $counts->spam,
            'trash'     => (int) $counts->trash,
            'total'     => (int) $counts->total_comments,
            'today'     => $this->count_today(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function format_comment(\WP_Comment $c): array {
        return [
            'id'           => (int) $c->comment_ID,
            'post_id'      => (int) $c->comment_post_ID,
            'post_title'   => get_the_title((int) $c->comment_post_ID),
            'parent'       => (int) $c->comment_parent,
            'author'       => $c->comment_author,
            'author_email' => $c->comment_author_email,
            'author_url'   => $c->comment_author_url,
            'author_ip'    => $c->comment_author_IP,
            'date'         => $c->comment_date_gmt,
            'content'      => $c->comment_content,
            'status'       => $c->comment_approved,
            'agent'        => $c->comment_agent,
        ];
    }

    private function count_today(): int {
        global $wpdb;
        $today = gmdate('Y-m-d');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE DATE(comment_date_gmt) = %s",
            $today
        ));
    }
}
