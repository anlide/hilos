<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Impersonation;

use Hilos\Auth\Impersonation\DTO\ImpersonationCardSettings;
use Hilos\Auth\Impersonation\DTO\ImpersonationPolicySignalData;
use Hilos\Auth\Impersonation\ImpersonationScopeRule;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the impersonation settings as they are read (HIL-1170).
 *
 * Every default is the behavior the product had before the settings existed, so a project whose
 * catalog does not fold the fragment in - and a process with no settings accessor at all - lives
 * on them. With no database mounted an accessor answers the catalog default, so a stored value is
 * scripted through an accessor that answers it for the keys a case names.
 */
final class ImpersonationSettingsTest extends TestCase
{
    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSetting = Hilos::$setting;
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSetting;

        parent::tearDown();
    }

    public function testNoSettingsAccessorAnswersTodaysBehavior(): void
    {
        Hilos::$setting = null;

        self::assertDefaults();
    }

    /**
     * A project like binance mounts no impersonation page and folds no fragment in.
     */
    public function testACatalogWithoutTheKeysAnswersTodaysBehavior(): void
    {
        Hilos::$setting = new SettingsAccessor(StepUpSettingsCatalog::class);

        self::assertDefaults();
    }

    public function testTheCatalogDefaultsAreTodaysBehavior(): void
    {
        Hilos::$setting = new SettingsAccessor(ImpersonationSettingsCatalog::class);

        self::assertDefaults();
        self::assertSame(ImpersonationSettings::KEYS, array_keys(ImpersonationSettingsCatalog::getCatalog()));
    }

    public function testStoredValuesAreRead(): void
    {
        Hilos::$setting = self::storing([
            ImpersonationSettings::ALLOWED_KEY => '0',
            ImpersonationSettings::SCOPE_KEY => ImpersonationSettings::SCOPE_VIEW,
            ImpersonationSettings::ACCOUNT_ACCESS_KEY => '1',
            ImpersonationSettings::CARRY_ADMIN_KEY => '1',
            ImpersonationSettings::BLOCKED_KEY => '0',
            ImpersonationSettings::FROZEN_KEY => '0',
            ImpersonationSettings::EQUAL_KEY => '0',
        ]);

        self::assertFalse(ImpersonationSettings::isAllowed());
        self::assertSame(ImpersonationSettings::SCOPE_VIEW, ImpersonationSettings::scope());
        self::assertTrue(ImpersonationSettings::isViewOnly());
        self::assertTrue(ImpersonationSettings::allowsAccountAccess());
        self::assertTrue(ImpersonationSettings::carriesAdmin());
        self::assertFalse(ImpersonationSettings::allowsBlocked());
        self::assertFalse(ImpersonationSettings::allowsFrozen());
        self::assertFalse(ImpersonationSettings::allowsEqual());
        self::assertSame(
            [
                ImpersonationCardSettings::allowed => false,
                ImpersonationCardSettings::scope => ImpersonationSettings::SCOPE_VIEW,
                ImpersonationCardSettings::carryAdmin => true,
                ImpersonationCardSettings::blocked => false,
                ImpersonationCardSettings::frozen => false,
                ImpersonationCardSettings::equal => false,
            ],
            ImpersonationCardSettings::current()->toArray(),
        );
        self::assertSame(
            [
                ImpersonationPolicySignalData::viewOnly => true,
                ImpersonationPolicySignalData::carryAdmin => true,
                ImpersonationPolicySignalData::allowed => false,
                ImpersonationPolicySignalData::accountAccess => true,
                ImpersonationPolicySignalData::blocked => false,
                ImpersonationPolicySignalData::frozen => false,
                ImpersonationPolicySignalData::equal => false,
            ],
            ImpersonationPolicySignalData::current()->toArray(),
        );
    }

    public function testTheSwitchesAreEveryKeyButTheScope(): void
    {
        self::assertSame(
            array_values(array_diff(ImpersonationSettings::KEYS, [ImpersonationSettings::SCOPE_KEY])),
            ImpersonationSettings::SWITCH_KEYS,
        );
    }

    public function testTheScopeRuleAcceptsTheTwoValuesAndNothingElse(): void
    {
        self::assertNull(ImpersonationScopeRule::validate(ImpersonationSettings::SCOPE_VIEW));
        self::assertNull(ImpersonationScopeRule::validate(ImpersonationSettings::SCOPE_ACT));
        self::assertSame('Choose view only or view and act', ImpersonationScopeRule::validate('admin'));
        self::assertSame('Choose view only or view and act', ImpersonationScopeRule::validate(''));
        self::assertSame('Choose view only or view and act', ImpersonationScopeRule::validate(true));
    }

    public function testPolicySignalDataExactRoundtrip(): void
    {
        $wire = [
            ImpersonationPolicySignalData::viewOnly => true,
            ImpersonationPolicySignalData::carryAdmin => false,
            ImpersonationPolicySignalData::allowed => true,
            ImpersonationPolicySignalData::accountAccess => false,
            ImpersonationPolicySignalData::blocked => true,
            ImpersonationPolicySignalData::frozen => false,
            ImpersonationPolicySignalData::equal => true,
        ];

        $restored = ImpersonationPolicySignalData::fromArray($wire);

        self::assertTrue($restored->viewOnly);
        self::assertFalse($restored->carryAdmin);
        self::assertTrue($restored->allowed);
        self::assertFalse($restored->accountAccess);
        self::assertTrue($restored->blocked);
        self::assertFalse($restored->frozen);
        self::assertTrue($restored->equal);
        self::assertSame($wire, $restored->toArray());
    }

    public function testPolicySignalDataRejectsMissingKey(): void
    {
        $wire = [
            ImpersonationPolicySignalData::viewOnly => true,
            ImpersonationPolicySignalData::carryAdmin => false,
            ImpersonationPolicySignalData::allowed => true,
            ImpersonationPolicySignalData::accountAccess => false,
            ImpersonationPolicySignalData::blocked => true,
            ImpersonationPolicySignalData::frozen => false,
        ];

        $this->expectException(InvalidFormatException::class);

        ImpersonationPolicySignalData::fromArray($wire);
    }

    public function testPolicySignalDataRejectsNonBooleanKey(): void
    {
        $wire = [
            ImpersonationPolicySignalData::viewOnly => true,
            ImpersonationPolicySignalData::carryAdmin => false,
            ImpersonationPolicySignalData::allowed => 'true',
            ImpersonationPolicySignalData::accountAccess => false,
            ImpersonationPolicySignalData::blocked => true,
            ImpersonationPolicySignalData::frozen => false,
            ImpersonationPolicySignalData::equal => true,
        ];

        $this->expectException(InvalidFormatException::class);

        ImpersonationPolicySignalData::fromArray($wire);
    }

    /**
     * Asserts the seven readers answer the behavior the product had before the settings existed.
     */
    private static function assertDefaults(): void
    {
        self::assertTrue(ImpersonationSettings::isAllowed());
        self::assertSame(ImpersonationSettings::SCOPE_ACT, ImpersonationSettings::scope());
        self::assertFalse(ImpersonationSettings::isViewOnly());
        self::assertFalse(ImpersonationSettings::allowsAccountAccess());
        self::assertFalse(ImpersonationSettings::carriesAdmin());
        self::assertTrue(ImpersonationSettings::allowsBlocked());
        self::assertTrue(ImpersonationSettings::allowsFrozen());
        self::assertTrue(ImpersonationSettings::allowsEqual());
        self::assertSame(
            [
                ImpersonationPolicySignalData::viewOnly => false,
                ImpersonationPolicySignalData::carryAdmin => false,
                ImpersonationPolicySignalData::allowed => true,
                ImpersonationPolicySignalData::accountAccess => false,
                ImpersonationPolicySignalData::blocked => true,
                ImpersonationPolicySignalData::frozen => true,
                ImpersonationPolicySignalData::equal => true,
            ],
            ImpersonationPolicySignalData::current()->toArray(),
        );
    }

    /**
     * @param array<string, string> $stored Values the rows hold, by key
     * @return SettingsAccessor Accessor answering those values for their keys
     */
    private static function storing(array $stored): SettingsAccessor
    {
        return new class ($stored) extends SettingsAccessor {
            /**
             * @param array<string, string> $stored Values the rows hold, by key
             */
            public function __construct(private readonly array $stored)
            {
                parent::__construct(ImpersonationSettingsCatalog::class);
            }

            /**
             * @param string $key Setting key
             * @return mixed The scripted value for a named key, the catalog default otherwise
             */
            public function effectiveValueFor(string $key): mixed
            {
                return $this->stored[$key] ?? parent::effectiveValueFor($key);
            }
        };
    }
}
