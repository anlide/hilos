<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\SecondFactor;

use Hilos\Auth\SecondFactor\SecondFactorBackupCodesRule;
use Hilos\Auth\SecondFactor\SecondFactorRequiredRule;
use Hilos\Auth\SecondFactor\SecondFactorResetWaitDefaultRule;
use Hilos\Auth\SecondFactor\SecondFactorResetWaitMaxRule;
use Hilos\Auth\SecondFactor\SecondFactorResetWaitMinRule;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\SecondFactorTrustDaysRule;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the eight second-factor settings and the rules six of them are written through (HIL-494, HIL-1303).
 *
 * The three wait bounds judge each other, so those cases run over a settings accessor whose
 * catalog holds the bounds under test as defaults - with no database mounted, a default is
 * what the accessor answers.
 */
final class SecondFactorSettingsRulesTest extends TestCase
{
    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSetting = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(SecondFactorSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSetting;

        parent::tearDown();
    }

    /**
     * The group lists its eight keys in the order of the screen (HIL-1303): the two switches first, a
     * yes or a no that is on out of the box and needs no rule, then the six keys edited in a dialog.
     */
    public function testTheGroupListsTheTwoSwitchesBeforeTheSixDialogKeys(): void
    {
        self::assertSame([
            'auth.second_factor.enabled',
            'auth.second_factor.forget_trusts_on_disable',
            'auth.second_factor.required',
            'auth.second_factor.trust_days',
            'auth.second_factor.backup_codes',
            'auth.second_factor.reset_wait_default_days',
            'auth.second_factor.reset_wait_min_days',
            'auth.second_factor.reset_wait_max_days',
        ], SecondFactorSettings::KEYS);
        self::assertSame(
            ['auth.second_factor.enabled', 'auth.second_factor.forget_trusts_on_disable'],
            SecondFactorSettings::SWITCH_KEYS,
        );
        self::assertSame([
            'auth.second_factor.required',
            'auth.second_factor.trust_days',
            'auth.second_factor.backup_codes',
            'auth.second_factor.reset_wait_default_days',
            'auth.second_factor.reset_wait_min_days',
            'auth.second_factor.reset_wait_max_days',
        ], SecondFactorSettings::DIALOG_KEYS);

        $catalog = SecondFactorSettingsCatalog::getCatalog();
        self::assertSame(SecondFactorSettings::KEYS, array_keys($catalog));
        foreach (SecondFactorSettings::SWITCH_KEYS as $key) {
            self::assertSame(SettingsCatalogConstants::TYPE_BOOLEAN, $catalog[$key][SettingsCatalogConstants::CATALOG_ENTRY_TYPE], $key);
            self::assertTrue($catalog[$key][SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE], $key);
            self::assertArrayNotHasKey(SettingsCatalogConstants::CATALOG_ENTRY_RULE, $catalog[$key], $key);
            self::assertTrue(Hilos::$setting[$key]->bool(), $key);
        }
    }

    /**
     * Exactly the three audiences are accepted.
     */
    public function testRequiredAcceptsTheThreeAudiences(): void
    {
        foreach (SecondFactorSettings::REQUIRED_VALUES as $value) {
            self::assertNull(SecondFactorRequiredRule::validate($value));
        }
        self::assertSame('Value must be one of: none, admins, everyone', SecondFactorRequiredRule::validate('all'));
    }

    /**
     * Zero trust days is a value (no checkbox), and the ceiling is a year.
     */
    public function testTrustDaysRunFromZeroToAYear(): void
    {
        self::assertNull(SecondFactorTrustDaysRule::validate(0));
        self::assertNull(SecondFactorTrustDaysRule::validate('365'));
        self::assertSame('Value must be a whole number of days from 0 to 365', SecondFactorTrustDaysRule::validate(366));
        self::assertSame('Value must be a whole number of days from 0 to 365', SecondFactorTrustDaysRule::validate(-1));
        self::assertSame('Value must be a whole number of days from 0 to 365', SecondFactorTrustDaysRule::validate('7.5'));
    }

    /**
     * A set holds one to twenty codes.
     */
    public function testBackupCodesRunFromOneToTwenty(): void
    {
        self::assertNull(SecondFactorBackupCodesRule::validate(1));
        self::assertNull(SecondFactorBackupCodesRule::validate(20));
        self::assertSame('Value must be a whole number from 1 to 20', SecondFactorBackupCodesRule::validate(0));
        self::assertSame('Value must be a whole number from 1 to 20', SecondFactorBackupCodesRule::validate(21));
    }

    /**
     * The default stays between the minimum and the maximum in force (1 and 30 out of the box).
     */
    public function testTheDefaultStaysBetweenTheBoundsInForce(): void
    {
        self::assertNull(SecondFactorResetWaitDefaultRule::validate(1));
        self::assertNull(SecondFactorResetWaitDefaultRule::validate(30));
        self::assertSame(
            'Value must be a whole number of days from the minimum (1) to the maximum (30)',
            SecondFactorResetWaitDefaultRule::validate(31),
        );
        self::assertSame(
            'Value must be a whole number of days from the minimum (1) to the maximum (30)',
            SecondFactorResetWaitDefaultRule::validate(0),
        );
    }

    /**
     * The minimum stays at one day or more and at the default or less (8 out of the box).
     */
    public function testTheMinimumStaysBetweenOneDayAndTheDefault(): void
    {
        self::assertNull(SecondFactorResetWaitMinRule::validate(1));
        self::assertNull(SecondFactorResetWaitMinRule::validate(8));
        self::assertSame('Value must be a whole number of days from 1 to the default (8)', SecondFactorResetWaitMinRule::validate(0));
        self::assertSame('Value must be a whole number of days from 1 to the default (8)', SecondFactorResetWaitMinRule::validate(9));
    }

    /**
     * The maximum stays at the default or more and a year or less.
     */
    public function testTheMaximumStaysBetweenTheDefaultAndAYear(): void
    {
        self::assertNull(SecondFactorResetWaitMaxRule::validate(8));
        self::assertNull(SecondFactorResetWaitMaxRule::validate(365));
        self::assertSame('Value must be a whole number of days from the default (8) to 365', SecondFactorResetWaitMaxRule::validate(7));
        self::assertSame('Value must be a whole number of days from the default (8) to 365', SecondFactorResetWaitMaxRule::validate(366));
    }

    /**
     * The bounds are read as they stand: a narrower range in force narrows what the default may be.
     */
    public function testTheBoundsInForceAreTheOnesJudgedBy(): void
    {
        Hilos::$setting = new SettingsAccessor(NarrowResetWaitTestCatalog::class);

        self::assertNull(SecondFactorResetWaitDefaultRule::validate(5));
        self::assertSame(
            'Value must be a whole number of days from the minimum (3) to the maximum (10)',
            SecondFactorResetWaitDefaultRule::validate(2),
        );
        self::assertSame('Value must be a whole number of days from 1 to the default (5)', SecondFactorResetWaitMinRule::validate(6));
        self::assertSame('Value must be a whole number of days from the default (5) to 365', SecondFactorResetWaitMaxRule::validate(4));
    }
}

/**
 * A catalog whose wait bounds stand at 3 <= 5 <= 10, so the cross-checks have something to read.
 */
final class NarrowResetWaitTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> The second-factor catalog with narrowed wait defaults
     */
    public static function getCatalog(): array
    {
        $catalog = SecondFactorSettingsCatalog::getCatalog();
        $catalog[SecondFactorSettings::RESET_WAIT_MIN_DAYS_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = 3;
        $catalog[SecondFactorSettings::RESET_WAIT_DEFAULT_DAYS_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = 5;
        $catalog[SecondFactorSettings::RESET_WAIT_MAX_DAYS_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = 10;

        return $catalog;
    }
}
