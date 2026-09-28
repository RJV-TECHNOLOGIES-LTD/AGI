<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Tests;

/**
 * In-memory wpdb sufficient for audit inserts, approval rows, and ledger writes.
 */
final class FakeWpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $rows = [];

    public function insert(string $table, array $data, mixed $format = null): int {
        $this->insert_id++;
        $data['id'] = $this->insert_id;
        // Mirror the approval-queue column default. submit() omits status and
        // relies on ENUM default 'pending'.
        if (str_ends_with($table, 'rjv_agi_approval_queue')) {
            $data['status'] ??= 'pending';
            $data['execution_result'] ??= null;
        }
        $this->rows[$table][$this->insert_id] = $data;
        return 1;
    }

    public function update(string $table, array $data, array $where, mixed $format = null, mixed $where_format = null): int {
        $count = 0;
        foreach ($this->rows[$table] ?? [] as $id => $row) {
            $match = true;
            foreach ($where as $key => $expected) {
                if (!array_key_exists($key, $row) || (string) $row[$key] !== (string) $expected) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $this->rows[$table][$id] = array_merge($row, $data);
                $count++;
            }
        }
        return $count;
    }

    public function prepare(string $query, mixed ...$args): string {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $index = 0;
        $prepared = preg_replace_callback('/%[dfs]/', static function () use (&$args, &$index): string {
            $value = $args[$index++] ?? '';
            return (string) $value;
        }, $query);
        return is_string($prepared) ? $prepared : $query;
    }

    public function get_row(string $query, mixed $output = null): ?array {
        if (preg_match('/FROM\s+(\S+)\s+WHERE\s+id\s*=\s*(\d+)/i', $query, $matches) === 1) {
            return $this->rows[$matches[1]][(int) $matches[2]] ?? null;
        }
        return null;
    }

    public function get_var(string $query): mixed {
        return null;
    }

    public function get_results(string $query, mixed $output = null): array {
        return [];
    }

    public function get_col(string $query): array {
        return [];
    }

    public function query(string $query): int {
        return 0;
    }

    public function get_charset_collate(): string {
        return '';
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    /**
     * @return list<array{action: string, status: string, details: array<string, mixed>}>
     */
    public function audit_entries(): array {
        $table = $this->prefix . (defined('RJV_AGI_LOG_TABLE') ? RJV_AGI_LOG_TABLE : 'rjv_agi_audit_log');
        $entries = [];
        foreach ($this->rows[$table] ?? [] as $row) {
            $details = json_decode((string) ($row['details'] ?? ''), true);
            $entries[] = [
                'action' => (string) ($row['action'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'details' => is_array($details) ? $details : [],
            ];
        }
        return $entries;
    }
}
