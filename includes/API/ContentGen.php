<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * AI Content Generation API
 *
 * End-to-end content creation workflows:
 *   – Free-form completion (system + user prompt)
 *   – Structured blog post generation (outline → full article)
 *   – Bulk post generation from topic list
 *   – SEO metadata generation with multi-plugin write-back
 *   – Content rewriting (style, tone, length control)
 *   – Translation into target language
 *   – FAQ / Q&A extraction from existing content
 *   – Content quality scoring and readability analysis
 *   – Excerpt generation
 *   – AI provider status endpoint
 */
class ContentGen extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/ai/status', [
            ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/ai/complete', [
            ['methods' => 'POST', 'callback' => [$this, 'complete'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/generate-post', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_post'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/generate-outline', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_outline'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/generate-bulk', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_bulk'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/generate-seo', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_seo'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/rewrite', [
            ['methods' => 'POST', 'callback' => [$this, 'rewrite'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/translate', [
            ['methods' => 'POST', 'callback' => [$this, 'translate'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/generate-faq', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_faq'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/generate-excerpt', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_excerpt'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/ai/quality-score', [
            ['methods' => 'POST', 'callback' => [$this, 'quality_score'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    public function status(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success((new Router())->status());
    }

    // -------------------------------------------------------------------------
    // Free-form completion
    // -------------------------------------------------------------------------

    public function complete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d = (array) $r->get_json_params();

        if (empty($d['message'])) {
            return $this->error('message is required');
        }

        $ai     = new Router();
        $result = $ai->complete(
            sanitize_textarea_field((string) ($d['system_prompt'] ?? 'You are an expert assistant for RJV Technologies Ltd.')),
            (string) $d['message'],
            [
                'provider'    => sanitize_key((string) ($d['provider']    ?? '')),
                'model'       => sanitize_text_field((string) ($d['model']        ?? '')),
                'temperature' => (float) ($d['temperature'] ?? 0.3),
                'max_tokens'  => (int)   ($d['max_tokens']  ?? 4096),
            ]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        return $this->with_ai_meta($this->success($result), $result);
    }

    // -------------------------------------------------------------------------
    // Blog post generation (outline → full article)
    // -------------------------------------------------------------------------

    public function generate_post(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d     = (array) $r->get_json_params();
        $topic = sanitize_text_field((string) ($d['topic'] ?? ''));

        if ($topic === '') {
            return $this->error('topic is required');
        }

        $ai        = new Router();
        $provider  = sanitize_key((string) ($d['provider'] ?? ''));
        $tone      = sanitize_text_field((string) ($d['tone']     ?? 'professional'));
        $length    = sanitize_text_field((string) ($d['length']   ?? '1500 words'));
        $language  = sanitize_text_field((string) ($d['language'] ?? 'British English'));
        $keywords  = array_map('sanitize_text_field', (array) ($d['keywords'] ?? []));
        $sections  = sanitize_text_field((string) ($d['sections'] ?? ''));

        $keyword_instruction = !empty($keywords)
            ? "\nNaturally incorporate these keywords: " . implode(', ', $keywords)
            : '';
        $section_instruction = $sections !== ''
            ? "\nInclude these sections: {$sections}"
            : '';

        $result = $ai->complete(
            "You are an expert content writer. Write in {$language}. Output clean, semantic HTML (h2, h3, p, ul, ol, strong, em). No markdown fences or backticks.",
            "Write a {$length} blog post on: {$topic}\nTone: {$tone}{$keyword_instruction}{$section_instruction}\n\nInclude a compelling introduction, well-structured body with subheadings, and a strong conclusion.",
            ['provider' => $provider, 'max_tokens' => (int) ($d['max_tokens'] ?? 8192), 'temperature' => 0.7]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $post_id = null;

        if (!empty($d['auto_create'])) {
            $post_id = wp_insert_post([
                'post_title'   => sanitize_text_field((string) ($d['title'] ?? $topic)),
                'post_content' => wp_kses_post($result['content']),
                'post_status'  => sanitize_key((string) ($d['status'] ?? 'draft')),
                'post_type'    => sanitize_key((string) ($d['post_type'] ?? 'post')),
                'post_author'  => (int) ($d['author_id'] ?? get_current_user_id()),
            ], true);

            if (is_wp_error($post_id)) {
                $post_id = null;
            } elseif (!empty($d['auto_seo'])) {
                $seo_result = $this->run_seo_generation((int) $post_id, $topic, $provider, true);
                $result['seo'] = $seo_result;
            }
        }

        return $this->with_ai_meta($this->success(array_merge($result, ['post_id' => $post_id])), $result);
    }

    // -------------------------------------------------------------------------
    // Outline generation
    // -------------------------------------------------------------------------

    public function generate_outline(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d     = (array) $r->get_json_params();
        $topic = sanitize_text_field((string) ($d['topic'] ?? ''));

        if ($topic === '') {
            return $this->error('topic is required');
        }

        $ai     = new Router();
        $result = $ai->complete(
            'You are an expert content strategist. Respond ONLY in JSON: {"title":"...","sections":[{"heading":"...","key_points":["..."]},...],"estimated_words":1500}',
            "Create a detailed blog post outline for: {$topic}\nTarget audience: " . sanitize_text_field((string) ($d['audience'] ?? 'general')),
            ['provider' => sanitize_key((string) ($d['provider'] ?? '')), 'temperature' => 0.4, 'max_tokens' => 1500, 'json_mode' => true]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $outline = json_decode($result['content'], true);
        return $this->with_ai_meta($this->success(['outline' => $outline ?? $result['content'], 'tokens' => $result['tokens'] ?? 0]), $result);
    }

    // -------------------------------------------------------------------------
    // Bulk post generation
    // -------------------------------------------------------------------------

    public function generate_bulk(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $topics = array_map('sanitize_text_field', (array) ($d['topics'] ?? []));
        $topics = array_filter($topics);

        if (empty($topics)) {
            return $this->error('topics array is required');
        }

        $topics = array_slice($topics, 0, 10); // cap at 10

        $ai          = new Router();
        $auto_create = !empty($d['auto_create']);
        $status      = sanitize_key((string) ($d['status'] ?? 'draft'));
        $provider    = sanitize_key((string) ($d['provider'] ?? ''));
        $results     = [];

        foreach ($topics as $topic) {
            $result = $ai->complete(
                'You are an expert content writer. Write in British English. Output clean HTML (h2, h3, p, ul, strong). No markdown.',
                "Write a 900-word blog post on: {$topic}\n Tone: professional. Include intro, body with subheadings, and conclusion.",
                ['provider' => $provider, 'max_tokens' => 5000, 'temperature' => 0.7]
            );

            $entry = [
                'topic'   => $topic,
                'content' => $result['content'] ?? '',
                'tokens'  => $result['tokens']  ?? 0,
                'error'   => $result['error']   ?? null,
                'post_id' => null,
            ];

            if ($auto_create && empty($result['error'])) {
                $pid = wp_insert_post([
                    'post_title'   => $topic,
                    'post_content' => wp_kses_post($result['content']),
                    'post_status'  => $status,
                    'post_type'    => 'post',
                ], true);

                $entry['post_id'] = is_wp_error($pid) ? null : (int) $pid;
            }

            $results[] = $entry;
        }

        $total_tokens = array_sum(array_column($results, 'tokens'));

        return $this->success([
            'results'      => $results,
            'total'        => count($results),
            'auto_created' => $auto_create,
            'total_tokens' => $total_tokens,
        ]);
    }

    // -------------------------------------------------------------------------
    // SEO metadata generation
    // -------------------------------------------------------------------------

    public function generate_seo(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $post_id = (int) ($d['post_id'] ?? 0);
        $post    = get_post($post_id);

        if (!$post) {
            return $this->error('post_id is required and must exist', 404);
        }

        $provider    = sanitize_key((string) ($d['provider'] ?? ''));
        $auto_apply  = !empty($d['auto_apply']);
        $seo         = $this->run_seo_generation($post_id, '', $provider, $auto_apply);

        return $this->success($seo);
    }

    // -------------------------------------------------------------------------
    // Rewrite
    // -------------------------------------------------------------------------

    public function rewrite(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $content = (string) ($d['content'] ?? '');

        if (empty($content)) {
            return $this->error('content is required');
        }

        $style    = sanitize_text_field((string) ($d['style']  ?? 'professional'));
        $length   = sanitize_text_field((string) ($d['length'] ?? ''));
        $language = sanitize_text_field((string) ($d['language'] ?? ''));

        $instructions = [];
        if ($length !== '')   { $instructions[] = "Target length: {$length}"; }
        if ($language !== '') { $instructions[] = "Output in {$language}"; }

        $system = "Rewrite the following content in a {$style} style. Preserve factual accuracy and key information. Output clean HTML." . ($instructions ? ' ' . implode('. ', $instructions) . '.' : '');

        $ai     = new Router();
        $result = $ai->complete($system, $content, [
            'provider'    => sanitize_key((string) ($d['provider'] ?? '')),
            'temperature' => (float) ($d['temperature'] ?? 0.5),
            'max_tokens'  => (int) ($d['max_tokens'] ?? 4096),
        ]);

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        return $this->with_ai_meta($this->success($result), $result);
    }

    // -------------------------------------------------------------------------
    // Translation
    // -------------------------------------------------------------------------

    public function translate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d        = (array) $r->get_json_params();
        $content  = (string) ($d['content']  ?? '');
        $language = sanitize_text_field((string) ($d['language'] ?? ''));

        if (empty($content)) {
            return $this->error('content is required');
        }
        if ($language === '') {
            return $this->error('language is required');
        }

        $preserve_html = !empty($d['preserve_html']) ? 'Preserve all HTML tags exactly as they are, only translate the text within them.' : '';

        $ai     = new Router();
        $result = $ai->complete(
            "You are a professional translator. Translate the content into {$language}. {$preserve_html} Output only the translated content, nothing else.",
            $content,
            ['provider' => sanitize_key((string) ($d['provider'] ?? '')), 'temperature' => 0.2, 'max_tokens' => 8192]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $post_id = null;
        if (!empty($d['auto_create']) && !empty($d['source_post_id'])) {
            $source = get_post((int) $d['source_post_id']);
            if ($source) {
                $post_id = wp_insert_post([
                    'post_title'   => sanitize_text_field((string) ($d['title'] ?? $source->post_title . " ({$language})")),
                    'post_content' => wp_kses_post($result['content']),
                    'post_status'  => 'draft',
                    'post_type'    => $source->post_type,
                ], true);
                if (is_wp_error($post_id)) { $post_id = null; }
            }
        }

        return $this->with_ai_meta($this->success(array_merge($result, ['language' => $language, 'post_id' => $post_id])), $result);
    }

    // -------------------------------------------------------------------------
    // FAQ generation
    // -------------------------------------------------------------------------

    public function generate_faq(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $post_id = (int) ($d['post_id'] ?? 0);
        $content = (string) ($d['content'] ?? '');

        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post) {
                $content = wp_strip_all_tags($post->post_content);
            }
        }

        if (empty($content)) {
            return $this->error('content or post_id is required');
        }

        $count = max(3, min((int) ($d['count'] ?? 5), 15));
        $ai    = new Router();

        $result = $ai->complete(
            "You are an FAQ specialist. Respond ONLY in JSON: {\"faqs\":[{\"question\":\"...\",\"answer\":\"...\"}]}",
            "Generate {$count} FAQ questions and answers based on this content:\n\n" . mb_substr(wp_strip_all_tags($content), 0, 3000),
            ['provider' => sanitize_key((string) ($d['provider'] ?? '')), 'temperature' => 0.4, 'max_tokens' => 2000, 'json_mode' => true]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $parsed = json_decode($result['content'], true);

        return $this->success([
            'faqs'    => $parsed['faqs'] ?? [],
            'raw'     => $parsed === null ? $result['content'] : null,
            'tokens'  => $result['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Excerpt generation
    // -------------------------------------------------------------------------

    public function generate_excerpt(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $post_id = (int) ($d['post_id'] ?? 0);
        $content = (string) ($d['content'] ?? '');

        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post) { $content = wp_strip_all_tags($post->post_content); }
        }

        if (empty($content)) {
            return $this->error('content or post_id is required');
        }

        $length = max(50, min((int) ($d['max_words'] ?? 55), 200));
        $ai     = new Router();

        $result = $ai->complete(
            "Write a compelling excerpt of approximately {$length} words. Plain text only, no HTML, no quotes.",
            mb_substr(wp_strip_all_tags($content), 0, 4000),
            ['provider' => sanitize_key((string) ($d['provider'] ?? '')), 'temperature' => 0.4, 'max_tokens' => 300]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $excerpt = sanitize_textarea_field($result['content']);

        if ($post_id > 0 && !empty($d['auto_apply'])) {
            wp_update_post(['ID' => $post_id, 'post_excerpt' => $excerpt]);
        }

        return $this->success([
            'excerpt'  => $excerpt,
            'post_id'  => $post_id ?: null,
            'applied'  => $post_id > 0 && !empty($d['auto_apply']),
            'tokens'   => $result['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Quality scoring
    // -------------------------------------------------------------------------

    public function quality_score(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $post_id = (int) ($d['post_id'] ?? 0);
        $content = (string) ($d['content'] ?? '');

        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post) {
                $content = $post->post_content;
            }
        }

        if (empty($content)) {
            return $this->error('content or post_id is required');
        }

        $ai     = new Router();
        $result = $ai->complete(
            'You are a content quality analyst. Evaluate the content and respond ONLY in JSON: {"overall":85,"scores":{"readability":80,"seo_friendliness":75,"engagement":90,"accuracy_indicators":80,"structure":85},"word_count":1200,"strengths":["..."],"improvements":["..."]}',
            'Analyse this content quality:\n\n' . mb_substr(wp_strip_all_tags($content), 0, 4000),
            ['provider' => sanitize_key((string) ($d['provider'] ?? '')), 'temperature' => 0.2, 'max_tokens' => 1000, 'json_mode' => true]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $analysis = json_decode($result['content'], true);

        return $this->success([
            'analysis' => $analysis ?? $result['content'],
            'tokens'   => $result['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Run SEO metadata generation for a post and optionally auto-apply.
     */
    private function run_seo_generation(int $post_id, string $fallback_topic, string $provider, bool $auto_apply): array {
        $post = get_post($post_id);
        if (!$post) {
            return ['error' => 'Post not found'];
        }

        $ai     = new Router();
        $result = $ai->complete(
            'You are an SEO specialist. Respond ONLY in JSON: {"seo_title":"...","meta_description":"...","focus_keyword":"...","slug":"...","og_title":"...","og_description":"..."}',
            "Generate SEO metadata for this page:\nTitle: {$post->post_title}\n" .
            "Content preview: " . mb_substr(wp_strip_all_tags($post->post_content), 0, 500),
            ['provider' => $provider, 'temperature' => 0.2, 'max_tokens' => 600, 'json_mode' => true]
        );

        if (!empty($result['error'])) {
            return $result;
        }

        $seo = json_decode($result['content'], true);

        if (is_array($seo) && $auto_apply) {
            $this->set_seo($post_id, $seo);
            $seo['applied'] = true;

            // Apply OG meta
            if (!empty($seo['og_title'])) {
                update_post_meta($post_id, '_aioseo_og_title', sanitize_text_field($seo['og_title']));
            }
            if (!empty($seo['og_description'])) {
                update_post_meta($post_id, '_aioseo_og_description', sanitize_textarea_field($seo['og_description']));
            }
        }

        return ['seo' => $seo ?? $result['content'], 'tokens' => $result['tokens'] ?? 0];
    }
}
