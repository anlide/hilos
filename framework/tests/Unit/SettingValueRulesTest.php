<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\CronExpressionRule;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the catalog value rules the settings write paths pass through (HIL-760).
 *
 * Locks what the rules accept and refuse, that the refusal text comes from the rule itself, and
 * that a key without a rule — or a settings layer that is not initialized at all — is written
 * exactly as before.
 */
final class SettingValueRulesTest extends TestCase
{
    /** Key carrying the non-negative integer rule in the test catalog. */
    private const string INTEGER_KEY = 'test.rotation.max_age_seconds';

    /** Key carrying the cron expression rule in the test catalog. */
    private const string CRON_KEY = 'test.rotation.cron';

    /** Key cataloged without any rule. */
    private const string PLAIN_KEY = 'test.plain';

    protected function setUp(): void
    {
        parent::setUp();

        SettingValueRulesTestCatalog::$catalog = [
            self::INTEGER_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
            ],
            self::CRON_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => CronExpressionRule::class,
            ],
            self::PLAIN_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
            ],
        ];
        Hilos::$setting = new SettingsAccessor(SettingValueRulesTestCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = null;
        SettingValueRulesTestCatalog::$catalog = [];

        parent::tearDown();
    }

    public function testNonNegativeIntegerRuleAcceptsWholeNumbersAndDigitStrings(): void
    {
        $this->assertNull(NonNegativeIntegerRule::validate(0));
        $this->assertNull(NonNegativeIntegerRule::validate(2_592_000));
        $this->assertNull(NonNegativeIntegerRule::validate('0'));
        $this->assertNull(NonNegativeIntegerRule::validate('86400'));
    }

    public function testNonNegativeIntegerRuleRefusesNegativesAndNonIntegers(): void
    {
        $refusal = NonNegativeIntegerRule::validate(-1);

        $this->assertNotNull($refusal);
        $this->assertSame($refusal, NonNegativeIntegerRule::validate('-1'));
        $this->assertNotNull(NonNegativeIntegerRule::validate('30 days'));
        $this->assertNotNull(NonNegativeIntegerRule::validate(''));
        $this->assertNotNull(NonNegativeIntegerRule::validate(1.5));
        $this->assertNotNull(NonNegativeIntegerRule::validate(true));
        $this->assertNotNull(NonNegativeIntegerRule::validate(null));
    }

    public function testCronExpressionRuleAcceptsEmptyAndRunnableExpressions(): void
    {
        $this->assertNull(CronExpressionRule::validate(''));
        $this->assertNull(CronExpressionRule::validate('   '));
        $this->assertNull(CronExpressionRule::validate('0 3 * * *'));
        $this->assertNull(CronExpressionRule::validate('*/5 * * * *'));
    }

    public function testCronExpressionRuleRefusesAnExpressionThatWouldNeverFire(): void
    {
        $this->assertNotNull(CronExpressionRule::validate('0 3 * * abc'));
        $this->assertNotNull(CronExpressionRule::validate('every night'));
        $this->assertNotNull(CronExpressionRule::validate('0 3 * *'));
        $this->assertNotNull(CronExpressionRule::validate(3));
    }

    public function testGuardPassesAValueItsRuleAccepts(): void
    {
        SettingValueRules::assertValid(self::INTEGER_KEY, '86400');
        SettingValueRules::assertValid(self::CRON_KEY, '0 3 * * *');

        $this->expectNotToPerformAssertions();
    }

    public function testGuardRefusesWithTheTextOfTheRule(): void
    {
        $this->expectException(SettingInvalidValueException::class);
        $this->expectExceptionMessage((string)NonNegativeIntegerRule::validate(-1));

        SettingValueRules::assertValid(self::INTEGER_KEY, -1);
    }

    public function testGuardRefusesACronExpressionWithTheTextOfItsOwnRule(): void
    {
        $this->expectException(SettingInvalidValueException::class);
        $this->expectExceptionMessage((string)CronExpressionRule::validate('0 3 * * abc'));

        SettingValueRules::assertValid(self::CRON_KEY, '0 3 * * abc');
    }

    public function testGuardLetsThroughAKeyWithoutARule(): void
    {
        SettingValueRules::assertValid(self::PLAIN_KEY, 'anything at all');
        SettingValueRules::assertValid('not.in.catalog', -1);

        $this->expectNotToPerformAssertions();
    }

    public function testGuardLetsThroughWhenSettingsAreNotInitialized(): void
    {
        Hilos::$setting = null;

        SettingValueRules::assertValid(self::INTEGER_KEY, -1);

        $this->expectNotToPerformAssertions();
    }

    public function testCatalogNamingSomethingThatIsNotARuleIsRefused(): void
    {
        SettingValueRulesTestCatalog::$catalog[self::PLAIN_KEY][SettingsCatalogConstants::CATALOG_ENTRY_RULE]
            = 'Not\\A\\Rule';

        $this->expectException(SettingInvalidValueException::class);

        SettingValueRules::assertValid(self::PLAIN_KEY, 'value');
    }
}

/**
 * Catalog provider for the setting value rule tests.
 */
final class SettingValueRulesTestCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Settings catalog */
    public static array $catalog = [];

    /**
     * Returns the current test catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}
