<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

/**
 * Event Schema Registry
 *
 * Authoritative, versioned catalogue of every event type the data-collection
 * layer can capture.  The AGI queries this to know exactly what events to
 * expect, what properties each event carries, and which industry verticals
 * each event is relevant to.
 *
 * This class is read-only — no database writes, no mutable state.
 * Schema version is bumped whenever a new event type or property is added.
 */
final class Schema {

    public const VERSION = '1.0.0';

    // ── Category constants ────────────────────────────────────────────────────
    public const CAT_AUTH        = 'auth';
    public const CAT_NAVIGATION  = 'navigation';
    public const CAT_ENGAGEMENT  = 'engagement';
    public const CAT_CONTENT     = 'content';
    public const CAT_ECOMMERCE   = 'ecommerce';
    public const CAT_FORM        = 'form';
    public const CAT_MEDIA       = 'media';
    public const CAT_AGI         = 'agi';
    public const CAT_SYSTEM      = 'system';
    public const CAT_PERFORMANCE = 'performance';
    public const CAT_ERROR       = 'error';

    // ── Industry constants ────────────────────────────────────────────────────
    public const IND_ALL           = ['general','ecommerce','saas','media','b2b','healthcare',
                                      'finance','education','real_estate','legal','manufacturing'];
    public const IND_ECOMMERCE     = ['general','ecommerce'];
    public const IND_EDUCATION     = ['general','education','saas'];
    public const IND_FINANCE       = ['general','finance','saas'];
    public const IND_HEALTHCARE    = ['general','healthcare'];
    public const IND_B2B           = ['general','b2b','saas'];
    public const IND_MEDIA         = ['general','media'];
    public const IND_REAL_ESTATE   = ['general','real_estate'];
    public const IND_LEGAL         = ['general','legal'];
    public const IND_MANUFACTURING = ['general','manufacturing','b2b'];

    /**
     * Return the complete event-type schema.
     *
     * Each entry has:
     *   event_type   string   Unique slug used in the dc_events table.
     *   category     string   Logical grouping.
     *   industries   string[] Industry verticals this event applies to.
     *   description  string   Human + AGI readable description.
     *   source       string   Where this event originates: server|browser|both.
     *   properties   array[]  Each property: name, type, required, description.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array {
        return [

            // ── Authentication ────────────────────────────────────────────────
            [
                'event_type'  => 'user_login',
                'category'    => self::CAT_AUTH,
                'industries'  => self::IND_ALL,
                'description' => 'A WordPress user successfully authenticated.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'wp_user_id',  'type' => 'integer', 'required' => true,  'description' => 'WordPress user ID'],
                    ['name' => 'user_login',  'type' => 'string',  'required' => true,  'description' => 'WordPress login name'],
                    ['name' => 'roles',       'type' => 'array',   'required' => false, 'description' => 'Array of WP role slugs'],
                ],
            ],
            [
                'event_type'  => 'user_login_failed',
                'category'    => self::CAT_AUTH,
                'industries'  => self::IND_ALL,
                'description' => 'A login attempt failed.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'username', 'type' => 'string', 'required' => true,  'description' => 'Attempted login name'],
                    ['name' => 'ip',       'type' => 'string', 'required' => false, 'description' => 'Client IP address'],
                ],
            ],
            [
                'event_type'  => 'user_logout',
                'category'    => self::CAT_AUTH,
                'industries'  => self::IND_ALL,
                'description' => 'A WordPress user logged out.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'wp_user_id', 'type' => 'integer', 'required' => true, 'description' => 'WordPress user ID'],
                ],
            ],
            [
                'event_type'  => 'user_register',
                'category'    => self::CAT_AUTH,
                'industries'  => self::IND_ALL,
                'description' => 'A new WordPress user account was created.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'wp_user_id', 'type' => 'integer', 'required' => true,  'description' => 'New user ID'],
                    ['name' => 'email',      'type' => 'string',  'required' => false, 'description' => 'Registration email'],
                ],
            ],
            [
                'event_type'  => 'user_deleted',
                'category'    => self::CAT_AUTH,
                'industries'  => self::IND_ALL,
                'description' => 'A WordPress user account was deleted.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'wp_user_id', 'type' => 'integer', 'required' => true, 'description' => 'Deleted user ID'],
                ],
            ],
            [
                'event_type'  => 'user_profile_updated',
                'category'    => self::CAT_AUTH,
                'industries'  => self::IND_ALL,
                'description' => 'A WordPress user profile was updated.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'wp_user_id',   'type' => 'integer', 'required' => true,  'description' => 'User ID'],
                    ['name' => 'email',        'type' => 'string',  'required' => false, 'description' => 'Updated email'],
                    ['name' => 'display_name', 'type' => 'string',  'required' => false, 'description' => 'Updated display name'],
                ],
            ],
            [
                'event_type'  => 'user_role_changed',
                'category'    => self::CAT_AUTH,
                'industries'  => self::IND_ALL,
                'description' => 'A WordPress user role was changed.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'wp_user_id', 'type' => 'integer', 'required' => true, 'description' => 'User ID'],
                    ['name' => 'new_role',   'type' => 'string',  'required' => true, 'description' => 'New role slug'],
                    ['name' => 'old_roles',  'type' => 'array',   'required' => true, 'description' => 'Previous roles'],
                ],
            ],

            // ── Navigation ────────────────────────────────────────────────────
            [
                'event_type'  => 'page_view',
                'category'    => self::CAT_NAVIGATION,
                'industries'  => self::IND_ALL,
                'description' => 'A page or screen was viewed.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'url',       'type' => 'string',  'required' => true,  'description' => 'Full page URL'],
                    ['name' => 'title',     'type' => 'string',  'required' => false, 'description' => 'Page title'],
                    ['name' => 'referrer',  'type' => 'string',  'required' => false, 'description' => 'HTTP referrer URL'],
                    ['name' => 'post_id',   'type' => 'integer', 'required' => false, 'description' => 'WordPress post ID if applicable'],
                    ['name' => 'post_type', 'type' => 'string',  'required' => false, 'description' => 'WordPress post type'],
                ],
            ],
            [
                'event_type'  => 'site_search',
                'category'    => self::CAT_NAVIGATION,
                'industries'  => self::IND_ALL,
                'description' => 'A visitor performed a site search.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'search_term',   'type' => 'string',  'required' => true,  'description' => 'Search query'],
                    ['name' => 'results_count', 'type' => 'integer', 'required' => false, 'description' => 'Number of results returned'],
                ],
            ],
            [
                'event_type'  => 'link_clicked',
                'category'    => self::CAT_NAVIGATION,
                'industries'  => self::IND_ALL,
                'description' => 'A visitor clicked a hyperlink.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'href',        'type' => 'string',  'required' => true,  'description' => 'Link destination URL'],
                    ['name' => 'text',        'type' => 'string',  'required' => false, 'description' => 'Link text'],
                    ['name' => 'is_external', 'type' => 'boolean', 'required' => false, 'description' => 'True if link leaves the site'],
                    ['name' => 'element_id',  'type' => 'string',  'required' => false, 'description' => 'HTML element ID'],
                ],
            ],
            [
                'event_type'  => 'button_clicked',
                'category'    => self::CAT_NAVIGATION,
                'industries'  => self::IND_ALL,
                'description' => 'A visitor clicked a button element.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'element_id',   'type' => 'string', 'required' => false, 'description' => 'HTML element ID'],
                    ['name' => 'element_text', 'type' => 'string', 'required' => false, 'description' => 'Button label'],
                    ['name' => 'element_class','type' => 'string', 'required' => false, 'description' => 'CSS classes'],
                ],
            ],

            // ── Engagement ────────────────────────────────────────────────────
            [
                'event_type'  => 'scroll_depth',
                'category'    => self::CAT_ENGAGEMENT,
                'industries'  => self::IND_ALL,
                'description' => 'Visitor scrolled to a depth milestone (25/50/75/100%).',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'depth_pct', 'type' => 'integer', 'required' => true, 'description' => 'Percentage scrolled (25, 50, 75, 100)'],
                    ['name' => 'url',       'type' => 'string',  'required' => true, 'description' => 'Page URL'],
                ],
            ],
            [
                'event_type'  => 'time_on_page',
                'category'    => self::CAT_ENGAGEMENT,
                'industries'  => self::IND_ALL,
                'description' => 'Time spent on a page recorded at session end or tab visibility change.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'seconds', 'type' => 'integer', 'required' => true,  'description' => 'Seconds spent on page'],
                    ['name' => 'url',     'type' => 'string',  'required' => true,  'description' => 'Page URL'],
                    ['name' => 'engaged', 'type' => 'boolean', 'required' => false, 'description' => 'True if time >= 30 seconds'],
                ],
            ],
            [
                'event_type'  => 'comment_submitted',
                'category'    => self::CAT_ENGAGEMENT,
                'industries'  => self::IND_ALL,
                'description' => 'A WordPress comment was submitted.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'comment_id', 'type' => 'integer', 'required' => true, 'description' => 'Comment ID'],
                    ['name' => 'post_id',    'type' => 'integer', 'required' => true, 'description' => 'Post the comment was left on'],
                    ['name' => 'approved',   'type' => 'integer', 'required' => true, 'description' => '1=approved, 0=pending, spam'],
                ],
            ],
            [
                'event_type'  => 'video_played',
                'category'    => self::CAT_ENGAGEMENT,
                'industries'  => self::IND_ALL,
                'description' => 'A video player was started.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'video_url',    'type' => 'string',  'required' => false, 'description' => 'Video source URL'],
                    ['name' => 'video_title',  'type' => 'string',  'required' => false, 'description' => 'Video title'],
                    ['name' => 'video_id',     'type' => 'string',  'required' => false, 'description' => 'Embed video ID (YouTube, Vimeo)'],
                    ['name' => 'current_time', 'type' => 'number',  'required' => false, 'description' => 'Playback position in seconds'],
                ],
            ],
            [
                'event_type'  => 'video_completed',
                'category'    => self::CAT_ENGAGEMENT,
                'industries'  => self::IND_ALL,
                'description' => 'A video was watched to completion (>= 90%).',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'video_id',    'type' => 'string', 'required' => false, 'description' => 'Embed video ID'],
                    ['name' => 'duration_s',  'type' => 'number', 'required' => false, 'description' => 'Total video duration in seconds'],
                ],
            ],

            // ── Content ───────────────────────────────────────────────────────
            [
                'event_type'  => 'content_published',
                'category'    => self::CAT_CONTENT,
                'industries'  => self::IND_ALL,
                'description' => 'A post was published.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'post_id',    'type' => 'integer', 'required' => true,  'description' => 'Post ID'],
                    ['name' => 'post_type',  'type' => 'string',  'required' => true,  'description' => 'WP post type'],
                    ['name' => 'title',      'type' => 'string',  'required' => false, 'description' => 'Post title'],
                    ['name' => 'author_id',  'type' => 'integer', 'required' => false, 'description' => 'Author user ID'],
                    ['name' => 'categories', 'type' => 'array',   'required' => false, 'description' => 'Category names'],
                ],
            ],
            [
                'event_type'  => 'page_published',
                'category'    => self::CAT_CONTENT,
                'industries'  => self::IND_ALL,
                'description' => 'A page was published.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'post_id',   'type' => 'integer', 'required' => true,  'description' => 'Page ID'],
                    ['name' => 'title',     'type' => 'string',  'required' => false, 'description' => 'Page title'],
                    ['name' => 'author_id', 'type' => 'integer', 'required' => false, 'description' => 'Author user ID'],
                ],
            ],
            [
                'event_type'  => 'content_saved',
                'category'    => self::CAT_CONTENT,
                'industries'  => self::IND_ALL,
                'description' => 'A post/page was saved (any status).',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'post_id',     'type' => 'integer', 'required' => true, 'description' => 'Post ID'],
                    ['name' => 'post_type',   'type' => 'string',  'required' => true, 'description' => 'WP post type'],
                    ['name' => 'post_status', 'type' => 'string',  'required' => true, 'description' => 'WP post status'],
                    ['name' => 'is_update',   'type' => 'boolean', 'required' => true, 'description' => 'True if updating existing'],
                ],
            ],
            [
                'event_type'  => 'content_trashed',
                'category'    => self::CAT_CONTENT,
                'industries'  => self::IND_ALL,
                'description' => 'A post/page was moved to trash.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'post_id',   'type' => 'integer', 'required' => true, 'description' => 'Post ID'],
                    ['name' => 'post_type', 'type' => 'string',  'required' => true, 'description' => 'WP post type'],
                ],
            ],

            // ── Forms ─────────────────────────────────────────────────────────
            [
                'event_type'  => 'form_started',
                'category'    => self::CAT_FORM,
                'industries'  => self::IND_ALL,
                'description' => 'A visitor started interacting with a form (first field focus).',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'form_id',   'type' => 'string', 'required' => false, 'description' => 'HTML form ID or data attribute'],
                    ['name' => 'form_name', 'type' => 'string', 'required' => false, 'description' => 'Form name or label'],
                ],
            ],
            [
                'event_type'  => 'form_submitted',
                'category'    => self::CAT_FORM,
                'industries'  => self::IND_ALL,
                'description' => 'A form was submitted.',
                'source'      => 'both',
                'properties'  => [
                    ['name' => 'form_id',   'type' => 'string',  'required' => false, 'description' => 'Form ID'],
                    ['name' => 'form_name', 'type' => 'string',  'required' => false, 'description' => 'Form name'],
                    ['name' => 'entry_id',  'type' => 'integer', 'required' => false, 'description' => 'Submission entry ID (plugin-specific)'],
                    ['name' => 'plugin',    'type' => 'string',  'required' => false, 'description' => 'Form plugin: contact_form_7|gravity_forms|wpforms|native'],
                ],
            ],
            [
                'event_type'  => 'form_abandoned',
                'category'    => self::CAT_FORM,
                'industries'  => self::IND_ALL,
                'description' => 'Visitor started but did not submit a form.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'form_id',         'type' => 'string',  'required' => false, 'description' => 'Form ID'],
                    ['name' => 'last_field',       'type' => 'string',  'required' => false, 'description' => 'Last field the visitor interacted with'],
                    ['name' => 'fields_completed', 'type' => 'integer', 'required' => false, 'description' => 'Number of fields completed'],
                ],
            ],

            // ── Media ─────────────────────────────────────────────────────────
            [
                'event_type'  => 'media_uploaded',
                'category'    => self::CAT_MEDIA,
                'industries'  => self::IND_ALL,
                'description' => 'A media file was uploaded.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'attachment_id', 'type' => 'integer', 'required' => true,  'description' => 'Attachment post ID'],
                    ['name' => 'mime_type',     'type' => 'string',  'required' => false, 'description' => 'MIME type of uploaded file'],
                ],
            ],
            [
                'event_type'  => 'media_deleted',
                'category'    => self::CAT_MEDIA,
                'industries'  => self::IND_ALL,
                'description' => 'A media file was deleted.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'attachment_id', 'type' => 'integer', 'required' => true, 'description' => 'Attachment post ID'],
                ],
            ],

            // ── eCommerce ─────────────────────────────────────────────────────
            [
                'event_type'  => 'product_viewed',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A visitor viewed a product detail page.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'product_id',   'type' => 'integer', 'required' => true,  'description' => 'WooCommerce product ID'],
                    ['name' => 'product_name', 'type' => 'string',  'required' => false, 'description' => 'Product name'],
                    ['name' => 'price',        'type' => 'number',  'required' => false, 'description' => 'Product price'],
                    ['name' => 'sku',          'type' => 'string',  'required' => false, 'description' => 'Product SKU'],
                    ['name' => 'category',     'type' => 'string',  'required' => false, 'description' => 'Primary product category'],
                ],
            ],
            [
                'event_type'  => 'product_added_to_cart',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A product was added to the WooCommerce cart.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'product_id',   'type' => 'integer', 'required' => true,  'description' => 'Product ID'],
                    ['name' => 'variation_id', 'type' => 'integer', 'required' => false, 'description' => 'Variation ID (0 if not variable)'],
                    ['name' => 'quantity',     'type' => 'integer', 'required' => true,  'description' => 'Quantity added'],
                    ['name' => 'product_name', 'type' => 'string',  'required' => false, 'description' => 'Product name'],
                    ['name' => 'price',        'type' => 'number',  'required' => false, 'description' => 'Unit price'],
                    ['name' => 'sku',          'type' => 'string',  'required' => false, 'description' => 'Product SKU'],
                ],
            ],
            [
                'event_type'  => 'product_removed_from_cart',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A product was removed from the cart.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'product_id', 'type' => 'integer', 'required' => true,  'description' => 'Product ID'],
                    ['name' => 'quantity',   'type' => 'integer', 'required' => false, 'description' => 'Quantity removed'],
                ],
            ],
            [
                'event_type'  => 'checkout_started',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A visitor began the checkout flow.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'cart_total',  'type' => 'number',  'required' => false, 'description' => 'Cart total value'],
                    ['name' => 'item_count',  'type' => 'integer', 'required' => false, 'description' => 'Number of cart items'],
                    ['name' => 'currency',    'type' => 'string',  'required' => false, 'description' => 'Currency code'],
                ],
            ],
            [
                'event_type'  => 'order_created',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A WooCommerce order was created.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'order_id',      'type' => 'integer', 'required' => true,  'description' => 'WC order ID'],
                    ['name' => 'order_number',  'type' => 'string',  'required' => false, 'description' => 'Human-readable order number'],
                    ['name' => 'total',         'type' => 'number',  'required' => true,  'description' => 'Order total'],
                    ['name' => 'currency',      'type' => 'string',  'required' => true,  'description' => 'Currency code'],
                    ['name' => 'payment_method','type' => 'string',  'required' => false, 'description' => 'Payment method slug'],
                    ['name' => 'items',         'type' => 'array',   'required' => false, 'description' => 'Array of {product_id, name, quantity, total}'],
                    ['name' => 'item_count',    'type' => 'integer', 'required' => false, 'description' => 'Number of line items'],
                ],
            ],
            [
                'event_type'  => 'order_payment_complete',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'Payment for a WooCommerce order was completed.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'order_id', 'type' => 'integer', 'required' => true,  'description' => 'WC order ID'],
                    ['name' => 'total',    'type' => 'number',  'required' => false, 'description' => 'Order total'],
                    ['name' => 'currency', 'type' => 'string',  'required' => false, 'description' => 'Currency code'],
                ],
            ],
            [
                'event_type'  => 'order_status_changed',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A WooCommerce order status was changed.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'order_id',   'type' => 'integer', 'required' => true, 'description' => 'WC order ID'],
                    ['name' => 'old_status', 'type' => 'string',  'required' => true, 'description' => 'Previous status'],
                    ['name' => 'new_status', 'type' => 'string',  'required' => true, 'description' => 'New status'],
                ],
            ],
            [
                'event_type'  => 'product_updated',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A WooCommerce product was updated.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'product_id',   'type' => 'integer', 'required' => true,  'description' => 'Product ID'],
                    ['name' => 'product_name', 'type' => 'string',  'required' => false, 'description' => 'Product name'],
                    ['name' => 'price',        'type' => 'number',  'required' => false, 'description' => 'Current price'],
                    ['name' => 'sku',          'type' => 'string',  'required' => false, 'description' => 'SKU'],
                    ['name' => 'stock_status', 'type' => 'string',  'required' => false, 'description' => 'in_stock|out_of_stock|on_backorder'],
                ],
            ],
            [
                'event_type'  => 'product_stock_changed',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A WooCommerce product stock status changed.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'product_id',   'type' => 'integer', 'required' => true,  'description' => 'Product ID'],
                    ['name' => 'stock_status', 'type' => 'string',  'required' => true,  'description' => 'New stock status'],
                    ['name' => 'stock_qty',    'type' => 'integer', 'required' => false, 'description' => 'Current stock quantity'],
                ],
            ],
            [
                'event_type'  => 'wishlist_add',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A product was added to a wishlist.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'product_id', 'type' => 'integer', 'required' => true, 'description' => 'Product ID'],
                ],
            ],
            [
                'event_type'  => 'coupon_applied',
                'category'    => self::CAT_ECOMMERCE,
                'industries'  => self::IND_ECOMMERCE,
                'description' => 'A coupon code was applied at checkout.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'coupon_code',    'type' => 'string', 'required' => true,  'description' => 'Applied coupon code'],
                    ['name' => 'discount_value', 'type' => 'number', 'required' => false, 'description' => 'Discount amount'],
                ],
            ],

            // ── Performance ───────────────────────────────────────────────────
            [
                'event_type'  => 'web_vitals',
                'category'    => self::CAT_PERFORMANCE,
                'industries'  => self::IND_ALL,
                'description' => 'Core Web Vitals captured from the browser.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'lcp_ms',    'type' => 'integer', 'required' => false, 'description' => 'Largest Contentful Paint in ms'],
                    ['name' => 'fid_ms',    'type' => 'integer', 'required' => false, 'description' => 'First Input Delay in ms'],
                    ['name' => 'cls_score', 'type' => 'number',  'required' => false, 'description' => 'Cumulative Layout Shift score'],
                    ['name' => 'ttfb_ms',   'type' => 'integer', 'required' => false, 'description' => 'Time to First Byte in ms'],
                    ['name' => 'inp_ms',    'type' => 'integer', 'required' => false, 'description' => 'Interaction to Next Paint in ms'],
                    ['name' => 'url',       'type' => 'string',  'required' => true,  'description' => 'Page URL where vitals were measured'],
                ],
            ],

            // ── Errors ────────────────────────────────────────────────────────
            [
                'event_type'  => 'js_error',
                'category'    => self::CAT_ERROR,
                'industries'  => self::IND_ALL,
                'description' => 'An unhandled JavaScript error occurred in the browser.',
                'source'      => 'browser',
                'properties'  => [
                    ['name' => 'message',  'type' => 'string',  'required' => true,  'description' => 'Error message'],
                    ['name' => 'filename', 'type' => 'string',  'required' => false, 'description' => 'Script filename'],
                    ['name' => 'lineno',   'type' => 'integer', 'required' => false, 'description' => 'Line number'],
                    ['name' => 'colno',    'type' => 'integer', 'required' => false, 'description' => 'Column number'],
                    ['name' => 'stack',    'type' => 'string',  'required' => false, 'description' => 'Error stack trace (truncated)'],
                    ['name' => 'url',      'type' => 'string',  'required' => true,  'description' => 'Page URL where error occurred'],
                ],
            ],

            // ── AGI operations ────────────────────────────────────────────────
            [
                'event_type'  => 'agi_agent_started',
                'category'    => self::CAT_AGI,
                'industries'  => self::IND_ALL,
                'description' => 'An AGI agent was deployed and started.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'agent_id',   'type' => 'string', 'required' => true,  'description' => 'Agent unique ID'],
                    ['name' => 'agent_type', 'type' => 'string', 'required' => false, 'description' => 'Agent type slug'],
                ],
            ],
            [
                'event_type'  => 'agi_agent_completed',
                'category'    => self::CAT_AGI,
                'industries'  => self::IND_ALL,
                'description' => 'An AGI agent completed its task.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'agent_id', 'type' => 'string', 'required' => true, 'description' => 'Agent unique ID'],
                    ['name' => 'status',   'type' => 'string', 'required' => true, 'description' => 'Completion status'],
                ],
            ],
            [
                'event_type'  => 'agi_goal_executed',
                'category'    => self::CAT_AGI,
                'industries'  => self::IND_ALL,
                'description' => 'An AGI goal was executed.',
                'source'      => 'server',
                'properties'  => [
                    ['name' => 'goal_id', 'type' => 'string', 'required' => true,  'description' => 'Goal ID'],
                    ['name' => 'result',  'type' => 'string', 'required' => false, 'description' => 'Execution result summary'],
                ],
            ],

            // ── Custom (pass-through for AGI or application-defined events) ──
            [
                'event_type'  => 'custom',
                'category'    => self::CAT_SYSTEM,
                'industries'  => self::IND_ALL,
                'description' => 'Application-defined custom event. Properties are free-form.',
                'source'      => 'both',
                'properties'  => [
                    ['name' => 'name',       'type' => 'string', 'required' => true,  'description' => 'Custom event name'],
                    ['name' => 'attributes', 'type' => 'object', 'required' => false, 'description' => 'Free-form key-value attributes'],
                ],
            ],
        ];
    }

    /**
     * Return the schema as a map keyed by event_type for fast lookup.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function map(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = [];
        foreach (self::all() as $entry) {
            $cached[(string) $entry['event_type']] = $entry;
        }
        return $cached;
    }

    /**
     * Check if an event_type is registered in the schema.
     */
    public static function is_known(string $event_type): bool {
        return isset(self::map()[$event_type]);
    }

    /**
     * Return all event types for a given category.
     *
     * @return list<string>
     */
    public static function types_for_category(string $category): array {
        return array_values(array_map(
            fn($e) => (string) $e['event_type'],
            array_filter(self::all(), fn($e) => $e['category'] === $category)
        ));
    }

    /**
     * Return all event types for a given industry.
     *
     * @return list<string>
     */
    public static function types_for_industry(string $industry): array {
        return array_values(array_map(
            fn($e) => (string) $e['event_type'],
            array_filter(self::all(), fn($e) => in_array($industry, (array) $e['industries'], true))
        ));
    }
}
