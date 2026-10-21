<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Unit tests verifying that SettingsAccessor caches its catalog per instance.
 */
final class SettingsAccessorCatalogCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingSettingsTestCatalog::$calls = 0;
        CountingSettingsTestCatalog::$catalog = [
            'test_string_key' => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'test_default',
            ],
        ];
        CountingSettingsTestCatalog::$failure = null;
    }

    protected function tearDown(): void
    {
        CountingSettingsTestCatalog::$calls = 0;
        CountingSettingsTestCatalog::$catalog = [];
        CountingSettingsTestCatalog::$failure = null;

        parent::tearDown();
    }

    public function testTheCatalogProviderIsAskedOnceForManyReads(): void
    {
        $accessor = new SettingsAccessor(CountingSettingsTestCatalog::class);

        $this->assertTrue(isset($accessor['test_string_key']));
        $this->assertSame($this->expectedCatalog(), $accessor->catalog());
        $this->assertSame(SettingsCatalogConstants::TYPE_STRING, $accessor->typeFor('test_string_key'));
        $this->assertSame('test_default', $accessor->defaultValueFor('test_string_key'));

        $this->assertSame(1, CountingSettingsTestCatalog::$calls);
    }

    public function testANewAccessorAsksTheProviderAgain(): void
    {
        $firstAccessor = new SettingsAccessor(CountingSettingsTestCatalog::class);
        $this->assertTrue(isset($firstAccessor['test_string_key']));
        $this->assertSame(1, CountingSettingsTestCatalog::$calls);

        $secondAccessor = new SettingsAccessor(CountingSettingsTestCatalog::class);
        $this->assertTrue(isset($secondAccessor['test_string_key']));
        $this->assertSame(2, CountingSettingsTestCatalog::$calls);
    }

    public function testAThrowingProviderIsAskedAgain(): void
    {
        CountingSettingsTestCatalog::$failure = new RuntimeException('Provider failure');

        $accessor = new SettingsAccessor(CountingSettingsTestCatalog::class);

        $thrown = false;
        try {
            $accessor->catalog();
        } catch (RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Provider failure', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException was not thrown');
        $this->assertSame(1, CountingSettingsTestCatalog::$calls);

        CountingSettingsTestCatalog::$failure = null;

        $catalog = $accessor->catalog();

        $this->assertSame($this->expectedCatalog(), $catalog);
        $this->assertSame(2, CountingSettingsTestCatalog::$calls);
    }

    /** @return array<string, array<string, mixed>> Exact framework and project catalog */
    private function expectedCatalog(): array
    {
        return [
            'websocket.delivery_window_ms' => [
                'type' => 'integer',
                'admin_view_visible' => true,
                'default_value' => 1000,
                'rule' => PositiveIntegerRule::class,
            ],
            'test_string_key' => [
                'type' => 'string',
                'default_value' => 'test_default',
            ],
        ];
    }
}

/**
 * Catalog provider for SettingsAccessorCatalogCacheTest that counts getCatalog() calls.
 */
final class CountingSettingsTestCatalog implements CatalogProviderInterface
{
    public static int $calls = 0;

    /** @var array<string, array<string, mixed>> Settings catalog */
    public static array $catalog = [];

    public static ?Throwable $failure = null;

    /**
     * Returns the current test catalog and counts calls.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        ++self::$calls;

        if (self::$failure !== null) {
            throw self::$failure;
        }

        return self::$catalog;
    }
}
