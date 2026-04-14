<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Execution;

/**
 * Append-only deterministic execution ledger with hash chaining.
 */
final class ExecutionLedger {
    private static ?self $instance = null;
    private string $table_name;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rjv_agi_execution_ledger';
    }

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rjv_agi_execution_ledger';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            execution_id VARCHAR(100) NOT NULL,
            record_type ENUM('execution','event') NOT NULL DEFAULT 'event',
            entity_type VARCHAR(50) NOT NULL,
            entity_id VARCHAR(120) NOT NULL,
            event_type VARCHAR(120) NOT NULL,
            sequence_no INT UNSIGNED NOT NULL DEFAULT 0,
            payload LONGTEXT NULL,
            result LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'recorded',
            trace_id VARCHAR(100) NULL,
            prev_hash CHAR(64) NOT NULL DEFAULT '',
            record_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_execution (execution_id),
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_created (created_at),
            INDEX idx_seq (execution_id, sequence_no)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function start_execution(string $entity_type, string $entity_id, array $payload = [], array $context = []): string {
        $execution_id = sanitize_key((string) ($context['execution_id'] ?? ('exec_' . wp_generate_uuid4())));
        if ($execution_id === '') {
            $execution_id = 'exec_' . wp_generate_uuid4();
        }

        $this->append_record([
            'execution_id' => $execution_id,
            'record_type' => 'execution',
            'entity_type' => sanitize_key($entity_type),
            'entity_id' => sanitize_text_field($entity_id),
            'event_type' => 'execution_started',
            'sequence_no' => 0,
            'payload' => $payload,
            'result' => null,
            'status' => 'running',
            'trace_id' => sanitize_text_field((string) ($context['trace_id'] ?? '')),
        ]);

        return $execution_id;
    }

    public function append_event(string $execution_id, string $event_type, array $payload = [], array $context = []): int {
        $last = $this->last_for_execution($execution_id);
        $seq = (int) ($last['sequence_no'] ?? 0) + 1;
        return $this->append_record([
            'execution_id' => sanitize_key($execution_id),
            'record_type' => 'event',
            'entity_type' => sanitize_key((string) ($context['entity_type'] ?? 'workflow')),
            'entity_id' => sanitize_text_field((string) ($context['entity_id'] ?? $execution_id)),
            'event_type' => sanitize_key($event_type),
            'sequence_no' => $seq,
            'payload' => $payload,
            'result' => null,
            'status' => sanitize_key((string) ($context['status'] ?? 'recorded')),
            'trace_id' => sanitize_text_field((string) ($context['trace_id'] ?? '')),
        ]);
    }

    public function complete_execution(string $execution_id, bool $success, array $result = [], array $context = []): int {
        $last = $this->last_for_execution($execution_id);
        $seq = (int) ($last['sequence_no'] ?? 0) + 1;
        return $this->append_record([
            'execution_id' => sanitize_key($execution_id),
            'record_type' => 'event',
            'entity_type' => sanitize_key((string) ($context['entity_type'] ?? 'workflow')),
            'entity_id' => sanitize_text_field((string) ($context['entity_id'] ?? $execution_id)),
            'event_type' => 'execution_completed',
            'sequence_no' => $seq,
            'payload' => ['success' => $success],
            'result' => $result,
            'status' => $success ? 'completed' : 'failed',
            'trace_id' => sanitize_text_field((string) ($context['trace_id'] ?? '')),
        ]);
    }

    public function replay_execution(string $execution_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE execution_id = %s ORDER BY sequence_no ASC, id ASC",
            $execution_id
        ), ARRAY_A) ?: [];

        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'sequence_no' => (int) $row['sequence_no'],
                'record_type' => $row['record_type'],
                'event_type' => $row['event_type'],
                'status' => $row['status'],
                'payload' => json_decode((string) $row['payload'], true),
                'result' => json_decode((string) $row['result'], true),
                'trace_id' => $row['trace_id'],
                'hash' => $row['record_hash'],
                'at' => $row['created_at'],
            ];
        }

        return [
            'execution_id' => $execution_id,
            'event_count' => count($events),
            'events' => $events,
            'chain_verified' => $this->verify_execution_chain($execution_id),
        ];
    }

    public function verify_execution_chain(string $execution_id): bool {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE execution_id = %s ORDER BY sequence_no ASC, id ASC",
            $execution_id
        ), ARRAY_A) ?: [];
        if (empty($rows)) {
            return true;
        }

        $previousHash = '';
        foreach ($rows as $row) {
            if ((string) $row['prev_hash'] !== $previousHash) {
                return false;
            }

            $expected = $this->compute_hash([
                'execution_id' => $row['execution_id'],
                'record_type' => $row['record_type'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'event_type' => $row['event_type'],
                'sequence_no' => (int) $row['sequence_no'],
                'payload' => (string) $row['payload'],
                'result' => (string) $row['result'],
                'status' => $row['status'],
                'trace_id' => $row['trace_id'],
                'created_at' => $row['created_at'],
                'prev_hash' => $row['prev_hash'],
            ]);
            if (!hash_equals((string) $row['record_hash'], $expected)) {
                return false;
            }
            $previousHash = (string) $row['record_hash'];
        }

        return true;
    }

    public function list_recent(int $limit = 50): array {
        global $wpdb;
        $limit = max(1, min($limit, 200));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT execution_id, entity_type, entity_id, MAX(created_at) as last_at, COUNT(*) as records
             FROM {$this->table_name}
             GROUP BY execution_id, entity_type, entity_id
             ORDER BY last_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    private function append_record(array $record): int {
        global $wpdb;
        $lastGlobal = $this->last_global_hash();
        $createdAt = current_time('mysql', true);

        $payloadJson = wp_json_encode($record['payload'] ?? null);
        $resultJson = wp_json_encode($record['result'] ?? null);
        $hash = $this->compute_hash([
            'execution_id' => $record['execution_id'],
            'record_type' => $record['record_type'],
            'entity_type' => $record['entity_type'],
            'entity_id' => $record['entity_id'],
            'event_type' => $record['event_type'],
            'sequence_no' => (int) $record['sequence_no'],
            'payload' => $payloadJson,
            'result' => $resultJson,
            'status' => $record['status'],
            'trace_id' => $record['trace_id'],
            'created_at' => $createdAt,
            'prev_hash' => $lastGlobal,
        ]);

        $wpdb->insert($this->table_name, [
            'execution_id' => $record['execution_id'],
            'record_type' => $record['record_type'],
            'entity_type' => $record['entity_type'],
            'entity_id' => $record['entity_id'],
            'event_type' => $record['event_type'],
            'sequence_no' => (int) $record['sequence_no'],
            'payload' => $payloadJson,
            'result' => $resultJson,
            'status' => $record['status'],
            'trace_id' => $record['trace_id'],
            'prev_hash' => $lastGlobal,
            'record_hash' => $hash,
            'created_at' => $createdAt,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function last_for_execution(string $execution_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE execution_id = %s ORDER BY sequence_no DESC, id DESC LIMIT 1",
            $execution_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function last_global_hash(): string {
        global $wpdb;
        $hash = $wpdb->get_var("SELECT record_hash FROM {$this->table_name} ORDER BY id DESC LIMIT 1");
        return is_string($hash) ? $hash : '';
    }

    private function compute_hash(array $record): string {
        return hash('sha256', wp_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

