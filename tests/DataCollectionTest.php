<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Tests;

use PHPUnit\Framework\TestCase;
use RJV_AGI_Bridge\DataCollection\EventStore;
use RJV_AGI_Bridge\DataCollection\SessionManager;
use RJV_AGI_Bridge\DataCollection\ProfileStore;
use RJV_AGI_Bridge\DataCollection\PageViewStore;
use RJV_AGI_Bridge\DataCollection\ConsentStore;
use RJV_AGI_Bridge\DataCollection\IngestQueue;
use RJV_AGI_Bridge\DataCollection\Schema;

/**
 * Unit tests for the RJV AGI Data Collection layer.
 *
 * These tests run outside WordPress using the lightweight stubs in
 * tests/bootstrap.php.  Database operations are stubbed via a fake
 * $wpdb object injected into the global scope.
 */
final class DataCollectionTest extends TestCase {

    // -------------------------------------------------------------------------
    // Schema tests — pure logic, no DB needed
    // -------------------------------------------------------------------------

    public function test_schema_all_returns_non_empty_array(): void {
        $all = Schema::all();
        $this->assertIsArray($all);
        $this->assertGreaterThan(10, count($all), 'Schema should define more than 10 event types');
    }

    public function test_schema_every_entry_has_required_keys(): void {
        foreach (Schema::all() as $entry) {
            $this->assertArrayHasKey('event_type',  $entry);
            $this->assertArrayHasKey('category',    $entry);
            $this->assertArrayHasKey('industries',  $entry);
            $this->assertArrayHasKey('description', $entry);
            $this->assertArrayHasKey('source',      $entry);
            $this->assertArrayHasKey('properties',  $entry);
        }
    }

    public function test_schema_event_types_are_unique(): void {
        $types  = array_column(Schema::all(), 'event_type');
        $unique = array_unique($types);
        $this->assertSame(count($types), count($unique), 'Duplicate event_type found in schema');
    }

    public function test_schema_is_known_returns_true_for_page_view(): void {
        $this->assertTrue(Schema::is_known('page_view'));
    }

    public function test_schema_is_known_returns_false_for_unknown(): void {
        $this->assertFalse(Schema::is_known('definitely_not_an_event_xyzzy'));
    }

    public function test_schema_map_is_keyed_by_event_type(): void {
        $map = Schema::map();
        $this->assertArrayHasKey('page_view',     $map);
        $this->assertArrayHasKey('user_login',    $map);
        $this->assertArrayHasKey('order_created', $map);
    }

    public function test_schema_types_for_category_returns_correct_subset(): void {
        $types = Schema::types_for_category(Schema::CAT_AUTH);
        $this->assertContains('user_login',         $types);
        $this->assertContains('user_login_failed',  $types);
        $this->assertContains('user_logout',        $types);
        $this->assertNotContains('page_view', $types);
    }

    public function test_schema_types_for_industry_ecommerce_includes_order_created(): void {
        $types = Schema::types_for_industry('ecommerce');
        $this->assertContains('order_created', $types);
        $this->assertContains('product_added_to_cart', $types);
    }

    public function test_schema_version_is_semver(): void {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Schema::VERSION);
    }

    // -------------------------------------------------------------------------
    // SessionManager::parse_device — pure logic, no DB needed
    // -------------------------------------------------------------------------

    /** @dataProvider provideUserAgents */
    public function test_parse_device(string $ua, string $expectedType, string $expectedBrowser): void {
        $result = SessionManager::instance()->parse_device($ua);
        $this->assertSame($expectedType,    $result['type'],    "UA: {$ua}");
        $this->assertSame($expectedBrowser, $result['browser'], "UA: {$ua}");
    }

    /** @return array<string, array{string, string, string}> */
    public static function provideUserAgents(): array {
        return [
            'Chrome desktop' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'desktop', 'Chrome',
            ],
            'Firefox desktop' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'desktop', 'Firefox',
            ],
            'Safari iOS mobile' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                'mobile', 'Safari',
            ],
            'iPad tablet' => [
                'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                'tablet', 'Safari',
            ],
            'Edge desktop' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
                'desktop', 'Edge',
            ],
            'Android mobile Chrome' => [
                'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'mobile', 'Chrome',
            ],
            'Empty UA' => [
                '',
                'desktop', 'unknown',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // ConsentStore — terms-acceptance logic (option-backed, no real DB)
    // -------------------------------------------------------------------------

    protected function setUp(): void {
        // Reset option store and singletons before each test
        $GLOBALS['_options'] = [];
        $this->reset_singleton(ConsentStore::class);
        $this->reset_singleton(SessionManager::class);
        $this->reset_singleton(ProfileStore::class);
    }

    public function test_terms_version_constant_is_defined(): void {
        $this->assertNotEmpty(ConsentStore::TERMS_VERSION);
    }

    public function test_site_acceptance_option_constant(): void {
        $this->assertSame('rjv_agi_dc_terms_accepted', ConsentStore::SITE_ACCEPTANCE_OPTION);
    }

    public function test_site_has_accepted_returns_false_when_no_option(): void {
        // No DB, no option → has_accepted = false
        $store = ConsentStore::instance();
        // Without DB support, the DB check always returns null → false
        $this->assertFalse($store->site_has_accepted());
    }

    public function test_site_acceptance_summary_structure(): void {
        $store   = ConsentStore::instance();
        $summary = $store->site_acceptance_summary();

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('accepted',      $summary);
        $this->assertArrayHasKey('terms_version', $summary);
        $this->assertArrayHasKey('record',        $summary);
        $this->assertArrayHasKey('note',          $summary);
        $this->assertSame(ConsentStore::TERMS_VERSION, $summary['terms_version']);
        $this->assertStringContainsString('mandatory', strtolower($summary['note']));
    }

    // -------------------------------------------------------------------------
    // ProfileStore — decode_row / update_traits logic
    // -------------------------------------------------------------------------

    public function test_profile_store_instance_is_singleton(): void {
        $a = ProfileStore::instance();
        $b = ProfileStore::instance();
        $this->assertSame($a, $b);
    }

    // -------------------------------------------------------------------------
    // IngestQueue — push/stats logic (no real DB, uses fake wpdb)
    // -------------------------------------------------------------------------

    public function test_ingest_queue_instance_is_singleton(): void {
        $a = IngestQueue::instance();
        $b = IngestQueue::instance();
        $this->assertSame($a, $b);
    }

    // -------------------------------------------------------------------------
    // Schema property types
    // -------------------------------------------------------------------------

    public function test_every_property_has_name_type_required_description(): void {
        foreach (Schema::all() as $event) {
            foreach ((array) $event['properties'] as $prop) {
                $this->assertArrayHasKey('name',        $prop, "event: {$event['event_type']}");
                $this->assertArrayHasKey('type',        $prop, "event: {$event['event_type']}");
                $this->assertArrayHasKey('required',    $prop, "event: {$event['event_type']}");
                $this->assertArrayHasKey('description', $prop, "event: {$event['event_type']}");
            }
        }
    }

    public function test_schema_source_is_valid_value(): void {
        $valid = ['server', 'browser', 'both'];
        foreach (Schema::all() as $event) {
            $this->assertContains(
                $event['source'],
                $valid,
                "Invalid source '{$event['source']}' for event: {$event['event_type']}"
            );
        }
    }

    public function test_schema_industries_are_non_empty_arrays(): void {
        foreach (Schema::all() as $event) {
            $this->assertIsArray($event['industries']);
            $this->assertNotEmpty($event['industries'], "Event {$event['event_type']} has no industries");
        }
    }

    public function test_web_vitals_event_covers_all_industries(): void {
        $map = Schema::map();
        $this->assertArrayHasKey('web_vitals', $map);
        $industries = $map['web_vitals']['industries'];
        // Should be available for all industries
        $this->assertContains('general',   $industries);
        $this->assertContains('ecommerce', $industries);
        $this->assertContains('healthcare',$industries);
    }

    public function test_ecommerce_events_tagged_for_ecommerce_industry(): void {
        $ecom_types = Schema::types_for_industry('ecommerce');
        foreach (['order_created', 'product_added_to_cart', 'order_payment_complete'] as $type) {
            $this->assertContains($type, $ecom_types, "{$type} should be tagged for ecommerce");
        }
    }

    public function test_mandatory_terms_note_in_schema_summary_cannot_be_opted_out(): void {
        $summary = ConsentStore::instance()->site_acceptance_summary();
        // The note must clearly state that data collection cannot be disabled
        $this->assertStringContainsString('mandatory', strtolower($summary['note']));
        $this->assertStringContainsString('all', strtolower($summary['note']));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Reset a singleton instance using reflection.
     */
    private function reset_singleton(string $class): void {
        try {
            $ref  = new \ReflectionClass($class);
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        } catch (\ReflectionException $e) {
            // Class not loaded yet — nothing to reset
        }
    }
}
