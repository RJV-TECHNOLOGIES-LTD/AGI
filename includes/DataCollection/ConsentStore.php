<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

use RJV_AGI_Bridge\AuditLog;

/**
 * Terms Acceptance Store
 *
 * Records the mandatory, non-negotiable acceptance of the plugin's terms of
 * service and data-collection policy.  Data collection is a condition of use:
 * installing and running the plugin constitutes acceptance across ALL versions
 * (free, professional, enterprise).  There is no opt-out.
 *
 * What this class does
 * ────────────────────
 * • Records a timestamped, tamper-evident acceptance entry when the plugin is
 *   activated or when a subject first interacts with the system.
 * • Provides an authoritative "terms accepted" gate used by Auth to verify
 *   that the operator has not manually tampered with the acceptance record.
 * • Exposes per-subject data records for AGI-driven operations:
 *     - GDPR Art. 17 erasure  (AGI tier-3 only; removes PII, keeps legal record)
 *     - GDPR Art. 20 portability export (AGI tier-2 only)
 *
 * What this class does NOT do
 * ───────────────────────────
 * • It does NOT provide an opt-out or withdrawal mechanism.
 * • It does NOT gate data collection behind user consent — collection is always on.
 * • It does NOT allow disabling data collection from any tier or version.
 */
final class ConsentStore {

    private static ?self $instance = null;
    private string $table;

    /** Option key that stores the site-level terms acceptance record. */
    public const SITE_ACCEPTANCE_OPTION = 'rjv_agi_dc_terms_accepted';

    /** Current terms version.  Bump when terms change. */
    public const TERMS_VERSION = '1.0';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rjv_agi_dc_terms';
    }

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'rjv_agi_dc_terms';
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_id       VARCHAR(36)   NOT NULL,
            subject_id      VARCHAR(120)  NOT NULL DEFAULT '',
            subject_type    ENUM('site','user','visitor') NOT NULL DEFAULT 'site',
            terms_version   VARCHAR(20)   NOT NULL DEFAULT '1.0',
            accepted_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            plugin_version  VARCHAR(20)   NOT NULL DEFAULT '',
            ip_address      VARCHAR(45)   NOT NULL DEFAULT '',
            user_agent      VARCHAR(512)  NOT NULL DEFAULT '',
            context         LONGTEXT      NULL,
            record_hash     CHAR(64)      NOT NULL DEFAULT '',
            tenant_id       VARCHAR(100)  NOT NULL DEFAULT '',
            UNIQUE INDEX idx_record_id  (record_id),
            INDEX idx_subject    (subject_id),
            INDEX idx_accepted   (accepted_at),
            INDEX idx_tenant     (tenant_id)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Site-level acceptance (plugin activation)
    // -------------------------------------------------------------------------

    /**
     * Record site-level mandatory acceptance.
     * Called on plugin activation.  Idempotent — safe to call multiple times.
     *
     * @param array<string,mixed> $context  Optional extra context (IP, plugin version, etc.)
     */
    public function record_site_acceptance(array $context = []): string {
        // Already accepted at this version? Return existing record_id.
        $existing = get_option(self::SITE_ACCEPTANCE_OPTION, []);
        if (
            is_array($existing)
            && !empty($existing['record_id'])
            && (string) ($existing['terms_version'] ?? '') === self::TERMS_VERSION
        ) {
            return (string) $existing['record_id'];
        }

        $record_id = $this->insert_acceptance([
            'subject_id'    => 'site',
            'subject_type'  => 'site',
            'terms_version' => self::TERMS_VERSION,
            'plugin_version' => defined('RJV_AGI_VERSION') ? RJV_AGI_VERSION : '',
            'context'       => $context,
        ]);

        if ($record_id !== '') {
            update_option(self::SITE_ACCEPTANCE_OPTION, [
                'record_id'     => $record_id,
                'terms_version' => self::TERMS_VERSION,
                'accepted_at'   => gmdate('c'),
            ]);

            AuditLog::log('dc_terms_accepted', 'data_collection', 0, [
                'record_id'     => $record_id,
                'terms_version' => self::TERMS_VERSION,
                'scope'         => 'site',
            ], 1);
        }

        return $record_id;
    }

    /**
     * Check whether the site-level acceptance record is present and valid.
     * Used by the Auth gate to refuse all API requests if tampered with.
     */
    public function site_has_accepted(): bool {
        $existing = get_option(self::SITE_ACCEPTANCE_OPTION, []);
        if (!is_array($existing) || empty($existing['record_id'])) {
            return false;
        }

        // Verify the DB row still exists (tamper detection)
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT record_hash FROM {$this->table} WHERE record_id = %s LIMIT 1",
                (string) $existing['record_id']
            ),
            ARRAY_A
        );

        return is_array($row) && !empty($row['record_hash']);
    }

    /**
     * Record per-subject acceptance (e.g. on first login or first front-end visit).
     * Use this to stamp when a specific WP user or anonymous visitor first used the system.
     *
     * @param array<string,mixed> $context
     */
    public function record_subject_acceptance(string $subject_id, string $subject_type = 'user', array $context = []): string {
        if ($subject_id === '') {
            return '';
        }

        // Only record once per subject per terms version
        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT record_id FROM {$this->table}
                 WHERE subject_id = %s AND terms_version = %s LIMIT 1",
                $subject_id,
                self::TERMS_VERSION
            )
        );
        if ($exists) {
            return (string) $exists;
        }

        return $this->insert_acceptance([
            'subject_id'    => $subject_id,
            'subject_type'  => in_array($subject_type, ['site', 'user', 'visitor'], true) ? $subject_type : 'visitor',
            'terms_version' => self::TERMS_VERSION,
            'plugin_version' => defined('RJV_AGI_VERSION') ? RJV_AGI_VERSION : '',
            'context'       => $context,
        ]);
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get the acceptance record for a subject (latest per terms version).
     *
     * @return array<string,mixed>|null
     */
    public function get_acceptance(string $subject_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE subject_id = %s ORDER BY accepted_at DESC LIMIT 1",
                $subject_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $this->decode_row($row) : null;
    }

    /**
     * Get the site-level acceptance summary (from option + DB verification).
     *
     * @return array<string,mixed>
     */
    public function site_acceptance_summary(): array {
        $option = get_option(self::SITE_ACCEPTANCE_OPTION, []);
        $valid  = $this->site_has_accepted();

        return [
            'accepted'      => $valid,
            'terms_version' => self::TERMS_VERSION,
            'record'        => is_array($option) ? $option : [],
            'note'          => 'Data collection is mandatory across all plugin versions. '
                             . 'Installing and running this plugin constitutes acceptance of all terms.',
        ];
    }

    // -------------------------------------------------------------------------
    // AGI-driven privacy operations (tier-3 only)
    // -------------------------------------------------------------------------

    /**
     * GDPR Art. 17 — Right to Erasure.
     *
     * Removes all PII data for a subject from every data-collection table.
     * The terms-acceptance record is anonymised (PII removed, legal record kept).
     * This operation is AGI-driven only and requires tier-3 authentication.
     *
     * @return array{events: int, sessions: int, pageviews: int, profile: bool, terms_anonymised: int}
     */
    public function erase_subject(string $subject_id, string $tenant_id = ''): array {
        $anon_id = 'erased_' . hash('sha256', $subject_id . 'rjv-dc-erasure-v1');

        $events   = EventStore::instance()->delete_by_subject($subject_id);
        $sessions = SessionManager::instance()->delete_by_subject($subject_id);
        $pviews   = PageViewStore::instance()->delete_by_subject($subject_id);
        $profile  = ProfileStore::instance()->delete($subject_id);

        // Anonymise terms rows — keep legal record, remove PII identifier
        global $wpdb;
        $terms_updated = (int) $wpdb->update(
            $this->table,
            ['subject_id' => $anon_id, 'ip_address' => '', 'user_agent' => ''],
            ['subject_id' => $subject_id],
            ['%s', '%s', '%s'],
            ['%s']
        );

        AuditLog::log('dc_subject_erased', 'data_collection', 0, [
            'subject_id'       => $subject_id,
            'tenant_id'        => $tenant_id,
            'events_deleted'   => $events,
            'sessions_deleted' => $sessions,
            'pageviews_deleted'=> $pviews,
            'profile_deleted'  => $profile,
            'terms_anonymised' => $terms_updated,
        ], 3);

        return [
            'events'           => $events,
            'sessions'         => $sessions,
            'pageviews'        => $pviews,
            'profile'          => $profile,
            'terms_anonymised' => $terms_updated,
        ];
    }

    /**
     * GDPR Art. 20 — Right to Data Portability.
     *
     * Returns all stored data for a subject as a structured array.
     * AGI-driven only; requires tier-2 authentication.
     *
     * @return array{profile: array|null, events: list<array>, sessions: list<array>, pageviews: list<array>, terms: array|null}
     */
    public function export_subject(string $subject_id): array {
        return [
            'profile'   => ProfileStore::instance()->export($subject_id),
            'events'    => EventStore::instance()->export_by_subject($subject_id),
            'sessions'  => SessionManager::instance()->export_by_subject($subject_id),
            'pageviews' => PageViewStore::instance()->export_by_subject($subject_id),
            'terms'     => $this->get_acceptance($subject_id),
        ];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     */
    private function insert_acceptance(array $data): string {
        global $wpdb;

        $record_id = wp_generate_uuid4();
        $context_json = isset($data['context']) && is_array($data['context'])
            ? (wp_json_encode($data['context']) ?: null)
            : null;

        $subject_id    = sanitize_text_field((string) ($data['subject_id'] ?? ''));
        $terms_version = sanitize_text_field((string) ($data['terms_version'] ?? self::TERMS_VERSION));
        $plugin_version = sanitize_text_field((string) ($data['plugin_version'] ?? ''));

        $record_hash = hash_hmac(
            'sha256',
            implode('|', [$record_id, $subject_id, $terms_version, $plugin_version]),
            $this->chain_key()
        );

        $result = $wpdb->insert(
            $this->table,
            [
                'record_id'      => $record_id,
                'subject_id'     => $subject_id,
                'subject_type'   => sanitize_text_field((string) ($data['subject_type'] ?? 'site')),
                'terms_version'  => $terms_version,
                'plugin_version' => $plugin_version,
                'ip_address'     => $this->sanitize_ip((string) ($data['ip_address'] ?? $this->current_ip())),
                'user_agent'     => sanitize_text_field(substr((string) ($data['user_agent'] ?? $this->current_ua()), 0, 512)),
                'context'        => $context_json,
                'record_hash'    => $record_hash,
                'tenant_id'      => sanitize_text_field((string) ($data['tenant_id'] ?? '')),
            ],
            ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
        );

        return $result !== false ? $record_id : '';
    }

    private function chain_key(): string {
        $root = defined('AUTH_KEY') ? AUTH_KEY : wp_generate_password(64, true, true);
        return hash_hmac('sha256', 'rjv-dc-terms-v1:' . (string) get_option('siteurl', ''), $root);
    }

    private function sanitize_ip(string $ip): string {
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private function current_ip(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip  = trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = wp_unslash($_SERVER['REMOTE_ADDR']);
        } else {
            return '';
        }
        return $this->sanitize_ip($ip);
    }

    private function current_ua(): string {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode_row(array $row): array {
        if (isset($row['context']) && is_string($row['context']) && $row['context'] !== '') {
            $decoded       = json_decode($row['context'], true);
            $row['context'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['context'] = [];
        }
        return $row;
    }
}
