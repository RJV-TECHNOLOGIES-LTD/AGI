<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;

use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AuditLog;
use RJV_AGI_Bridge\DataCollection\EventStore;
use RJV_AGI_Bridge\DataCollection\SessionManager;
use RJV_AGI_Bridge\DataCollection\ProfileStore;
use RJV_AGI_Bridge\DataCollection\PageViewStore;
use RJV_AGI_Bridge\DataCollection\ConsentStore;
use RJV_AGI_Bridge\DataCollection\IngestQueue;
use RJV_AGI_Bridge\DataCollection\Schema;

/**
 * Data Collection REST API
 *
 * The plugin captures data; the AGI reads and acts via this API.
 * All endpoints are authenticated with the existing X-RJV-AGI-Key mechanism.
 *
 * The /dc/collect endpoint additionally accepts a collector token (X-RJV-DC-Token)
 * for browser JS SDK ingestion so the main AGI key is never exposed client-side.
 *
 * Data collection is mandatory and always on — no endpoint can disable it.
 */
final class DataCollection extends Base {

    public function register_routes(): void {

        // ── Browser / server event ingest ────────────────────────────────────
        register_rest_route($this->namespace, '/dc/collect', [
            'methods'             => 'POST',
            'callback'            => [$this, 'collect'],
            'permission_callback' => [$this, 'collector_auth'],
        ]);

        register_rest_route($this->namespace, '/dc/events', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'ingest_event'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_events'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
        ]);

        register_rest_route($this->namespace, '/dc/events/batch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'ingest_batch'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // ── Sessions ─────────────────────────────────────────────────────────
        register_rest_route($this->namespace, '/dc/sessions', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_session'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_sessions'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
        ]);

        register_rest_route($this->namespace, '/dc/sessions/(?P<id>[a-zA-Z0-9\-]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_session'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'update_session'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
        ]);

        // ── Profiles ─────────────────────────────────────────────────────────
        register_rest_route($this->namespace, '/dc/profiles', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_profiles'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($this->namespace, '/dc/profiles/(?P<id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_profile'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        register_rest_route($this->namespace, '/dc/profiles/(?P<id>[a-zA-Z0-9_\-]+)/traits', [
            'methods'             => 'PUT',
            'callback'            => [$this, 'update_profile_traits'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        // ── Page views ───────────────────────────────────────────────────────
        register_rest_route($this->namespace, '/dc/pageviews', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'record_pageview'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_pageviews'],
                'permission_callback' => [Auth::class, 'tier1'],
            ],
        ]);

        // ── Terms acceptance (mandatory — AGI reads, never disables) ─────────
        register_rest_route($this->namespace, '/dc/terms', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_terms_status'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // ── AGI-driven subject operations (tier2/3) ───────────────────────────
        register_rest_route($this->namespace, '/dc/subjects/(?P<id>[a-zA-Z0-9_\-]+)/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'export_subject'],
            'permission_callback' => [Auth::class, 'tier2'],
        ]);

        register_rest_route($this->namespace, '/dc/subjects/(?P<id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'erase_subject'],
            'permission_callback' => [Auth::class, 'tier3'],
        ]);

        // ── Schema ───────────────────────────────────────────────────────────
        register_rest_route($this->namespace, '/dc/schema', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_schema'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);

        // ── Queue stats ───────────────────────────────────────────────────────
        register_rest_route($this->namespace, '/dc/queue/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'queue_stats'],
            'permission_callback' => [Auth::class, 'tier1'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Collector auth (browser JS SDK)
    // -------------------------------------------------------------------------

    /**
     * Accept requests that carry either the AGI API key OR the collector token.
     * The collector token is safe to expose client-side; it only allows writing
     * events, not reading or managing data.
     */
    public function collector_auth(\WP_REST_Request $request): bool {
        // Try standard AGI key first
        if (Auth::tier1($request)) {
            return true;
        }

        // Fall back to collector token
        $provided = sanitize_text_field(
            (string) ($request->get_header('X-RJV-DC-Token') ?? $request->get_param('_dc_token') ?? '')
        );
        $expected = (string) get_option('rjv_agi_dc_collector_token', '');

        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    // -------------------------------------------------------------------------
    // Browser / server event ingest
    // -------------------------------------------------------------------------

    /**
     * POST /dc/collect
     *
     * Accepts a single event OR an array of events from the browser JS SDK.
     * Supports both { event_type, ... } and { events: [...] } payloads.
     */
    public function collect(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $this->start_timer();
        $body = (array) $r->get_json_params();

        // Batch format
        if (isset($body['events']) && is_array($body['events'])) {
            return $this->handle_batch_ingest($body['events'], $r);
        }

        // Single event
        return $this->handle_single_ingest($body, $r);
    }

    /**
     * POST /dc/events
     * Single event ingest via AGI API key.
     */
    public function ingest_event(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $this->start_timer();
        $body = (array) $r->get_json_params();
        return $this->handle_single_ingest($body, $r);
    }

    /**
     * POST /dc/events/batch
     * Batch ingest up to 500 events.
     */
    public function ingest_batch(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $this->start_timer();
        $body   = (array) $r->get_json_params();
        $events = (array) ($body['events'] ?? $body);
        return $this->handle_batch_ingest($events, $r);
    }

    /**
     * GET /dc/events
     */
    public function list_events(\WP_REST_Request $r): \WP_REST_Response {
        $pagination = $this->parse_pagination($r, 1000);
        $filters    = array_merge($this->extract_event_filters($r), $pagination);
        $result     = EventStore::instance()->query($filters);

        $this->log('dc_events_listed', 'data_collection', 0, ['total' => $result['total']]);

        return $this->paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page'],
            rest_url("{$this->namespace}/dc/events")
        );
    }

    // -------------------------------------------------------------------------
    // Sessions
    // -------------------------------------------------------------------------

    /**
     * POST /dc/sessions
     */
    public function create_session(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $body       = (array) $r->get_json_params();
        $session_id = SessionManager::instance()->start($body);

        if ($session_id === '') {
            return $this->error('Failed to create session.', 500);
        }

        // Upsert profile if subject_id present
        $subject_id = sanitize_text_field((string) ($body['subject_id'] ?? ''));
        if ($subject_id !== '') {
            ProfileStore::instance()->upsert(array_merge($body, ['subject_id' => $subject_id]));
            ProfileStore::instance()->increment_counters($subject_id, ['session_count' => 1]);
        }

        $this->log('dc_session_created', 'data_collection', 0, ['session_id' => $session_id]);

        return $this->success(['session_id' => $session_id], 201);
    }

    /**
     * GET /dc/sessions
     */
    public function list_sessions(\WP_REST_Request $r): \WP_REST_Response {
        $pagination = $this->parse_pagination($r, 500);
        $filters    = array_merge([
            'subject_id' => sanitize_text_field((string) $r->get_param('subject_id')),
            'industry'   => sanitize_key((string) $r->get_param('industry')),
            'tenant_id'  => sanitize_text_field((string) $r->get_param('tenant_id')),
            'since'      => sanitize_text_field((string) $r->get_param('since')),
            'until'      => sanitize_text_field((string) $r->get_param('until')),
        ], $pagination);

        $result = SessionManager::instance()->list(array_filter($filters));

        return $this->paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page'],
            rest_url("{$this->namespace}/dc/sessions")
        );
    }

    /**
     * GET /dc/sessions/{id}
     */
    public function get_session(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $session = SessionManager::instance()->get(sanitize_text_field($r['id']));
        if (!$session) {
            return $this->not_found('session');
        }
        return $this->success($session);
    }

    /**
     * PATCH /dc/sessions/{id}
     * Updates exit_url, page_count, event_count.
     */
    public function update_session(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $session_id = sanitize_text_field($r['id']);
        $body       = (array) $r->get_json_params();
        $action     = sanitize_key((string) ($body['action'] ?? 'touch'));

        if ($action === 'close') {
            SessionManager::instance()->close($session_id, (string) ($body['exit_url'] ?? ''));
        } else {
            SessionManager::instance()->touch($session_id, $body);
        }

        return $this->success(['session_id' => $session_id, 'action' => $action]);
    }

    // -------------------------------------------------------------------------
    // Profiles
    // -------------------------------------------------------------------------

    /**
     * GET /dc/profiles
     */
    public function list_profiles(\WP_REST_Request $r): \WP_REST_Response {
        $pagination = $this->parse_pagination($r, 500);
        $filters    = array_merge([
            'industry'        => sanitize_key((string) $r->get_param('industry')),
            'lifecycle_stage' => sanitize_text_field((string) $r->get_param('lifecycle_stage')),
            'tenant_id'       => sanitize_text_field((string) $r->get_param('tenant_id')),
            'since'           => sanitize_text_field((string) $r->get_param('since')),
            'until'           => sanitize_text_field((string) $r->get_param('until')),
        ], $pagination);

        $result = ProfileStore::instance()->list(array_filter($filters));

        return $this->paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page'],
            rest_url("{$this->namespace}/dc/profiles")
        );
    }

    /**
     * GET /dc/profiles/{id}
     */
    public function get_profile(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $profile = ProfileStore::instance()->get(sanitize_text_field($r['id']));
        if (!$profile) {
            return $this->not_found('profile');
        }
        return $this->success($profile);
    }

    /**
     * PUT /dc/profiles/{id}/traits
     * The AGI writes computed traits back to a profile.
     */
    public function update_profile_traits(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $subject_id = sanitize_text_field($r['id']);
        $body       = (array) $r->get_json_params();
        $traits     = (array) ($body['traits'] ?? $body);

        if (empty($traits)) {
            return $this->error('traits must be a non-empty object.', 422);
        }

        $ok = ProfileStore::instance()->update_traits($subject_id, $traits);
        if (!$ok) {
            return $this->not_found('profile');
        }

        $this->log('dc_profile_traits_updated', 'data_collection', 0, ['subject_id' => $subject_id]);

        return $this->success(['subject_id' => $subject_id, 'updated' => true]);
    }

    // -------------------------------------------------------------------------
    // Page views
    // -------------------------------------------------------------------------

    /**
     * POST /dc/pageviews
     */
    public function record_pageview(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $body = (array) $r->get_json_params();
        $id   = PageViewStore::instance()->record($body);

        if ($id === 0) {
            return $this->error('Failed to record page view.', 500);
        }

        $subject_id = sanitize_text_field((string) ($body['subject_id'] ?? ''));
        if ($subject_id !== '') {
            ProfileStore::instance()->increment_counters($subject_id, ['page_view_count' => 1]);
        }

        return $this->success(['id' => $id], 201);
    }

    /**
     * GET /dc/pageviews
     */
    public function list_pageviews(\WP_REST_Request $r): \WP_REST_Response {
        $pagination = $this->parse_pagination($r, 1000);
        $filters    = array_merge([
            'session_id' => sanitize_text_field((string) $r->get_param('session_id')),
            'subject_id' => sanitize_text_field((string) $r->get_param('subject_id')),
            'post_id'    => (int) $r->get_param('post_id') ?: null,
            'url_path'   => sanitize_text_field((string) $r->get_param('url_path')),
            'tenant_id'  => sanitize_text_field((string) $r->get_param('tenant_id')),
            'since'      => sanitize_text_field((string) $r->get_param('since')),
            'until'      => sanitize_text_field((string) $r->get_param('until')),
        ], $pagination);

        $result = PageViewStore::instance()->query(array_filter($filters));

        return $this->paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page'],
            rest_url("{$this->namespace}/dc/pageviews")
        );
    }

    // -------------------------------------------------------------------------
    // Terms acceptance
    // -------------------------------------------------------------------------

    /**
     * GET /dc/terms
     * Returns site-level mandatory terms acceptance status.
     */
    public function get_terms_status(\WP_REST_Request $r): \WP_REST_Response {
        $summary = ConsentStore::instance()->site_acceptance_summary();
        return $this->success($summary);
    }

    // -------------------------------------------------------------------------
    // AGI subject operations
    // -------------------------------------------------------------------------

    /**
     * GET /dc/subjects/{id}/export
     * Returns all stored data for a subject (data portability).
     */
    public function export_subject(\WP_REST_Request $r): \WP_REST_Response {
        $subject_id = sanitize_text_field($r['id']);
        $export     = ConsentStore::instance()->export_subject($subject_id);

        $this->log('dc_subject_exported', 'data_collection', 0, ['subject_id' => $subject_id], 2);

        return $this->success($export);
    }

    /**
     * DELETE /dc/subjects/{id}
     * Erases all PII data for a subject (tier-3 AGI operation only).
     */
    public function erase_subject(\WP_REST_Request $r): \WP_REST_Response {
        $subject_id = sanitize_text_field($r['id']);
        $result     = ConsentStore::instance()->erase_subject($subject_id);

        return $this->success($result);
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * GET /dc/schema
     * Returns the full event-type schema for AGI consumption.
     */
    public function get_schema(\WP_REST_Request $r): \WP_REST_Response {
        $category = sanitize_key((string) $r->get_param('category'));
        $industry = sanitize_key((string) $r->get_param('industry'));

        if ($category !== '') {
            $types = Schema::types_for_category($category);
            $schema = array_values(array_filter(Schema::all(), fn($e) => in_array($e['event_type'], $types, true)));
        } elseif ($industry !== '') {
            $types = Schema::types_for_industry($industry);
            $schema = array_values(array_filter(Schema::all(), fn($e) => in_array($e['event_type'], $types, true)));
        } else {
            $schema = Schema::all();
        }

        return $this->success([
            'version'     => Schema::VERSION,
            'total'       => count($schema),
            'event_types' => $schema,
        ]);
    }

    // -------------------------------------------------------------------------
    // Queue stats
    // -------------------------------------------------------------------------

    /**
     * GET /dc/queue/stats
     */
    public function queue_stats(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success(IngestQueue::instance()->stats());
    }

    // -------------------------------------------------------------------------
    // Shared ingest helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     */
    private function handle_single_ingest(array $data, \WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (empty($data['event_type'])) {
            return $this->error('event_type is required.', 422);
        }

        // Enrich with server-side context if not set
        $data = $this->enrich_with_request($data, $r);

        // Direct write (synchronous, no queue) for single events from AGI
        $id = EventStore::instance()->append($data);

        if ($id === 0) {
            return $this->error('Failed to store event.', 500);
        }

        // Increment subject counters
        $subject_id = sanitize_text_field((string) ($data['subject_id'] ?? ''));
        if ($subject_id !== '') {
            ProfileStore::instance()->increment_counters($subject_id, ['event_count' => 1]);
        }

        AuditLog::log('dc_event_ingested', 'data_collection', $id, [
            'event_type' => $data['event_type'],
            'subject_id' => $subject_id,
        ], 1);

        return $this->success(['id' => $id, 'latency_ms' => $this->elapsed_ms()], 201);
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function handle_batch_ingest(array $events, \WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if (empty($events)) {
            return $this->error('events array is required and must not be empty.', 422);
        }
        if (count($events) > EventStore::BATCH_LIMIT) {
            return $this->error(
                sprintf('Batch exceeds maximum of %d events.', EventStore::BATCH_LIMIT),
                422
            );
        }

        $events = array_map(fn($e) => $this->enrich_with_request((array) $e, $r), $events);

        $count = EventStore::instance()->batch_append($events);

        if ($count < 0) {
            return $this->error('Batch insert failed.', 500);
        }

        AuditLog::log('dc_batch_ingested', 'data_collection', 0, [
            'count' => $count,
        ], 1);

        return $this->success(['count' => $count, 'latency_ms' => $this->elapsed_ms()], 201);
    }

    /**
     * Merge server-side context into an event array if fields are missing.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function enrich_with_request(array $data, \WP_REST_Request $r): array {
        // IP from header stack if not provided
        if (empty($data['context']['ip'])) {
            $data['context']['ip'] = $this->request_ip();
        }
        // Tenant ID
        if (empty($data['tenant_id'])) {
            $data['tenant_id'] = sanitize_text_field((string) $r->get_header('X-Tenant-ID'));
        }
        return $data;
    }

    private function request_ip(): string {
        $candidates = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $ips = explode(',', wp_unslash($_SERVER[$key]));
            $ip  = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '';
    }

    /**
     * Extract event-query filters from a GET request.
     *
     * @return array<string,mixed>
     */
    private function extract_event_filters(\WP_REST_Request $r): array {
        return array_filter([
            'subject_id'     => sanitize_text_field((string) $r->get_param('subject_id')),
            'session_id'     => sanitize_text_field((string) $r->get_param('session_id')),
            'event_type'     => sanitize_text_field((string) $r->get_param('event_type')),
            'event_category' => sanitize_text_field((string) $r->get_param('category')),
            'industry'       => sanitize_key((string) $r->get_param('industry')),
            'tenant_id'      => sanitize_text_field((string) $r->get_param('tenant_id')),
            'since'          => sanitize_text_field((string) $r->get_param('since')),
            'until'          => sanitize_text_field((string) $r->get_param('until')),
        ]);
    }
}
