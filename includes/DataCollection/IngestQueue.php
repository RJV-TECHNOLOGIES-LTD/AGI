<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\DataCollection;

use RJV_AGI_Bridge\AuditLog;

/**
 * Ingest Queue
 *
 * Asynchronous, fault-tolerant ingestion queue for data-collection payloads.
 * Items pushed here are persisted immediately and processed in batches by the
 * WordPress cron runner (rjv_agi_dc_queue_process hook).
 *
 * Retry model: up to MAX_ATTEMPTS attempts with exponential back-off.
 * After MAX_ATTEMPTS failures the item is moved to dead_letter status.
 *
 * The plugin captures and queues; the cron drains; the AGI reads the stored records.
 */
final class IngestQueue {

    private static ?self $instance = null;
    private string $table;

    public const MAX_ATTEMPTS = 3;

    /** Back-off delays in seconds for each attempt index (0-based). */
    private const RETRY_DELAYS = [60, 300, 1800]; // 1 min, 5 min, 30 min

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rjv_agi_dc_queue';
    }

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'rjv_agi_dc_queue';
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            queue_id      VARCHAR(36)   NOT NULL,
            payload_type  VARCHAR(60)   NOT NULL,
            payload       LONGTEXT      NOT NULL,
            status        ENUM('pending','processing','done','failed','dead_letter') NOT NULL DEFAULT 'pending',
            attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 3,
            error_message VARCHAR(500)   NULL,
            created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            process_after DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at  DATETIME       NULL,
            tenant_id     VARCHAR(100)   NOT NULL DEFAULT '',
            UNIQUE INDEX idx_queue_id      (queue_id),
            INDEX idx_status_process (status, process_after),
            INDEX idx_created        (created_at),
            INDEX idx_tenant         (tenant_id)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Push
    // -------------------------------------------------------------------------

    /**
     * Add a payload to the queue for asynchronous processing.
     *
     * @param string               $payload_type  One of: event, session, pageview, profile_update, batch
     * @param array<string,mixed>  $payload       The data to process.
     * @param int                  $delay_seconds Seconds before this item is eligible for processing (default 0).
     * @param string               $tenant_id
     *
     * Returns the queue_id on success, '' on failure.
     */
    public function push(string $payload_type, array $payload, int $delay_seconds = 0, string $tenant_id = ''): string {
        global $wpdb;

        $queue_id     = wp_generate_uuid4();
        $process_after = $delay_seconds > 0
            ? gmdate('Y-m-d H:i:s', time() + $delay_seconds)
            : current_time('mysql', true);

        $result = $wpdb->insert(
            $this->table,
            [
                'queue_id'      => $queue_id,
                'payload_type'  => sanitize_key($payload_type),
                'payload'       => wp_json_encode($payload) ?: '{}',
                'status'        => 'pending',
                'max_attempts'  => self::MAX_ATTEMPTS,
                'process_after' => $process_after,
                'tenant_id'     => sanitize_text_field($tenant_id),
            ],
            ['%s','%s','%s','%s','%d','%s','%s']
        );

        return $result !== false ? $queue_id : '';
    }

    // -------------------------------------------------------------------------
    // Process (called by cron)
    // -------------------------------------------------------------------------

    /**
     * Drain up to $limit pending items from the queue.
     *
     * Returns a summary array: [ 'processed' => int, 'failed' => int, 'dead_letter' => int ]
     */
    public function process_batch(int $limit = 100): array {
        global $wpdb;

        $limit = max(1, min($limit, 500));
        $now   = current_time('mysql', true);

        // Claim pending items atomically
        $items = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = 'pending' AND process_after <= %s
                 ORDER BY id ASC LIMIT %d",
                $now,
                $limit
            ),
            ARRAY_A
        );

        if (empty($items)) {
            return ['processed' => 0, 'failed' => 0, 'dead_letter' => 0];
        }

        $ids = array_column($items, 'id');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 'processing' WHERE id IN ({$id_placeholders})",
            $ids
        ));

        $processed   = 0;
        $failed      = 0;
        $dead_letter = 0;

        foreach ($items as $item) {
            $id      = (int) $item['id'];
            $attempts = (int) $item['attempt_count'] + 1;
            $max      = (int) ($item['max_attempts'] ?? self::MAX_ATTEMPTS);

            try {
                $payload = json_decode((string) ($item['payload'] ?? '{}'), true);
                if (!is_array($payload)) {
                    $payload = [];
                }
                $this->dispatch((string) $item['payload_type'], $payload);

                $wpdb->update(
                    $this->table,
                    ['status' => 'done', 'attempt_count' => $attempts, 'processed_at' => $now],
                    ['id' => $id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                if ($attempts >= $max) {
                    $wpdb->update(
                        $this->table,
                        [
                            'status'        => 'dead_letter',
                            'attempt_count' => $attempts,
                            'error_message' => substr($e->getMessage(), 0, 500),
                        ],
                        ['id' => $id],
                        ['%s', '%d', '%s'],
                        ['%d']
                    );
                    $dead_letter++;
                    AuditLog::log('dc_queue_dead_letter', 'data_collection', $id, [
                        'queue_id'     => $item['queue_id'] ?? '',
                        'payload_type' => $item['payload_type'] ?? '',
                        'error'        => substr($e->getMessage(), 0, 200),
                    ], 2);
                } else {
                    // Exponential back-off
                    $delay         = self::RETRY_DELAYS[min($attempts - 1, count(self::RETRY_DELAYS) - 1)];
                    $process_after = gmdate('Y-m-d H:i:s', time() + $delay);
                    $wpdb->update(
                        $this->table,
                        [
                            'status'        => 'pending',
                            'attempt_count' => $attempts,
                            'process_after' => $process_after,
                            'error_message' => substr($e->getMessage(), 0, 500),
                        ],
                        ['id' => $id],
                        ['%s', '%d', '%s', '%s'],
                        ['%d']
                    );
                }
            }
        }

        return [
            'processed'   => $processed,
            'failed'      => $failed,
            'dead_letter' => $dead_letter,
        ];
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    /**
     * Return queue health counters.
     *
     * @return array{pending: int, processing: int, done: int, failed: int, dead_letter: int, oldest_pending_seconds: int}
     */
    public function stats(): array {
        global $wpdb;

        $counts = (array) $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$this->table} GROUP BY status",
            ARRAY_A
        );

        $result = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'dead_letter' => 0];
        foreach ($counts as $row) {
            $key = str_replace(' ', '_', (string) ($row['status'] ?? ''));
            if (isset($result[$key])) {
                $result[$key] = (int) ($row['cnt'] ?? 0);
            }
        }

        // Age of the oldest pending item
        $oldest = $wpdb->get_var(
            "SELECT MIN(created_at) FROM {$this->table} WHERE status = 'pending'"
        );
        $result['oldest_pending_seconds'] = $oldest
            ? max(0, (int) (time() - strtotime((string) $oldest)))
            : 0;

        return $result;
    }

    /**
     * Purge completed queue items older than $days days.
     */
    public function purge_old(int $days = 7): int {
        global $wpdb;
        $cutoff  = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE status = 'done' AND processed_at < %s",
                $cutoff
            )
        );
        return $deleted !== false ? (int) $deleted : 0;
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Dispatch a single queue item to the appropriate store.
     *
     * @param array<string,mixed> $payload
     * @throws \RuntimeException on failure.
     */
    private function dispatch(string $payload_type, array $payload): void {
        switch ($payload_type) {
            case 'event':
                $id = EventStore::instance()->append($payload);
                if ($id === 0) {
                    throw new \RuntimeException('EventStore::append returned 0');
                }
                // Increment profile event counter
                $subject_id = (string) ($payload['subject_id'] ?? '');
                if ($subject_id !== '') {
                    ProfileStore::instance()->increment_counters($subject_id, ['event_count' => 1]);
                }
                break;

            case 'batch':
                $events = (array) ($payload['events'] ?? []);
                if (!empty($events)) {
                    $result = EventStore::instance()->batch_append($events);
                    if ($result < 0) {
                        throw new \RuntimeException('EventStore::batch_append failed');
                    }
                }
                break;

            case 'session':
                $action = (string) ($payload['action'] ?? 'start');
                $mgr    = SessionManager::instance();
                if ($action === 'start') {
                    $mgr->start($payload);
                } elseif ($action === 'touch') {
                    $mgr->touch((string) ($payload['session_id'] ?? ''), $payload);
                } elseif ($action === 'close') {
                    $mgr->close((string) ($payload['session_id'] ?? ''), (string) ($payload['exit_url'] ?? ''));
                }
                break;

            case 'pageview':
                $id = PageViewStore::instance()->record($payload);
                if ($id === 0) {
                    throw new \RuntimeException('PageViewStore::record returned 0');
                }
                $subject_id = (string) ($payload['subject_id'] ?? '');
                if ($subject_id !== '') {
                    ProfileStore::instance()->increment_counters($subject_id, ['page_view_count' => 1]);
                }
                break;

            case 'profile_update':
                $subject_id = (string) ($payload['subject_id'] ?? '');
                if ($subject_id === '') {
                    break;
                }
                ProfileStore::instance()->upsert($payload);
                break;

            default:
                // Unknown type — log and ignore rather than retrying
                AuditLog::log('dc_queue_unknown_type', 'data_collection', 0, ['type' => $payload_type], 1);
        }
    }
}
