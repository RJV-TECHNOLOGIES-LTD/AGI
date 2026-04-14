<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;

/**
 * API Schema / Introspection Controller
 *
 * Exposes a machine-readable catalogue of every route registered under the
 * rjv-agi/v1 namespace.  Designed for LLM callers that need to discover
 * available operations without relying on out-of-band documentation.
 *
 * Route: GET /wp-json/rjv-agi/v1/schema
 *
 * Response shape:
 * {
 *   "success": true,
 *   "data": {
 *     "namespace": "rjv-agi/v1",
 *     "base_url":  "https://site.example/wp-json/rjv-agi/v1",
 *     "auth": { ... },
 *     "routes": [
 *       {
 *         "route":       "/posts",
 *         "methods":     ["GET","POST"],
 *         "description": "...",
 *         "params":      { ... }
 *       },
 *       ...
 *     ]
 *   }
 * }
 */
class Schema extends Base {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/schema', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_schema'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
    }

    public function get_schema(\WP_REST_Request $r): \WP_REST_Response {
        $base_url = rest_url($this->namespace);

        return $this->success([
            'namespace' => $this->namespace,
            'base_url'  => $base_url,
            'auth'      => [
                'header'      => 'X-RJV-API-Key',
                'tiers'       => [
                    1 => 'Read-only operations (GET)',
                    2 => 'Write operations (POST, PUT, PATCH)',
                    3 => 'Destructive operations (DELETE) and key management',
                ],
                'note' => 'Pass your API key in the X-RJV-API-Key header. Obtain a key via GET /rjv-agi/v1/auth/keys (tier 3 required).',
            ],
            'conventions' => [
                'envelope'    => 'All responses are wrapped: { "success": bool, "data": ... }',
                'errors'      => 'Errors return WP_Error JSON with "code", "message", "data.status" and an optional "data.hint" for recovery guidance.',
                'pagination'  => 'List endpoints accept "page" (1-based) and "per_page" (max 100). Responses include "total", "pages", and RFC 5988 Link headers.',
                'links'       => 'Entity create/get responses include a "_links" block with "self", "view", "edit", and "delete" URLs.',
                'ai_meta'     => 'AI-powered endpoints include a "_ai_meta" block with "model", "provider", "tokens", "cost_usd", "latency_ms", and "cached".',
                'idempotency' => 'Send X-Idempotency-Key on POST/PUT requests to prevent duplicate processing on retry.',
                'approvals'   => 'Destructive or policy-guarded requests return HTTP 202 with "requires_approval": true. Re-submit with X-RJV-Approval-ID once approved.',
                'dry_run'     => 'Pass "dry_run": true in any POST/PUT body to validate the request without side-effects.',
            ],
            'routes'      => $this->route_catalogue($base_url),
        ]);
    }

    // -------------------------------------------------------------------------

    private function route_catalogue(string $base_url): array {
        // Fetch live-registered routes so the catalogue always reflects reality.
        $server     = rest_get_server();
        $registered = $server->get_routes($this->namespace);

        $descriptions = $this->descriptions();

        $catalogue = [];
        foreach ($registered as $route => $handlers) {
            // Strip the namespace prefix WP prepends internally.
            $path = '/' . ltrim(str_replace('/' . $this->namespace, '', $route), '/');
            if ($path === '/') {
                $path = '';
            }

            $methods = [];
            $args    = [];
            foreach ($handlers as $handler) {
                $raw_methods = is_array($handler['methods'])
                    ? array_keys($handler['methods'])
                    : explode(',', (string) $handler['methods']);
                foreach ($raw_methods as $m) {
                    $methods[] = strtoupper(trim($m));
                }
                if (!empty($handler['args'])) {
                    foreach ($handler['args'] as $name => $schema) {
                        $args[$name] = [
                            'type'        => $schema['type']        ?? 'string',
                            'required'    => $schema['required']     ?? false,
                            'description' => $schema['description']  ?? '',
                            'default'     => $schema['default']      ?? null,
                        ];
                    }
                }
            }

            $methods = array_values(array_unique($methods));
            sort($methods);

            $desc_key = rtrim($path, '/');
            $entry = [
                'route'       => $base_url . $path,
                'path'        => $path,
                'methods'     => $methods,
                'description' => $descriptions[$desc_key] ?? '',
            ];

            if ($args) {
                $entry['params'] = $args;
            }

            $catalogue[] = $entry;
        }

        usort($catalogue, fn($a, $b) => strcmp($a['path'], $b['path']));

        return $catalogue;
    }

    /**
     * Hand-written descriptions for each route path (keyed without trailing slash).
     * Paths that match WordPress regex patterns use their literal string form.
     */
    private function descriptions(): array {
        return [
            // Content
            '/posts'                                        => 'List (GET) or create (POST) blog posts.',
            '/posts/(?P<id>\d+)'                            => 'Get, update, or delete a single post by ID.',
            '/posts/bulk'                                   => 'Apply an action (publish/draft/trash/delete) to multiple post IDs in one request.',
            '/posts/(?P<id>\d+)/revisions'                  => 'List revisions for a post.',
            '/posts/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/restore' => 'Restore a post to a specific revision.',
            '/posts/scheduled'                              => 'List all scheduled (future) posts.',
            '/posts/(?P<id>\d+)/schedule'                   => 'Reschedule a post to a new date.',
            '/posts/(?P<id>\d+)/schedule/cancel'            => 'Cancel a scheduled post (moves to draft).',
            '/posts/(?P<id>\d+)/publish-now'                => 'Immediately publish a post.',
            '/pages'                                        => 'List (GET) or create (POST) pages.',
            '/pages/(?P<id>\d+)'                            => 'Get, update, or delete a single page by ID.',
            '/pages/(?P<id>\d+)/revisions'                  => 'List revisions for a page.',
            '/pages/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/restore' => 'Restore a page to a specific revision.',
            '/pages/scheduled'                              => 'List scheduled pages.',
            '/pages/(?P<id>\d+)/schedule'                   => 'Reschedule a page.',
            '/pages/(?P<id>\d+)/schedule/cancel'            => 'Cancel a scheduled page.',
            '/pages/(?P<id>\d+)/publish-now'                => 'Immediately publish a page.',
            '/media'                                        => 'List (GET) or upload (POST) media attachments.',
            '/media/(?P<id>\d+)'                            => 'Get, update metadata, or delete a media item.',
            '/media/(?P<id>\d+)/optimize'                   => 'Trigger image optimisation for a media item.',
            '/comments'                                     => 'List (GET) or create (POST) comments.',
            '/comments/(?P<id>\d+)'                         => 'Get, update, or delete a single comment.',
            '/taxonomies'                                   => 'List registered taxonomies.',
            '/taxonomies/(?P<tax>[a-z_-]+)/terms'           => 'List terms for a taxonomy.',
            '/taxonomies/(?P<tax>[a-z_-]+)/terms/(?P<id>\d+)' => 'Get, update, or delete a single term.',
            '/menus'                                        => 'List navigation menus.',
            '/menus/(?P<id>\d+)'                            => 'Get items in a navigation menu.',
            '/widgets'                                      => 'List registered widget areas.',
            '/widgets/(?P<id>[a-zA-Z0-9_-]+)'              => 'Get or update widgets in a sidebar.',

            // Users
            '/users'                                        => 'List (GET) or create (POST) users.',
            '/users/(?P<id>\d+)'                            => 'Get, update, or delete a user.',
            '/users/(?P<id>\d+)/role-transition'            => 'Change a user\'s role (requires approval).',
            '/users/me'                                     => 'Get the currently authenticated user.',

            // Options / Settings
            '/options'                                      => 'Read or write WordPress site options.',
            '/options/(?P<key>[a-zA-Z0-9_-]+)'             => 'Get or set a single option by key.',

            // Themes & Plugins
            '/themes'                                       => 'List installed themes.',
            '/themes/activate'                              => 'Activate a theme.',
            '/plugins'                                      => 'List installed plugins.',
            '/plugins/(?P<slug>[a-z0-9_-]+)'               => 'Activate, deactivate, or delete a plugin.',

            // AI Content Generation
            '/content/complete'                             => 'Free-form AI completion (system + user prompt). Returns content with _ai_meta.',
            '/content/generate'                             => 'Generate a full blog post from a topic. Publishes to WordPress.',
            '/content/generate/outline'                     => 'Generate a structured outline for a topic.',
            '/content/generate/bulk'                        => 'Generate multiple posts from a topic list.',
            '/content/generate/seo'                         => 'Generate SEO title and meta description for a post.',
            '/content/rewrite'                              => 'Rewrite existing content with a target style or tone.',
            '/content/translate'                            => 'Translate post content to a target language.',
            '/content/generate/faq'                         => 'Generate an FAQ section for a topic.',
            '/content/generate/excerpt'                     => 'Generate a post excerpt.',

            // SEO
            '/seo'                                          => 'List SEO metadata for all posts.',
            '/seo/(?P<id>\d+)'                              => 'Get or update SEO metadata for a post.',
            '/seo/analyze'                                  => 'Analyse on-page SEO for a post.',
            '/seo/schema'                                   => 'Get or update structured data (Schema.org) for a post.',
            '/seo/sitemap'                                  => 'Get or regenerate the XML sitemap.',
            '/seo/bulk-update'                              => 'Update SEO metadata for multiple posts.',
            '/seo/link-suggestions'                         => 'Get AI-generated internal linking suggestions.',

            // Site Health & Info
            '/site-health'                                  => 'Run WordPress site health checks.',
            '/site-health/info'                             => 'Return structured site environment information.',
            '/sites'                                        => 'List sites in a multisite network.',
            '/sites/(?P<id>\d+)'                            => 'Get details about a network site.',
            '/sites/(?P<id>\d+)/switch'                     => 'Switch active site context to a network site.',

            // Database
            '/db/tables'                                    => 'List all database tables with row counts.',
            '/db/tables/(?P<name>[a-zA-Z0-9_]+)'           => 'Get columns, indexes, and status of a single table.',
            '/db/query'                                     => 'Execute a read-only SELECT query (safety-checked).',
            '/db/optimize'                                  => 'Optimise database tables.',

            // File System
            '/files'                                        => 'List files in the uploads directory.',
            '/files/(?P<path>.+)'                           => 'Get, rename, or delete a file.',
            '/files/upload'                                 => 'Upload a file to the uploads directory.',

            // Cron
            '/cron'                                         => 'List scheduled cron events.',
            '/cron/(?P<hook>[a-zA-Z0-9_-]+)'               => 'Run, reschedule, or remove a cron hook.',

            // Cache
            '/cache/flush'                                  => 'Flush the WordPress object cache.',
            '/cache/purge'                                  => 'Purge a page cache by URL.',
            '/cache/status'                                 => 'Report cache configuration and status.',

            // Tools
            '/tools'                                        => 'List available background tool jobs.',
            '/tools/(?P<id>[a-zA-Z0-9_-]+)'                => 'Dispatch or get the status of a background tool job.',

            // WooCommerce
            '/woocommerce/products'                         => 'List WooCommerce products.',
            '/woocommerce/products/(?P<id>\d+)'             => 'Get, update, or delete a product.',
            '/woocommerce/orders'                           => 'List WooCommerce orders.',
            '/woocommerce/orders/(?P<id>\d+)'               => 'Get or update an order.',

            // Forms
            '/forms'                                        => 'List installed contact forms.',
            '/forms/(?P<id>[a-zA-Z0-9_-]+)/entries'        => 'List form submission entries.',

            // ACF
            '/acf/field-groups'                             => 'List ACF field groups.',
            '/acf/field-groups/(?P<key>[a-z0-9_]+)'        => 'Get, create, update, or delete an ACF field group.',
            '/acf/fields'                                   => 'List ACF fields.',
            '/acf/fields/(?P<key>[a-z0-9_]+)'              => 'Get, update, or delete an ACF field.',
            '/acf/posts/(?P<id>\d+)/fields'                 => 'Get or set ACF field values for a post.',

            // Email Marketing
            '/email-marketing/providers'                    => 'List configured email marketing providers.',
            '/email-marketing/lists'                        => 'List email subscriber lists.',
            '/email-marketing/subscribe'                    => 'Subscribe an email address to a list.',

            // Versions & Approvals
            '/versions/(?P<type>[a-z]+)/(?P<id>\d+)'       => 'List content versions for a resource.',
            '/versions/(?P<version_id>\d+)/revert'         => 'Revert a resource to a previous version.',
            '/versions/compare'                             => 'Diff two versions of a resource.',
            '/approvals'                                    => 'List pending approval requests.',
            '/approvals/(?P<id>\d+)/approve'                => 'Approve a queued action.',
            '/approvals/(?P<id>\d+)/reject'                 => 'Reject a queued action.',

            // Goals & Agents
            '/goals/execute'                                => 'Execute a multi-step goal sequence.',
            '/goals/active'                                 => 'List currently running goal executions.',
            '/agents'                                       => 'List (GET) or deploy (POST) AGI agents.',
            '/agents/(?P<agent_id>[a-zA-Z0-9_-]+)'         => 'Get or stop a specific agent.',
            '/agents/(?P<agent_id>[a-zA-Z0-9_-]+)/execute' => 'Submit a task to a running agent.',

            // Security & Threats
            '/security/scan'                                => 'Trigger an on-demand security scan.',
            '/security/status'                              => 'Return the last security scan result.',
            '/security/threats'                             => 'List threat statistics and currently banned IPs.',
            '/security/threats/bans/(?P<ip>[^/]+)'         => 'Unban an IP address.',

            // Performance
            '/performance/analyze'                          => 'Analyse WordPress performance and identify bottlenecks.',
            '/performance/optimize'                         => 'Run database and transient optimisations.',

            // Observability
            '/observability/burn-rate'                      => 'Return the current SLO error-budget burn rate.',
            '/metrics'                                      => 'Return Prometheus-format metrics text.',

            // Platform / Auth
            '/platform/status'                              => 'Return platform connectivity and subscription status.',
            '/platform/capabilities'                        => 'Return available capabilities and usage counters.',
            '/auth/keys'                                    => 'List (GET) or issue (POST) named API keys.',
            '/auth/keys/(?P<id>[a-zA-Z0-9_-]+)'            => 'Revoke a named API key.',

            // Audit
            '/audit-log/verify-chain'                       => 'Verify the HMAC integrity chain of the audit log.',
            '/audit-log/export-jsonl'                       => 'Export audit log entries as JSONL.',

            // Design System
            '/design/tokens'                                => 'Get (GET) or update (PUT) design system tokens.',
            '/design/validate-css'                          => 'Validate CSS value against the design system.',

            // Integrations & Webhooks
            '/integrations'                                 => 'List (GET) or register (POST) third-party integrations.',
            '/webhooks'                                     => 'List (GET) or create (POST) outbound webhooks.',
            '/webhooks/(?P<webhook_id>[a-zA-Z0-9_-]+)/deliveries' => 'List delivery attempts for a webhook.',

            // Hosting
            '/hosting/status'                               => 'Return Cloudflare tunnel status.',
            '/hosting/start'                                => 'Start the tunnel with auto-configuration.',
            '/hosting/start-named'                          => 'Start the tunnel with a named hostname.',
            '/hosting/stop'                                 => 'Stop the tunnel.',
            '/hosting/log'                                  => 'Stream recent tunnel log lines.',
            '/hosting/apply-url'                            => 'Apply the tunnel URL to WordPress site settings.',
            '/hosting/revert-url'                           => 'Revert WordPress site URL to the original value.',
            '/hosting/download-binary'                      => 'Download and verify the cloudflared binary.',
            '/hosting/wizard'                               => 'Run the full hosting setup wizard.',

            // Cloudflare
            '/cloudflare/setup'                             => 'Validate Cloudflare credentials.',
            '/cloudflare/zones'                             => 'List Cloudflare zones.',
            '/cloudflare/accounts'                          => 'List Cloudflare accounts.',
            '/cloudflare/tunnels'                           => 'List Cloudflare tunnels.',

            // External Platforms
            '/external-platforms/google/auth-url'           => 'Get the Google OAuth authorisation URL.',
            '/external-platforms/google/exchange-code'      => 'Exchange a Google OAuth code for tokens.',
            '/external-platforms/google/setup'              => 'Save Google credentials.',
            '/external-platforms/google/ga4/properties'     => 'List GA4 properties.',
            '/external-platforms/google/ga4/report'         => 'Fetch a GA4 analytics report.',
            '/external-platforms/google/gsc/sites'          => 'List Google Search Console sites.',
            '/external-platforms/microsoft/auth-url'        => 'Get the Microsoft OAuth authorisation URL.',
            '/external-platforms/microsoft/exchange-code'   => 'Exchange a Microsoft OAuth code for tokens.',
            '/external-platforms/microsoft/setup'           => 'Save Microsoft credentials.',

            // Auto-Provision
            '/auto-provision/start'                         => 'Start a new site provisioning run.',
            '/auto-provision/status'                        => 'Return the status of the current provisioning run.',
            '/auto-provision/resume'                        => 'Resume a paused or failed provisioning run.',
            '/auto-provision/abort'                         => 'Abort the current provisioning run.',
            '/auto-provision/reset'                         => 'Reset the provisioning state.',
            '/auto-provision/monitor-status'                => 'Return provisioning monitor health.',
            '/auto-provision/monitor-reset'                 => 'Reset provisioning monitor counters.',

            // Enterprise Control
            '/enterprise/programs'                          => 'List (GET) or create (POST) program registry entries.',
            '/enterprise/programs/(?P<id>[a-zA-Z0-9_-]+)'  => 'Get, update, or delete a program entry.',
            '/enterprise/policy'                            => 'Get (GET) or update (PUT) the active policy ruleset.',
            '/enterprise/contract'                          => 'Get (GET) or update (PUT) the API contract.',
            '/enterprise/upgrade/check'                     => 'Run upgrade compatibility and safety checks.',
            '/enterprise/upgrade/history'                   => 'Return upgrade history.',
            '/enterprise/compliance'                        => 'Return compliance control status.',
            '/enterprise/execution/history'                 => 'List execution ledger entries.',
            '/enterprise/release-gates'                     => 'Return release gate pass/fail status.',

            // Schema (this endpoint)
            '/schema'                                       => 'Return this machine-readable API catalogue.',
        ];
    }
}
