<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\CronExpressionRule;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use stdClass;

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
        $this->expectException(SettingValueRefusedException::class);
        $this->expectExceptionMessage((string)NonNegativeIntegerRule::validate(-1));

        SettingValueRules::assertValid(self::INTEGER_KEY, -1);
    }

    public function testGuardRefusesACronExpressionWithTheTextOfItsOwnRule(): void
    {
        $this->expectException(SettingValueRefusedException::class);
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

    public function testTypedIntegerAcceptsWholeNumbersAndNumericStrings(): void
    {
        $this->assertSame(0, SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, 0));
        $this->assertSame(42, SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, 42));
        $this->assertSame(-10, SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, -10));
        $this->assertSame(0, SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, '0'));
        $this->assertSame(42, SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, '42'));
        $this->assertSame(42, SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, ' 42 '));
        $this->assertSame(-10, SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, '-10'));
    }

    public function testTypedIntegerRefusesNonIntegersAndNonScalars(): void
    {
        $scalars = [
            'abc' => "Setting '" . self::INTEGER_KEY . "' takes a whole number; 'abc' is not one",
            '1.5' => "Setting '" . self::INTEGER_KEY . "' takes a whole number; '1.5' is not one",
            '' => "Setting '" . self::INTEGER_KEY . "' takes a whole number; '' is not one",
        ];
        foreach ($scalars as $value => $expectedMessage) {
            try {
                SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, $value);
                $this->fail("Expected SettingValueRefusedException for '{$value}'");
            } catch (SettingValueRefusedException $e) {
                $this->assertSame($expectedMessage, $e->getMessage());
            }
        }

        try {
            SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, 1.5);
            $this->fail('Expected SettingValueRefusedException for float 1.5');
        } catch (SettingValueRefusedException $e) {
            $this->assertSame("Setting '" . self::INTEGER_KEY . "' takes a whole number; '1.5' is not one", $e->getMessage());
        }

        try {
            SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, true);
            $this->fail('Expected SettingValueRefusedException for boolean true');
        } catch (SettingValueRefusedException $e) {
            $this->assertSame("Setting '" . self::INTEGER_KEY . "' takes a whole number; 'true' is not one", $e->getMessage());
        }

        try {
            SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, false);
            $this->fail('Expected SettingValueRefusedException for boolean false');
        } catch (SettingValueRefusedException $e) {
            $this->assertSame("Setting '" . self::INTEGER_KEY . "' takes a whole number; 'false' is not one", $e->getMessage());
        }

        foreach ([null, []] as $nonScalar) {
            try {
                SettingValueRules::typed(self::INTEGER_KEY, SettingsCatalogConstants::TYPE_INTEGER, $nonScalar);
                $this->fail('Expected SettingValueRefusedException for non-scalar');
            } catch (SettingValueRefusedException $e) {
                $this->assertSame("Setting '" . self::INTEGER_KEY . "' takes a whole number; value is not one", $e->getMessage());
            }
        }
    }

    public function testTypedFloatAcceptsNumbersAndNumericStrings(): void
    {
        $this->assertSame(0.0, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, 0));
        $this->assertSame(42.0, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, 42));
        $this->assertSame(3.14, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, 3.14));
        $this->assertSame(0.0, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, '0'));
        $this->assertSame(3.14, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, '3.14'));
        $this->assertSame(42.0, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, ' 42 '));
        $this->assertSame(-2.5, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, '-2.5'));
        $this->assertSame(1000.0, SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, '1e3'));
    }

    public function testTypedFloatRefusesNonNumbersAndNonScalars(): void
    {
        $scalars = [
            'abc' => "Setting 'ratio' takes a number; 'abc' is not one",
            '' => "Setting 'ratio' takes a number; '' is not one",
        ];
        foreach ($scalars as $value => $expectedMessage) {
            try {
                SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, $value);
                $this->fail("Expected SettingValueRefusedException for '{$value}'");
            } catch (SettingValueRefusedException $e) {
                $this->assertSame($expectedMessage, $e->getMessage());
            }
        }

        try {
            SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, true);
            $this->fail('Expected SettingValueRefusedException for boolean true');
        } catch (SettingValueRefusedException $e) {
            $this->assertSame("Setting 'ratio' takes a number; 'true' is not one", $e->getMessage());
        }

        try {
            SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, false);
            $this->fail('Expected SettingValueRefusedException for boolean false');
        } catch (SettingValueRefusedException $e) {
            $this->assertSame("Setting 'ratio' takes a number; 'false' is not one", $e->getMessage());
        }

        foreach ([null, []] as $nonScalar) {
            try {
                SettingValueRules::typed('ratio', SettingsCatalogConstants::TYPE_FLOAT, $nonScalar);
                $this->fail('Expected SettingValueRefusedException for non-scalar');
            } catch (SettingValueRefusedException $e) {
                $this->assertSame("Setting 'ratio' takes a number; value is not one", $e->getMessage());
            }
        }
    }

    public function testTypedBooleanAcceptsBooleansIntegersAndKnownStrings(): void
    {
        $this->assertTrue(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, true));
        $this->assertFalse(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, false));
        $this->assertTrue(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, 1));
        $this->assertFalse(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, 0));
        $this->assertTrue(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, -1));
        $this->assertTrue(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, '1'));
        $this->assertFalse(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, '0'));
        $this->assertTrue(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, 'true'));
        $this->assertTrue(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, 'TRUE'));
        $this->assertTrue(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, ' 1 '));
        $this->assertFalse(SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, ' 0 '));
    }

    public function testTypedBooleanNormalizesFalseStringToBooleanFalse(): void
    {
        $result = SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, 'false');
        $this->assertSame(false, $result);

        $resultUpper = SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, 'FALSE');
        $this->assertSame(false, $resultUpper);

        $resultSpaced = SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, '  false  ');
        $this->assertSame(false, $resultSpaced);
    }

    public function testTypedBooleanRefusesInvalidStringsAndNonScalars(): void
    {
        $scalars = [
            'abc' => "Setting 'flag' takes true or false; 'abc' is not one",
            'yes' => "Setting 'flag' takes true or false; 'yes' is not one",
            'no' => "Setting 'flag' takes true or false; 'no' is not one",
            '' => "Setting 'flag' takes true or false; '' is not one",
        ];
        foreach ($scalars as $value => $expectedMessage) {
            try {
                SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, $value);
                $this->fail("Expected SettingValueRefusedException for '{$value}'");
            } catch (SettingValueRefusedException $e) {
                $this->assertSame($expectedMessage, $e->getMessage());
            }
        }

        try {
            SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, 1.5);
            $this->fail('Expected SettingValueRefusedException for float 1.5');
        } catch (SettingValueRefusedException $e) {
            $this->assertSame("Setting 'flag' takes true or false; '1.5' is not one", $e->getMessage());
        }

        foreach ([null, []] as $nonScalar) {
            try {
                SettingValueRules::typed('flag', SettingsCatalogConstants::TYPE_BOOLEAN, $nonScalar);
                $this->fail('Expected SettingValueRefusedException for non-scalar');
            } catch (SettingValueRefusedException $e) {
                $this->assertSame("Setting 'flag' takes true or false; value is not one", $e->getMessage());
            }
        }
    }

    public function testTypedStringAcceptsAnyScalarAsString(): void
    {
        $this->assertSame('hello', SettingValueRules::typed('name', SettingsCatalogConstants::TYPE_STRING, 'hello'));
        $this->assertSame('', SettingValueRules::typed('name', SettingsCatalogConstants::TYPE_STRING, ''));
        $this->assertSame('42', SettingValueRules::typed('name', SettingsCatalogConstants::TYPE_STRING, 42));
        $this->assertSame('3.14', SettingValueRules::typed('name', SettingsCatalogConstants::TYPE_STRING, 3.14));
        $this->assertSame('1', SettingValueRules::typed('name', SettingsCatalogConstants::TYPE_STRING, true));
        $this->assertSame('', SettingValueRules::typed('name', SettingsCatalogConstants::TYPE_STRING, false));
    }

    public function testTypedStringRefusesNonScalars(): void
    {
        foreach ([null, [], new stdClass()] as $nonScalar) {
            try {
                SettingValueRules::typed('name', SettingsCatalogConstants::TYPE_STRING, $nonScalar);
                $this->fail('Expected SettingValueRefusedException for non-scalar string');
            } catch (SettingValueRefusedException $e) {
                $this->assertSame("Setting 'name' takes text; value is not one", $e->getMessage());
            }
        }
    }

    public function testTypedRefusesUnsupportedType(): void
    {
        $this->expectException(SettingTypeMismatchException::class);
        $this->expectExceptionMessage("Setting type 'unsupported' is not a supported setting type");

        SettingValueRules::typed('key', 'unsupported', 'val');
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
