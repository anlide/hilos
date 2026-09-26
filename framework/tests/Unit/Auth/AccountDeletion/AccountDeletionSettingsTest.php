<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountDeletion;

use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the grace period of account deletion as it is read (HIL-302).
 *
 * With no database mounted a settings accessor answers the catalog default, so the stored
 * value is scripted through an accessor that answers it for the one key.
 */
final class AccountDeletionSettingsTest extends TestCase
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

    public function testNoSettingsAccessorAnswersTheDefault(): void
    {
        Hilos::$setting = null;

        self::assertSame(30, AccountDeletionSettings::graceDays());
    }

    public function testACatalogWithoutTheKeyAnswersTheDefault(): void
    {
        Hilos::$setting = new SettingsAccessor(StepUpSettingsCatalog::class);

        self::assertSame(AccountDeletionSettings::DEFAULT_GRACE_DAYS, AccountDeletionSettings::graceDays());
    }

    public function testTheCatalogDefaultIsThirtyDays(): void
    {
        Hilos::$setting = new SettingsAccessor(AccountDeletionSettingsCatalog::class);

        self::assertSame(30, AccountDeletionSettings::graceDays());
    }

    public function testAStoredValueIsRead(): void
    {
        Hilos::$setting = self::storing('7');

        self::assertSame(7, AccountDeletionSettings::graceDays());
    }

    public function testAStoredValueOutsideTheBoundsIsHeldWithinThem(): void
    {
        Hilos::$setting = self::storing('0');
        self::assertSame(1, AccountDeletionSettings::graceDays());

        Hilos::$setting = self::storing('1000');
        self::assertSame(365, AccountDeletionSettings::graceDays());
    }

    /**
     * @param string $stored Value the grace-period row holds
     * @return SettingsAccessor Accessor answering that value for the grace-period key
     */
    private static function storing(string $stored): SettingsAccessor
    {
        return new class ($stored) extends SettingsAccessor {
            /**
             * @param string $stored Value the grace-period row holds
             */
            public function __construct(private readonly string $stored)
            {
                parent::__construct(AccountDeletionSettingsCatalog::class);
            }

            /**
             * @param string $key Setting key
             * @return mixed The scripted value for the grace-period key, the catalog default otherwise
             */
            public function effectiveValueFor(string $key): mixed
            {
                return $key === AccountDeletionSettings::GRACE_DAYS_KEY ? $this->stored : parent::effectiveValueFor($key);
            }
        };
    }
}
