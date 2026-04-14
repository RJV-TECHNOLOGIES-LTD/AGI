<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\AuditLog;

/**
 * Base REST Controller
 *
 * Provides shared helpers to all API controllers:
 *   – Standardised success/error response envelopes
 *   – Pagination helpers with RFC 5988 Link headers
 *   – Structured error codes
 *   – SEO meta read/write helpers shared across Posts and Pages
 *   – Request timing capture
 */
abstract class Base {

    protected string $namespace = 'rjv-agi/v1';

    /** Controller boot timestamp (set on first use). */
    private static ?float $request_start = null;

    abstract public function register_routes(): void;

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    /**
     * Return a standard success envelope.
     *
     * @param mixed $data    Payload to wrap.
     * @param int   $status  HTTP status code (default 200).
     * @param array $meta    Optional extra keys merged into the envelope.
     */
    protected function success(mixed $data, int $status = 200, array $meta = []): \WP_REST_Response {
        $body = array_merge(['success' => true, 'data' => $data], $meta);
        return new \WP_REST_Response($body, $status);
    }

    /**
     * Return a structured error response.
     *
     * @param string      $message   Human-readable error message.
     * @param int         $status    HTTP status code (default 400).
     * @param string|null $code      Machine-readable error code.
     * @param array       $data      Additional error context.
     */
    protected function error(
        string  $message,
        int     $status  = 400,
        ?string $code    = null,
        array   $data    = []
    ): \WP_Error {
        $error_code = $code ?? match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_error',
            $status === 429 => 'rate_limited',
            $status >= 500  => 'server_error',
            default         => 'bad_request',
        };

        return new \WP_Error(
            $error_code,
            $message,
            array_merge(['status' => $status], $data)
        );
    }

    /**
     * Return a paginated success response and attach RFC 5988 Link headers.
     *
     * @param array  $items      Paged result set.
     * @param int    $total      Total number of matching records.
     * @param int    $page       Current page number (1-based).
     * @param int    $per_page   Items per page.
     * @param string $base_url   Base URL for Link header construction.
     */
    protected function paginated(
        array  $items,
        int    $total,
        int    $page,
        int    $per_page,
        string $base_url = ''
    ): \WP_REST_Response {
        $pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        $response = $this->success([
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $pages,
        ]);

        // RFC 5988 Link headers
        if ($base_url !== '') {
            $links = [];
            if ($page > 1) {
                $links[] = "<{$base_url}?page=1&per_page={$per_page}>; rel=\"first\"";
                $links[] = "<{$base_url}?page=" . ($page - 1) . "&per_page={$per_page}>; rel=\"prev\"";
            }
            if ($page < $pages) {
                $links[] = "<{$base_url}?page=" . ($page + 1) . "&per_page={$per_page}>; rel=\"next\"";
                $links[] = "<{$base_url}?page={$pages}&per_page={$per_page}>; rel=\"last\"";
            }
            if ($links) {
                $response->header('Link', implode(', ', $links));
            }
        }

        $response->header('X-Total-Count', (string) $total);
        $response->header('X-Total-Pages', (string) $pages);

        return $response;
    }

    // -------------------------------------------------------------------------
    // Audit log helper
    // -------------------------------------------------------------------------

    protected function log(
        string  $action,
        string  $resource_type = '',
        int     $resource_id   = 0,
        array   $details       = [],
        int     $tier          = 1,
        string  $status        = 'success'
    ): void {
        AuditLog::log($action, $resource_type, $resource_id, $details, $tier, $status);
    }

    // -------------------------------------------------------------------------
    // Pagination argument parser
    // -------------------------------------------------------------------------

    /**
     * Parse and normalise pagination parameters from a request.
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    protected function parse_pagination(\WP_REST_Request $r, int $max_per_page = 100): array {
        $per_page = max(1, min((int) ($r['per_page'] ?? 20), $max_per_page));
        $page     = max(1, (int) ($r['page'] ?? 1));
        return [
            'page'     => $page,
            'per_page' => $per_page,
            'offset'   => ($page - 1) * $per_page,
        ];
    }

    // -------------------------------------------------------------------------
    // SEO meta helpers (shared between Posts, Pages, ContentGen)
    // -------------------------------------------------------------------------

    /**
     * Write SEO metadata for a post, supporting Yoast SEO, Rank Math,
     * All in One SEO, and The SEO Framework.
     *
     * @param int   $post_id  Target post ID.
     * @param array $seo      Map of seo_title, meta_description, focus_keyword, canonical …
     */
    protected function set_seo(int $post_id, array $seo): void {
        $title       = sanitize_text_field((string) ($seo['seo_title']       ?? $seo['title']       ?? ''));
        $description = sanitize_textarea_field((string) ($seo['meta_description'] ?? $seo['description'] ?? ''));
        $keyword     = sanitize_text_field((string) ($seo['focus_keyword']   ?? $seo['keyword']     ?? ''));
        $canonical   = esc_url_raw((string) ($seo['canonical']               ?? ''));

        // Yoast SEO
        if ($title)       update_post_meta($post_id, '_yoast_wpseo_title',   $title);
        if ($description) update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
        if ($keyword)     update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
        if ($canonical)   update_post_meta($post_id, '_yoast_wpseo_canonical', $canonical);

        // Rank Math
        if ($title)       update_post_meta($post_id, 'rank_math_title',       $title);
        if ($description) update_post_meta($post_id, 'rank_math_description',  $description);
        if ($keyword)     update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);

        // All in One SEO
        if ($title)       update_post_meta($post_id, '_aioseo_title',          $title);
        if ($description) update_post_meta($post_id, '_aioseo_description',    $description);

        // The SEO Framework
        if ($title)       update_post_meta($post_id, '_genesis_title',         $title);
        if ($description) update_post_meta($post_id, '_genesis_description',   $description);
    }

    /**
     * Read SEO metadata for a post from whichever plugin is active.
     *
     * @return array{seo_title: string, meta_description: string, focus_keyword: string, canonical: string, source: string}
     */
    protected function get_seo(int $post_id): array {
        // Yoast SEO
        $title = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
        if ($title !== '') {
            return [
                'seo_title'        => $title,
                'meta_description' => (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
                'focus_keyword'    => (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
                'canonical'        => (string) get_post_meta($post_id, '_yoast_wpseo_canonical', true),
                'source'           => 'yoast',
            ];
        }

        // Rank Math
        $title = (string) get_post_meta($post_id, 'rank_math_title', true);
        if ($title !== '') {
            return [
                'seo_title'        => $title,
                'meta_description' => (string) get_post_meta($post_id, 'rank_math_description', true),
                'focus_keyword'    => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
                'canonical'        => '',
                'source'           => 'rank_math',
            ];
        }

        // All in One SEO
        $title = (string) get_post_meta($post_id, '_aioseo_title', true);
        if ($title !== '') {
            return [
                'seo_title'        => $title,
                'meta_description' => (string) get_post_meta($post_id, '_aioseo_description', true),
                'focus_keyword'    => '',
                'canonical'        => '',
                'source'           => 'aioseo',
            ];
        }

        return [
            'seo_title'        => '',
            'meta_description' => '',
            'focus_keyword'    => '',
            'canonical'        => '',
            'source'           => 'none',
        ];
    }

    // -------------------------------------------------------------------------
    // Request timing
    // -------------------------------------------------------------------------

    /** Record the start of a timed operation. */
    protected function start_timer(): float {
        self::$request_start = microtime(true);
        return self::$request_start;
    }

    /** Return elapsed milliseconds since start_timer() was called. */
    protected function elapsed_ms(): int {
        if (self::$request_start === null) {
            return 0;
        }
        return (int) ((microtime(true) - self::$request_start) * 1000);
    }
}
