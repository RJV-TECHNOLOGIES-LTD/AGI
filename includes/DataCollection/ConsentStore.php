<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

use RJV_AGI_Bridge\AuditLog;

/**
 * Consent Store
 *
 * Records, manages, and enforces data-subject consent according to GDPR,
 * CCPA, LGPD, PIPL, and COPPA requirements.
 *
 * Responsibilities
 * ────────────────
 * • Store per-purpose, per-regulation consent grants and withdrawals.
 * • Provide an authoritative consent lookup used by the EventCollector before
 *   any capture occurs (when consent_required is enabled).
 * • Execute GDPR Art. 17 erasure: delete all data for a subject across all
 *   data-collection tables.
 * • Produce GDPR Art. 20 portability export: all stored data for a subject.
 *
 * The plugin captures and stores consent decisions; the AGI reads and acts.
 */
final class ConsentStore {

    private static ?self $instance = null;
    private string $table;

    /** Recognised consent purposes (applies across all regulations). */
    public const PURPOSES = [
        'necessary',       // always required; cannot be denied
        'functional',      // session management, preferences
        'analytics',       // behavioural / statistical analysis
        'marketing',       // ads, retargeting, email campaigns
        'personalization', // content/experience customisation
        'data_sharing',    // sharing with third parties
    ];

    /** Supported regulations. */
    public const REGULATIONS = ['gdpr', 'ccpa', 'lgpd', 'pipl', 'coppa', 'other'];

    /** Valid consent statuses. */
    public const STATUSES = ['granted', 'denied', 'withdrawn', 'pending'];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rjv_agi_dc_consent';
    }

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'rjv_agi_dc_consent';
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            consent_id   VARCHAR(36)  NOT NULL,
            subject_id   VARCHAR(120) NOT NULL,
            subject_type ENUM('user','visitor') NOT NULL DEFAULT 'visitor',
            purpose      VARCHAR(60)  NOT NULL,
            status       ENUM('granted','denied','withdrawn','pending') NOT NULL DEFAULT 'pending',
            regulation   VARCHAR(20)  NOT NULL DEFAULT 'gdpr',
            granted_at   DATETIME     NULL,
            withdrawn_at DATETIME     NULL,
            expiry_at    DATETIME     NULL,
            ip_address   VARCHAR(45)  NOT NULL DEFAULT '',
            user_agent   VARCHAR(512) NOT NULL DEFAULT '',
            proof        LONGTEXT     NULL,
            tenant_id    VARCHAR(100) NOT NULL DEFAULT '',
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_consent_id (consent_id),
            INDEX idx_subject    (subject_id),
            INDEX idx_purpose    (purpose),
            INDEX idx_status     (status),
            INDEX idx_regulation (regulation),
            INDEX idx_tenant     (tenant_id)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Record a consent decision for a subject + purpose.
     *
     * If a record already exists for this subject/purpose/regulation it is
     * superseded (the old record is kept for the audit trail; a new row is inserted).
     *
     * @param array{
     *   subject_id:    string,
     *   subject_type?: string,
     *   purpose:       string,
     *   status:        string,
     *   regulation?:   string,
     *   ip_address?:   string,
     *   user_agent?:   string,
     *   proof?:        array<string,mixed>,
     *   expiry_days?:  int,
     *   tenant_id?:    string,
     * } $data
     *
     * Returns the new consent_id on success, '' on failure.
     */
    public function record(array $data): string {
        global $wpdb;

        $subject_id  = sanitize_text_field((string) ($data['subject_id'] ?? ''));
        $purpose     = sanitize_key((string) ($data['purpose'] ?? ''));
        $status      = $this->valid_status((string) ($data['status'] ?? 'pending'));
        $regulation  = $this->valid_regulation((string) ($data['regulation'] ?? 'gdpr'));

        if ($subject_id === '' || $purpose === '') {
            return '';
        }

        $now        = current_time('mysql', true);
        $consent_id = wp_generate_uuid4();

        $expiry_at = null;
        if (!empty($data['expiry_days']) && (int) $data['expiry_days'] > 0) {
            $expiry_at = gmdate('Y-m-d H:i:s', strtotime("+{$data['expiry_days']} days"));
        }

        $proof_json = isset($data['proof']) && is_array($data['proof'])
            ? (wp_json_encode($data['proof']) ?: null)
            : null;

        $row = [
            'consent_id'   => $consent_id,
            'subject_id'   => $subject_id,
            'subject_type' => $this->valid_subject_type((string) ($data['subject_type'] ?? 'visitor')),
            'purpose'      => $purpose,
            'status'       => $status,
            'regulation'   => $regulation,
            'granted_at'   => $status === 'granted' ? $now : null,
            'withdrawn_at' => $status === 'withdrawn' ? $now : null,
            'expiry_at'    => $expiry_at,
            'ip_address'   => $this->sanitize_ip((string) ($data['ip_address'] ?? '')),
            'user_agent'   => sanitize_text_field(substr((string) ($data['user_agent'] ?? ''), 0, 512)),
            'proof'        => $proof_json,
            'tenant_id'    => sanitize_text_field((string) ($data['tenant_id'] ?? '')),
        ];

        $fmt    = ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];
        $result = $wpdb->insert($this->table, $row, $fmt);
        if ($result === false) {
            return '';
        }

        AuditLog::log('dc_consent_recorded', 'consent', 0, [
            'subject_id' => $subject_id,
            'purpose'    => $purpose,
            'status'     => $status,
            'regulation' => $regulation,
        ], 2);

        return $consent_id;
    }

    /**
     * Withdraw consent for a specific purpose.
     * Inserts a new "withdrawn" row (non-destructive audit trail).
     */
    public function withdraw(string $subject_id, string $purpose, string $regulation = 'gdpr', string $tenant_id = ''): bool {
        $result = $this->record([
            'subject_id'  => $subject_id,
            'purpose'     => $purpose,
            'status'      => 'withdrawn',
            'regulation'  => $regulation,
            'tenant_id'   => $tenant_id,
        ]);
        return $result !== '';
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get the most recent consent record per purpose for a subject.
     *
     * Returns array keyed by purpose → latest consent row.
     *
     * @return array<string, array<string,mixed>>
     */
    public function get_for_subject(string $subject_id): array {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE subject_id = %s ORDER BY created_at DESC",
                $subject_id
            ),
            ARRAY_A
        );

        // Keep only the most recent row per purpose
        $latest = [];
        foreach ($rows as $row) {
            $p = (string) ($row['purpose'] ?? '');
            if (!isset($latest[$p])) {
                $latest[$p] = $this->decode_row($row);
            }
        }

        return $latest;
    }

    /**
     * Check if a subject has granted consent for a specific purpose.
     * Returns false if consent_required is enabled and no grant exists.
     */
    public function has_consent(string $subject_id, string $purpose): bool {
        global $wpdb;

        // "necessary" is always granted
        if ($purpose === 'necessary') {
            return true;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, expiry_at FROM {$this->table}
                 WHERE subject_id = %s AND purpose = %s
                 ORDER BY created_at DESC LIMIT 1",
                $subject_id,
                $purpose
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return false;
        }

        if ((string) ($row['status'] ?? '') !== 'granted') {
            return false;
        }

        // Check expiry
        if (!empty($row['expiry_at'])) {
            $expiry = strtotime((string) $row['expiry_at']);
            if ($expiry !== false && time() > $expiry) {
                return false;
            }
        }

        return true;
    }

    /**
     * Full audit trail for a subject (all consent rows).
     *
     * @return list<array<string,mixed>>
     */
    public function audit_trail(string $subject_id): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE subject_id = %s ORDER BY created_at ASC",
                $subject_id
            ),
            ARRAY_A
        );
        return array_map([$this, 'decode_row'], $rows);
    }

    // -------------------------------------------------------------------------
    // GDPR / Privacy Operations
    // -------------------------------------------------------------------------

    /**
     * GDPR Art. 17 — Right to Erasure ("Right to be Forgotten").
     *
     * Deletes ALL data-collection records for the subject across every table.
     * Consent records themselves are anonymised (subject_id replaced with a
     * one-way hash) to preserve the legal audit trail while removing PII.
     *
     * @return array{events: int, sessions: int, pageviews: int, profile: bool, consent_anonymised: int}
     */
    public function erase_subject(string $subject_id, string $tenant_id = ''): array {
        $anon_id = 'erased_' . hash('sha256', $subject_id . 'rjv-dc-erasure');

        // Delete from event, session, pageview, profile tables
        $events   = EventStore::instance()->delete_by_subject($subject_id);
        $sessions = SessionManager::instance()->delete_by_subject($subject_id);
        $pviews   = PageViewStore::instance()->delete_by_subject($subject_id);
        $profile  = ProfileStore::instance()->delete($subject_id);

        // Anonymise consent rows (preserve audit trail, remove PII)
        global $wpdb;
        $consent_updated = (int) $wpdb->update(
            $this->table,
            ['subject_id' => $anon_id],
            ['subject_id' => $subject_id],
            ['%s'],
            ['%s']
        );

        AuditLog::log('dc_subject_erased', 'data_collection', 0, [
            'subject_id'          => $subject_id,
            'tenant_id'           => $tenant_id,
            'events_deleted'      => $events,
            'sessions_deleted'    => $sessions,
            'pageviews_deleted'   => $pviews,
            'profile_deleted'     => $profile,
            'consent_anonymised'  => $consent_updated,
        ], 3);

        return [
            'events'              => $events,
            'sessions'            => $sessions,
            'pageviews'           => $pviews,
            'profile'             => $profile,
            'consent_anonymised'  => $consent_updated,
        ];
    }

    /**
     * GDPR Art. 20 — Right to Data Portability.
     *
     * Returns all stored data for a subject as a structured array.
     *
     * @return array{profile: array|null, events: list<array>, sessions: list<array>, pageviews: list<array>, consent: list<array>}
     */
    public function export_subject(string $subject_id): array {
        return [
            'profile'   => ProfileStore::instance()->export($subject_id),
            'events'    => EventStore::instance()->export_by_subject($subject_id),
            'sessions'  => SessionManager::instance()->export_by_subject($subject_id),
            'pageviews' => PageViewStore::instance()->export_by_subject($subject_id),
            'consent'   => $this->audit_trail($subject_id),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function valid_status(string $s): string {
        return in_array($s, self::STATUSES, true) ? $s : 'pending';
    }

    private function valid_regulation(string $r): string {
        return in_array($r, self::REGULATIONS, true) ? $r : 'other';
    }

    private function valid_subject_type(string $t): string {
        return in_array($t, ['user', 'visitor'], true) ? $t : 'visitor';
    }

    private function sanitize_ip(string $ip): string {
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Decode proof field from JSON.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode_row(array $row): array {
        if (isset($row['proof']) && is_string($row['proof']) && $row['proof'] !== '') {
            $decoded     = json_decode($row['proof'], true);
            $row['proof'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['proof'] = [];
        }
        return $row;
    }
}
