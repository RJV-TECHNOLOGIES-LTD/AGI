<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

/**
 * SEO Management API
 *
 * Unified interface to the four most popular WordPress SEO plugins:
 *   – Yoast SEO (yoast/wordpress-seo)
 *   – Rank Math (rankmath/seo-suite)
 *   – All In One SEO (aioseo/all-in-one-seo-pack)
 *   – The SEO Framework
 *
 * Features:
 *   – Per-post SEO metadata read / write
 *   – Global site SEO settings
 *   – AI bulk SEO generation for multiple posts
 *   – Schema / structured-data generation (Article, FAQ, Product)
 *   – Readability analysis
 *   – Internal linking suggestions
 *   – SEO audit (posts missing metadata)
 */
class SEO extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/seo/post/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this, 'get_post_seo'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT', 'callback' => [$this, 'update_post_seo'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/seo/site', [
            ['methods' => 'GET',       'callback' => [$this, 'site_seo'],        'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_site_seo'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/seo/audit', [
            ['methods' => 'GET', 'callback' => [$this, 'audit'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/seo/ai-bulk', [
            ['methods' => 'POST', 'callback' => [$this, 'ai_bulk'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/seo/schema', [
            ['methods' => 'POST', 'callback' => [$this, 'generate_schema'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/seo/readability/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this, 'readability'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/seo/internal-links/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this, 'internal_links'], 'permission_callback' => [Auth::class, 'tier1']],
        ]);
        register_rest_route($this->namespace, '/seo/sitemap', [
            ['methods' => 'POST', 'callback' => [$this, 'regenerate_sitemap'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Redirects (Yoast Premium / Rank Math)
        register_rest_route($this->namespace, '/seo/redirects', [
            ['methods' => 'GET',  'callback' => [$this, 'list_redirects'],  'permission_callback' => [Auth::class, 'tier1'],
             'args' => ['per_page' => ['default' => 50], 'page' => ['default' => 1]]],
            ['methods' => 'POST', 'callback' => [$this, 'create_redirect'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
        register_rest_route($this->namespace, '/seo/redirects/(?P<id>\d+)', [
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_redirect'], 'permission_callback' => [Auth::class, 'tier2']],
            ['methods' => 'DELETE',    'callback' => [$this, 'delete_redirect'], 'permission_callback' => [Auth::class, 'tier3']],
        ]);

        // Social / Open Graph settings
        register_rest_route($this->namespace, '/seo/social', [
            ['methods' => 'GET',       'callback' => [$this, 'get_social_settings'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_social_settings'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Breadcrumbs settings
        register_rest_route($this->namespace, '/seo/breadcrumbs', [
            ['methods' => 'GET',       'callback' => [$this, 'get_breadcrumb_settings'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'PUT,PATCH', 'callback' => [$this, 'update_breadcrumb_settings'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);

        // Focus keyphrase management
        register_rest_route($this->namespace, '/seo/post/(?P<id>\d+)/keyphrases', [
            ['methods' => 'GET',  'callback' => [$this, 'get_keyphrases'],    'permission_callback' => [Auth::class, 'tier1']],
            ['methods' => 'POST', 'callback' => [$this, 'update_keyphrases'], 'permission_callback' => [Auth::class, 'tier2']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Per-post SEO: read
    // -------------------------------------------------------------------------

    public function get_post_seo(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post) {
            return $this->error('Post not found', 404);
        }

        $seo = $this->get_seo($id);

        // Enrich with raw meta for debugging
        $raw = [];
        foreach ($this->seo_meta_keys() as $key) {
            $val = get_post_meta($id, $key, true);
            if ($val !== '' && $val !== false) {
                $raw[$key] = $val;
            }
        }

        return $this->success(array_merge($seo, [
            'post_id'    => $id,
            'post_title' => $post->post_title,
            'permalink'  => get_permalink($id),
            'raw_meta'   => $raw,
            'plugin'     => $this->active_seo_plugin(),
        ]));
    }

    // -------------------------------------------------------------------------
    // Per-post SEO: write
    // -------------------------------------------------------------------------

    public function update_post_seo(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post) {
            return $this->error('Post not found', 404);
        }

        $d   = (array) $r->get_json_params();
        $this->set_seo($id, $d);

        // Optional slug update
        if (!empty($d['slug'])) {
            wp_update_post(['ID' => $id, 'post_name' => sanitize_title((string) $d['slug'])]);
        }

        $this->log('update_post_seo', 'seo', $id, ['fields' => array_keys($d)], 2);

        return $this->success(['updated' => true, 'post_id' => $id, 'seo' => $this->get_seo($id)]);
    }

    // -------------------------------------------------------------------------
    // Global site SEO settings snapshot
    // -------------------------------------------------------------------------

    public function site_seo(\WP_REST_Request $r): \WP_REST_Response {
        $plugin = $this->active_seo_plugin();
        $data   = ['plugin' => $plugin, 'site_title' => get_option('blogname'), 'tagline' => get_option('blogdescription')];

        switch ($plugin) {
            case 'yoast':
                $data['yoast_options'] = [
                    'website_name'     => get_option('wpseo_titles')['website_name']    ?? '',
                    'separator'        => get_option('wpseo_titles')['separator']        ?? '-',
                    'title_template'   => get_option('wpseo_titles')['title-home-wpseo'] ?? '',
                    'og_default_image' => get_option('wpseo_social')['og_default_image'] ?? '',
                ];
                break;

            case 'rank_math':
                $data['rank_math_options'] = [
                    'separator'         => get_option('rank-math-options-titles')['title_separator'] ?? '-',
                    'homepage_title'    => get_option('rank-math-options-titles')['homepage_title']  ?? '',
                    'local_seo'         => get_option('rank-math-options-local-seo') ? true : false,
                ];
                break;

            case 'aioseo':
                $aioseo = get_option('aioseo_options', []);
                if (is_string($aioseo)) {
                    $aioseo = json_decode($aioseo, true) ?? [];
                }
                $data['aioseo_options'] = [
                    'site_title_format' => $aioseo['searchAppearance']['global']['siteTitle']       ?? '',
                    'og_image'          => $aioseo['social']['facebook']['general']['defaultImage']  ?? '',
                ];
                break;
        }

        return $this->success($data);
    }

    // -------------------------------------------------------------------------
    // SEO audit: find posts with incomplete metadata
    // -------------------------------------------------------------------------

    public function audit(\WP_REST_Request $r): \WP_REST_Response {
        $paging     = $this->parse_pagination($r, 100);
        $post_types = array_map('sanitize_key', (array) ($r['post_types'] ?? ['post', 'page']));
        $issue_type = sanitize_key((string) ($r['issue'] ?? 'all'));

        $query = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $paging['per_page'],
            'offset'         => $paging['offset'],
            'fields'         => 'all',
        ]);

        $issues = [];

        foreach ($query->posts as $post) {
            $seo         = $this->get_seo($post->ID);
            $post_issues = [];

            if ($seo['seo_title'] === '' && in_array($issue_type, ['all', 'missing_title'], true)) {
                $post_issues[] = 'missing_seo_title';
            }
            if ($seo['meta_description'] === '' && in_array($issue_type, ['all', 'missing_description'], true)) {
                $post_issues[] = 'missing_meta_description';
            }
            if ($seo['focus_keyword'] === '' && in_array($issue_type, ['all', 'missing_keyword'], true)) {
                $post_issues[] = 'missing_focus_keyword';
            }

            $word_count = str_word_count(wp_strip_all_tags($post->post_content));
            if ($word_count < 300 && in_array($issue_type, ['all', 'thin_content'], true)) {
                $post_issues[] = 'thin_content';
            }

            if (!empty($post_issues)) {
                $issues[] = [
                    'post_id'    => $post->ID,
                    'title'      => $post->post_title,
                    'permalink'  => get_permalink($post->ID),
                    'post_type'  => $post->post_type,
                    'word_count' => $word_count,
                    'issues'     => $post_issues,
                ];
            }
        }

        $total_query = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        $total = $total_query->found_posts;

        return $this->paginated($issues, count($issues), $paging['page'], $paging['per_page']);
    }

    // -------------------------------------------------------------------------
    // AI bulk SEO generation
    // -------------------------------------------------------------------------

    public function ai_bulk(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d        = (array) $r->get_json_params();
        $ids      = array_map('absint', (array) ($d['post_ids'] ?? []));
        $ids      = array_slice(array_filter($ids), 0, 20);

        if (empty($ids)) {
            return $this->error('post_ids is required (max 20)');
        }

        $ai         = new Router();
        $auto_apply = !empty($d['auto_apply']);
        $provider   = sanitize_key((string) ($d['provider'] ?? ''));
        $results    = [];

        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post) {
                $results[] = ['post_id' => $id, 'error' => 'Not found'];
                continue;
            }

            $result = $ai->complete(
                'You are an SEO specialist. Respond ONLY in JSON: {"seo_title":"...","meta_description":"...","focus_keyword":"...","slug":"..."}',
                "Post title: {$post->post_title}\nContent preview: " . mb_substr(wp_strip_all_tags($post->post_content), 0, 400),
                ['provider' => $provider, 'temperature' => 0.2, 'max_tokens' => 400, 'json_mode' => true]
            );

            if (!empty($result['error'])) {
                $results[] = ['post_id' => $id, 'error' => $result['error']];
                continue;
            }

            $seo = json_decode($result['content'], true);

            if ($auto_apply && is_array($seo)) {
                $this->set_seo($id, $seo);
                if (!empty($seo['slug'])) {
                    wp_update_post(['ID' => $id, 'post_name' => sanitize_title($seo['slug'])]);
                }
            }

            $results[] = [
                'post_id' => $id,
                'title'   => $post->post_title,
                'seo'     => $seo ?? $result['content'],
                'applied' => $auto_apply && is_array($seo),
                'tokens'  => $result['tokens'] ?? 0,
            ];
        }

        $total_tokens = array_sum(array_column($results, 'tokens'));

        $this->log('ai_bulk_seo', 'seo', 0, [
            'count'       => count($ids),
            'auto_applied'=> $auto_apply,
            'total_tokens'=> $total_tokens,
        ], 2);

        return $this->success([
            'results'      => $results,
            'total_tokens' => $total_tokens,
            'auto_applied' => $auto_apply,
        ]);
    }

    // -------------------------------------------------------------------------
    // Schema / structured data generation
    // -------------------------------------------------------------------------

    public function generate_schema(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d       = (array) $r->get_json_params();
        $post_id = (int) ($d['post_id'] ?? 0);
        $type    = sanitize_key((string) ($d['schema_type'] ?? 'Article'));

        $valid_types = ['Article', 'BlogPosting', 'FAQPage', 'Product', 'LocalBusiness', 'BreadcrumbList', 'HowTo'];
        if (!in_array($type, $valid_types, true)) {
            return $this->error('schema_type must be one of: ' . implode(', ', $valid_types), 422);
        }

        $post    = $post_id > 0 ? get_post($post_id) : null;
        $context = $post ? "Title: {$post->post_title}\nContent: " . mb_substr(wp_strip_all_tags($post->post_content), 0, 1000) : sanitize_textarea_field((string) ($d['context'] ?? ''));

        if (empty($context)) {
            return $this->error('post_id or context is required');
        }

        $ai     = new Router();
        $result = $ai->complete(
            "Generate valid JSON-LD schema markup of type {$type}. Respond ONLY with valid JSON-LD (starting with {\"@context\":\"https://schema.org\"}). No markdown, no explanation.",
            "Create {$type} schema for:\n{$context}",
            ['provider' => sanitize_key((string) ($d['provider'] ?? '')), 'temperature' => 0.1, 'max_tokens' => 1500]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $schema = json_decode($result['content'], true);

        // Optionally inject into post content as <script type="application/ld+json">
        if ($post && !empty($d['auto_inject']) && $schema !== null) {
            $json_ld  = '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
            $existing = $post->post_content;

            if (!str_contains($existing, 'application/ld+json')) {
                wp_update_post(['ID' => $post_id, 'post_content' => $existing . "\n" . $json_ld]);
            }
        }

        return $this->success([
            'schema'      => $schema ?? $result['content'],
            'schema_type' => $type,
            'injected'    => $post && !empty($d['auto_inject']),
            'tokens'      => $result['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Readability analysis
    // -------------------------------------------------------------------------

    public function readability(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post) {
            return $this->error('Post not found', 404);
        }

        $content    = wp_strip_all_tags($post->post_content);
        $word_count = str_word_count($content);
        $sentences  = max(1, preg_match_all('/[.!?]+/', $content, $m));
        $paragraphs = max(1, substr_count($post->post_content, '</p>'));
        $avg_words  = $word_count / $sentences;

        // Simplified Flesch-Kincaid–style grade estimate
        $syllables  = $this->count_syllables($content);
        $fk_score   = 206.835 - (1.015 * ($word_count / $sentences)) - (84.6 * ($syllables / max(1, $word_count)));
        $fk_score   = max(0, min(100, round($fk_score, 1)));

        $grade = match (true) {
            $fk_score >= 80 => 'Very easy',
            $fk_score >= 60 => 'Standard',
            $fk_score >= 40 => 'Difficult',
            default         => 'Very difficult',
        };

        $has_subheadings = (bool) preg_match('/<h[23]/i', $post->post_content);
        $has_images      = (bool) preg_match('/<img/i', $post->post_content);
        $has_lists       = (bool) preg_match('/<[ou]l/i', $post->post_content);

        $suggestions = [];
        if ($word_count < 300) { $suggestions[] = 'Content is thin (< 300 words). Aim for 600+ words for better SEO.'; }
        if (!$has_subheadings) { $suggestions[] = 'Add H2/H3 subheadings to improve structure.'; }
        if (!$has_images)      { $suggestions[] = 'Include at least one image to increase engagement.'; }
        if ($avg_words > 25)   { $suggestions[] = 'Average sentence length is long. Aim for < 20 words.'; }

        return $this->success([
            'post_id'        => $id,
            'word_count'     => $word_count,
            'sentence_count' => $sentences,
            'paragraph_count'=> $paragraphs,
            'avg_sentence_length' => round($avg_words, 1),
            'flesch_score'   => $fk_score,
            'readability_grade' => $grade,
            'has_subheadings'=> $has_subheadings,
            'has_images'     => $has_images,
            'has_lists'      => $has_lists,
            'suggestions'    => $suggestions,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal linking suggestions
    // -------------------------------------------------------------------------

    public function internal_links(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id   = (int) $r['id'];
        $post = get_post($id);

        if (!$post) {
            return $this->error('Post not found', 404);
        }

        $ai   = new Router();

        // Get the 30 most recent published posts/pages (excluding this one)
        $candidates = get_posts([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'exclude'        => [$id],
        ]);

        if (empty($candidates)) {
            return $this->success(['suggestions' => [], 'message' => 'No other published content found']);
        }

        $candidate_list = implode("\n", array_map(
            fn($p): string => "ID:{$p->ID} | TITLE:{$p->post_title} | URL:" . get_permalink($p->ID),
            $candidates
        ));

        $result = $ai->complete(
            'You are an internal linking specialist. Respond ONLY in JSON: {"suggestions":[{"post_id":123,"title":"...","url":"...","anchor_text":"...","reason":"..."},...]}',
            "Source post: {$post->post_title}\nSource content (preview): " . mb_substr(wp_strip_all_tags($post->post_content), 0, 500) .
            "\n\nAvailable target posts:\n{$candidate_list}\n\nSuggest the top 5 most relevant internal links.",
            ['temperature' => 0.3, 'max_tokens' => 800, 'json_mode' => true]
        );

        if (!empty($result['error'])) {
            return $this->error($result['error'], 502);
        }

        $parsed = json_decode($result['content'], true);

        return $this->success([
            'suggestions' => $parsed['suggestions'] ?? [],
            'tokens'      => $result['tokens'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Sitemap regeneration trigger
    // -------------------------------------------------------------------------

    public function regenerate_sitemap(\WP_REST_Request $r): \WP_REST_Response {
        $plugin = $this->active_seo_plugin();
        $done   = false;

        switch ($plugin) {
            case 'yoast':
                if (class_exists('\WPSEO_Sitemaps_Router')) {
                    do_action('wpseo_ping_search_engines');
                    $done = true;
                }
                break;

            case 'rank_math':
                if (function_exists('rank_math')) {
                    do_action('rank_math/sitemap/update_sitemap');
                    $done = true;
                }
                break;
        }

        // Generic: delete all SEO-related transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%sitemap%' AND option_name LIKE '_transient_%'");

        $this->log('regenerate_sitemap', 'seo', 0, ['plugin' => $plugin], 2);

        return $this->success([
            'triggered' => true,
            'plugin'    => $plugin,
            'note'      => $done ? 'Sitemap regeneration triggered via plugin hook' : 'Sitemap transients cleared; plugin hook not available',
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Update site-wide SEO settings for the active plugin.
     */
    public function update_site_seo(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $plugin = $this->active_seo_plugin();

        $updated = [];

        switch ($plugin) {
            case 'yoast':
                $option = get_option('wpseo_titles', []);
                $map    = [
                    'title_separator'   => 'separator',
                    'homepage_title'    => 'title-home-wpseo',
                    'homepage_desc'     => 'metadesc-home-wpseo',
                    'company_name'      => 'company_name',
                    'company_logo'      => 'company_logo_url',
                    'noindex_archives'  => 'noindex-date-wpseo',
                    'noindex_authors'   => 'noindex-author-wpseo',
                ];
                foreach ($map as $k => $opt_k) {
                    if (!array_key_exists($k, $d)) continue;
                    $option[$opt_k] = sanitize_text_field((string) $d[$k]);
                    $updated[] = $k;
                }
                update_option('wpseo_titles', $option);
                break;

            case 'rank_math':
                $option = get_option('rank_math_general', []);
                $map    = [
                    'title_separator'  => 'title_separator',
                    'company_name'     => 'knowledgegraph_name',
                    'company_logo'     => 'knowledgegraph_logo',
                    'noindex_archives' => 'disable_date_archives',
                    'noindex_authors'  => 'disable_author_archives',
                ];
                foreach ($map as $k => $opt_k) {
                    if (!array_key_exists($k, $d)) continue;
                    $option[$opt_k] = sanitize_text_field((string) $d[$k]);
                    $updated[] = $k;
                }
                update_option('rank_math_general', $option);
                break;

            default:
                return $this->error('No supported SEO plugin detected', 503);
        }

        $this->log('seo_update_site_settings', 'seo', 0, ['plugin' => $plugin, 'updated' => $updated], 2);
        return $this->success(['updated' => $updated, 'plugin' => $plugin]);
    }

    // =========================================================================
    // Redirects
    // =========================================================================

    public function list_redirects(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $plugin = $this->active_seo_plugin();
        $pp     = min((int) ($r['per_page'] ?? 50), 200);
        $page   = max(1, (int) ($r['page'] ?? 1));

        // Rank Math has a built-in redirect manager
        if ($plugin === 'rank_math' && class_exists('\RankMath\Redirections\DB')) {
            $items = \RankMath\Redirections\DB::get_redirections([
                'limit'  => $pp,
                'offset' => ($page - 1) * $pp,
            ]);
            return $this->success([
                'plugin'    => 'rank_math',
                'redirects' => $items['redirections'] ?? [],
                'total'     => (int) ($items['count'] ?? 0),
            ]);
        }

        // Yoast Premium redirect table
        if ($plugin === 'yoast') {
            global $wpdb;
            $table = $wpdb->prefix . 'yoast_seo_links';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE type = 'redirect'");
                $rows  = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE type = 'redirect' ORDER BY id DESC LIMIT %d OFFSET %d",
                    $pp, ($page - 1) * $pp
                ), ARRAY_A) ?: [];
                return $this->success(['plugin' => 'yoast', 'redirects' => $rows, 'total' => $total]);
            }
        }

        // Fallback: check for Redirection plugin
        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT id, url, action_data, regex, enabled FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $pp, ($page - 1) * $pp
            ), ARRAY_A) ?: [];
            return $this->success([
                'plugin'    => 'redirection',
                'redirects' => array_map(fn($row) => [
                    'id'     => (int) $row['id'],
                    'source' => $row['url'],
                    'target' => $row['action_data'],
                    'regex'  => (bool) $row['regex'],
                    'active' => (bool) $row['enabled'],
                ], $rows),
                'total' => $total,
            ]);
        }

        return $this->error('No redirect manager found (Rank Math, Yoast Premium, or Redirection plugin required)', 503);
    }

    public function create_redirect(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $source = sanitize_text_field((string) ($d['source'] ?? ''));
        $target = esc_url_raw((string) ($d['target'] ?? ''));

        if (empty($source) || empty($target)) {
            return $this->error('source and target are required');
        }

        $plugin = $this->active_seo_plugin();

        // Rank Math
        if ($plugin === 'rank_math' && class_exists('\RankMath\Redirections\DB')) {
            $id = \RankMath\Redirections\DB::update([
                'sources'     => [['pattern' => $source, 'comparison' => sanitize_key($d['comparison'] ?? 'exact')]],
                'url_to'      => $target,
                'header_code' => (int) ($d['code'] ?? 301),
                'status'      => 'active',
            ]);
            $this->log('seo_create_redirect', 'seo', $id, ['source' => $source], 2);
            return $this->success(['id' => $id, 'source' => $source, 'target' => $target], 201);
        }

        // Redirection plugin fallback
        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            $wpdb->insert($table, [
                'url'           => $source,
                'action_type'   => 'url',
                'action_data'   => $target,
                'action_code'   => (int) ($d['code'] ?? 301),
                'enabled'       => 1,
                'regex'         => (int) (bool) ($d['regex'] ?? false),
                'group_id'      => 1,
                'hits'          => 0,
                'created'       => current_time('mysql', true),
            ]);
            $id = $wpdb->insert_id;
            $this->log('seo_create_redirect', 'seo', $id, ['source' => $source, 'plugin' => 'redirection'], 2);
            return $this->success(['id' => $id, 'source' => $source, 'target' => $target], 201);
        }

        return $this->error('No redirect manager found', 503);
    }

    public function update_redirect(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id = (int) $r['id'];
        $d  = (array) $r->get_json_params();

        // Redirection plugin
        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            $update = [];
            if (isset($d['source'])) $update['url']         = sanitize_text_field($d['source']);
            if (isset($d['target'])) $update['action_data'] = esc_url_raw($d['target']);
            if (isset($d['active'])) $update['enabled']     = (int) (bool) $d['active'];
            if (isset($d['code']))   $update['action_code'] = (int) $d['code'];
            if (!empty($update)) $wpdb->update($table, $update, ['id' => $id]);
            $this->log('seo_update_redirect', 'seo', $id, [], 2);
            return $this->success(['updated' => true, 'id' => $id]);
        }

        return $this->error('No redirect manager found', 503);
    }

    public function delete_redirect(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id     = (int) $r['id'];
        $plugin = $this->active_seo_plugin();

        if ($plugin === 'rank_math' && class_exists('\RankMath\Redirections\DB')) {
            \RankMath\Redirections\DB::delete($id);
            $this->log('seo_delete_redirect', 'seo', $id, ['plugin' => 'rank_math'], 3);
            return $this->success(['deleted' => true]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            $wpdb->delete($table, ['id' => $id]);
            $this->log('seo_delete_redirect', 'seo', $id, ['plugin' => 'redirection'], 3);
            return $this->success(['deleted' => true]);
        }

        return $this->error('No redirect manager found', 503);
    }

    // =========================================================================
    // Social / Open Graph settings
    // =========================================================================

    public function get_social_settings(\WP_REST_Request $r): \WP_REST_Response {
        $plugin = $this->active_seo_plugin();

        switch ($plugin) {
            case 'yoast':
                $opt = get_option('wpseo_social', []);
                return $this->success([
                    'plugin'           => 'yoast',
                    'facebook_site'    => $opt['facebook_site'] ?? '',
                    'twitter_site'     => $opt['twitter_site'] ?? '',
                    'og_default_image' => $opt['og_default_image'] ?? '',
                    'og_frontpage_title' => $opt['og_frontpage_title'] ?? '',
                    'og_frontpage_desc'  => $opt['og_frontpage_desc'] ?? '',
                    'twitter_card_type'  => $opt['twitter_card_type'] ?? 'summary_large_image',
                    'open_graph_frontpage_image' => $opt['og_frontpage_image'] ?? '',
                ]);

            case 'rank_math':
                $opt = get_option('rank_math_general', []);
                return $this->success([
                    'plugin'           => 'rank_math',
                    'facebook_author'  => $opt['social_url_facebook'] ?? '',
                    'twitter_handle'   => $opt['social_url_twitter'] ?? '',
                    'og_default_image' => $opt['open_graph_image'] ?? '',
                ]);

            default:
                return $this->success(['plugin' => $plugin, 'note' => 'Social settings not available for this plugin']);
        }
    }

    public function update_social_settings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $plugin = $this->active_seo_plugin();
        $updated= [];

        switch ($plugin) {
            case 'yoast':
                $opt = get_option('wpseo_social', []);
                $map = [
                    'facebook_site'      => 'facebook_site',
                    'twitter_site'       => 'twitter_site',
                    'og_default_image'   => 'og_default_image',
                    'og_frontpage_title' => 'og_frontpage_title',
                    'og_frontpage_desc'  => 'og_frontpage_desc',
                    'twitter_card_type'  => 'twitter_card_type',
                ];
                foreach ($map as $k => $opt_k) {
                    if (!array_key_exists($k, $d)) continue;
                    $opt[$opt_k] = sanitize_text_field((string) $d[$k]);
                    $updated[] = $k;
                }
                update_option('wpseo_social', $opt);
                break;

            case 'rank_math':
                $opt = get_option('rank_math_general', []);
                $map = [
                    'facebook_author'  => 'social_url_facebook',
                    'twitter_handle'   => 'social_url_twitter',
                    'og_default_image' => 'open_graph_image',
                ];
                foreach ($map as $k => $opt_k) {
                    if (!array_key_exists($k, $d)) continue;
                    $opt[$opt_k] = sanitize_text_field((string) $d[$k]);
                    $updated[] = $k;
                }
                update_option('rank_math_general', $opt);
                break;

            default:
                return $this->error('No supported SEO plugin detected', 503);
        }

        $this->log('seo_update_social', 'seo', 0, ['plugin' => $plugin, 'updated' => $updated], 2);
        return $this->success(['plugin' => $plugin, 'updated' => $updated]);
    }

    // =========================================================================
    // Breadcrumbs settings
    // =========================================================================

    public function get_breadcrumb_settings(\WP_REST_Request $r): \WP_REST_Response {
        $plugin = $this->active_seo_plugin();

        switch ($plugin) {
            case 'yoast':
                $opt = get_option('wpseo_titles', []);
                return $this->success([
                    'plugin'         => 'yoast',
                    'enabled'        => (bool) ($opt['breadcrumbs-enable'] ?? false),
                    'separator'      => $opt['breadcrumbs-sep'] ?? '»',
                    'home_label'     => $opt['breadcrumbs-home'] ?? 'Home',
                    'show_blog_page' => (bool) ($opt['breadcrumbs-blog-remove'] ?? false),
                    'prefix'         => $opt['breadcrumbs-prefix'] ?? '',
                ]);

            case 'rank_math':
                $opt = get_option('rank_math_general', []);
                return $this->success([
                    'plugin'         => 'rank_math',
                    'enabled'        => true,
                    'separator'      => $opt['breadcrumbs_separator'] ?? '/',
                    'home_label'     => $opt['breadcrumbs_home_label'] ?? 'Home',
                    'prefix'         => $opt['breadcrumbs_prefix'] ?? '',
                ]);

            default:
                return $this->success(['plugin' => $plugin, 'note' => 'Breadcrumb settings not available for this plugin']);
        }
    }

    public function update_breadcrumb_settings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d      = (array) $r->get_json_params();
        $plugin = $this->active_seo_plugin();
        $updated= [];

        switch ($plugin) {
            case 'yoast':
                $opt = get_option('wpseo_titles', []);
                $map = [
                    'enabled'        => 'breadcrumbs-enable',
                    'separator'      => 'breadcrumbs-sep',
                    'home_label'     => 'breadcrumbs-home',
                    'show_blog_page' => 'breadcrumbs-blog-remove',
                    'prefix'         => 'breadcrumbs-prefix',
                ];
                foreach ($map as $k => $opt_k) {
                    if (!array_key_exists($k, $d)) continue;
                    $opt[$opt_k] = in_array($k, ['enabled', 'show_blog_page'], true)
                        ? (bool) $d[$k]
                        : sanitize_text_field((string) $d[$k]);
                    $updated[] = $k;
                }
                update_option('wpseo_titles', $opt);
                break;

            case 'rank_math':
                $opt = get_option('rank_math_general', []);
                $map = [
                    'separator'  => 'breadcrumbs_separator',
                    'home_label' => 'breadcrumbs_home_label',
                    'prefix'     => 'breadcrumbs_prefix',
                ];
                foreach ($map as $k => $opt_k) {
                    if (!array_key_exists($k, $d)) continue;
                    $opt[$opt_k] = sanitize_text_field((string) $d[$k]);
                    $updated[] = $k;
                }
                update_option('rank_math_general', $opt);
                break;

            default:
                return $this->error('No supported SEO plugin detected', 503);
        }

        $this->log('seo_update_breadcrumbs', 'seo', 0, ['plugin' => $plugin, 'updated' => $updated], 2);
        return $this->success(['plugin' => $plugin, 'updated' => $updated]);
    }

    // =========================================================================
    // Focus keyphrases
    // =========================================================================

    public function get_keyphrases(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $post_id = (int) $r['id'];
        if (!get_post($post_id)) return $this->error('Post not found', 404);

        $plugin = $this->active_seo_plugin();

        switch ($plugin) {
            case 'yoast':
                return $this->success([
                    'plugin'       => 'yoast',
                    'post_id'      => $post_id,
                    'focus_keyphrase' => get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
                    'related_keyphrases' => get_post_meta($post_id, '_yoast_wpseo_focuskeywords', true),
                ]);

            case 'rank_math':
                return $this->success([
                    'plugin'       => 'rank_math',
                    'post_id'      => $post_id,
                    'focus_keyphrase' => get_post_meta($post_id, 'rank_math_focus_keyword', true),
                ]);

            default:
                return $this->error('Keyphrase management requires Yoast or Rank Math', 503);
        }
    }

    public function update_keyphrases(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $post_id = (int) $r['id'];
        if (!get_post($post_id)) return $this->error('Post not found', 404);

        $d      = (array) $r->get_json_params();
        $plugin = $this->active_seo_plugin();
        $updated= [];

        switch ($plugin) {
            case 'yoast':
                if (isset($d['focus_keyphrase'])) {
                    update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($d['focus_keyphrase']));
                    $updated[] = 'focus_keyphrase';
                }
                break;

            case 'rank_math':
                if (isset($d['focus_keyphrase'])) {
                    update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($d['focus_keyphrase']));
                    $updated[] = 'focus_keyphrase';
                }
                break;

            default:
                return $this->error('Keyphrase management requires Yoast or Rank Math', 503);
        }

        $this->log('seo_update_keyphrases', 'seo', $post_id, ['plugin' => $plugin, 'updated' => $updated], 2);
        return $this->success(['post_id' => $post_id, 'plugin' => $plugin, 'updated' => $updated]);
    }

    private function active_seo_plugin(): string {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            return 'rank_math';
        }
        if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\Plugin\AIOSEO')) {
            return 'aioseo';
        }
        if (class_exists('\The_SEO_Framework\Load')) {
            return 'seo_framework';
        }
        return 'none';
    }

    /**
     * All known SEO meta keys across supported plugins.
     *
     * @return string[]
     */
    private function seo_meta_keys(): array {
        return [
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
            '_yoast_wpseo_canonical', '_yoast_wpseo_opengraph-image',
            'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
            'rank_math_og_title', 'rank_math_og_description',
            '_aioseo_title', '_aioseo_description', '_aioseo_og_title', '_aioseo_og_description',
            '_genesis_title', '_genesis_description', '_genesis_noindex',
        ];
    }

    /**
     * Estimate syllable count (English approximation for Flesch scoring).
     */
    private function count_syllables(string $text): int {
        $words     = preg_split('/\s+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $syllables = 0;

        foreach ($words as $word) {
            $word      = preg_replace('/[^a-z]/', '', $word) ?? '';
            $count     = max(1, (int) preg_match_all('/[aeiouy]+/', $word, $m));
            // Subtract silent trailing e
            if (str_ends_with($word, 'e') && strlen($word) > 2) {
                $count--;
            }
            $syllables += max(1, $count);
        }

        return $syllables;
    }
}
