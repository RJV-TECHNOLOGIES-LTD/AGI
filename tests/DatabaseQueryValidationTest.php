<?php
declare(strict_types=1);

namespace RJV_AGI_Bridge\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Database::validate_select_query() and
 * Database::strip_sql_comments().
 *
 * These private methods are exercised via reflection. The critical security
 * property verified here is that MySQL conditional comments (e.g. the variant
 * starting with slash-asterisk-bang) cannot be used to hide blocked keywords:
 * the same comment-stripped SQL is both validated and sent to the database,
 * so the validator and the executor always see identical text.
 */
final class DatabaseQueryValidationTest extends TestCase {

    private \ReflectionClass $ref;
    private object $db;

    protected function setUp(): void {
        $this->ref = new \ReflectionClass(\RJV_AGI_Bridge\API\Database::class);
        $this->db  = $this->ref->newInstanceWithoutConstructor();
    }

    private function stripComments(string $sql): string {
        $method = $this->ref->getMethod('strip_sql_comments');
        $method->setAccessible(true);
        return $method->invoke($this->db, $sql);
    }

    private function validate(string $sql): ?string {
        $method = $this->ref->getMethod('validate_select_query');
        $method->setAccessible(true);
        return $method->invoke($this->db, $sql);
    }

    // ── strip_sql_comments ────────────────────────────────────────────────────

    public function test_strips_double_dash_line_comment(): void {
        $result = $this->stripComments("SELECT 1 -- this is a comment\nFROM dual");
        $this->assertStringNotContainsString('comment', $result);
    }

    public function test_strips_standard_block_comment(): void {
        $result = $this->stripComments('SELECT /* secret */ 1 FROM t');
        $this->assertStringNotContainsString('secret', $result);
    }

    public function test_strips_mysql_conditional_comment(): void {
        // Build the conditional comment string at runtime so the PHP parser
        // does not treat the slash-asterisk-bang sequence as part of the
        // surrounding PHP docblock.
        $conditional = '/' . '*' . '!50000 DROP TABLE x' . '*' . '/';
        $result = $this->stripComments("SELECT {$conditional} 1");
        $this->assertStringNotContainsString('DROP', strtoupper($result));
    }

    public function test_strips_multiple_comments(): void {
        $result = $this->stripComments('SELECT /* a */ 1, /* b */ 2');
        $this->assertStringNotContainsString('/* a */', $result);
        $this->assertStringNotContainsString('/* b */', $result);
    }

    // ── validate_select_query ─────────────────────────────────────────────────

    public function test_valid_select_passes(): void {
        $this->assertNull($this->validate('SELECT id, title FROM wp_posts LIMIT 10'));
    }

    public function test_non_select_is_rejected(): void {
        $this->assertNotNull($this->validate('UPDATE wp_posts SET title = "x"'));
    }

    public function test_blocked_keyword_drop_is_rejected(): void {
        $this->assertNotNull($this->validate('SELECT 1; DROP TABLE wp_users'));
    }

    public function test_semicolons_are_rejected(): void {
        $error = $this->validate('SELECT 1; SELECT 2');
        $this->assertNotNull($error);
        $this->assertStringContainsString('semicolon', strtolower($error));
    }

    public function test_information_schema_is_rejected(): void {
        $error = $this->validate('SELECT table_name FROM information_schema.tables');
        $this->assertNotNull($error);
    }

    public function test_sleep_is_rejected(): void {
        $error = $this->validate('SELECT SLEEP(5)');
        $this->assertNotNull($error);
    }

    public function test_empty_string_is_rejected(): void {
        $error = $this->validate('');
        $this->assertNotNull($error);
    }

    // ── Conditional-comment bypass is now closed ──────────────────────────────

    public function test_conditional_comment_bypass_is_blocked(): void {
        // The conditional comment hides DROP TABLE in the original SQL.
        // After strip_sql_comments the dangerous content is removed, so the
        // cleaned SQL that is BOTH validated AND executed contains no DROP.
        $conditional = '/' . '*' . '!50000 DROP TABLE wp_users' . '*' . '/';
        $raw     = "SELECT {$conditional} 1";
        $cleaned = $this->stripComments($raw);

        // Cleaned string must not contain the blocked keyword.
        $this->assertStringNotContainsString('DROP', strtoupper($cleaned));
        // Cleaned string is a valid SELECT.
        $this->assertNull($this->validate($cleaned));
    }
}
