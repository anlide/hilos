<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountDeletion;

use Hilos\Auth\AccountDeletion\AccountDeletionGraceDaysRule;
use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Database\Settings\SettingsCatalogConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule the grace period of account deletion is written through (HIL-302).
 */
final class AccountDeletionGraceDaysRuleTest extends TestCase
{
    private const string REFUSAL = 'The grace period must be between 1 and 365 days';

    public function testTheBoundsAreAccepted(): void
    {
        self::assertNull(AccountDeletionGraceDaysRule::validate(1));
        self::assertNull(AccountDeletionGraceDaysRule::validate(30));
        self::assertNull(AccountDeletionGraceDaysRule::validate('365'));
    }

    public function testAValueOutsideTheBoundsIsRefused(): void
    {
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate(0));
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate('0'));
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate(366));
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate(-1));
    }

    public function testAValueThatIsNotAWholeNumberIsRefused(): void
    {
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate('7.5'));
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate(7.0));
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate(''));
        self::assertSame(self::REFUSAL, AccountDeletionGraceDaysRule::validate(null));
    }

    public function testTheCatalogEntryNamesTheRuleAndTheDefault(): void
    {
        $entry = AccountDeletionSettingsCatalog::getCatalog()[AccountDeletionSettings::GRACE_DAYS_KEY];

        self::assertSame(SettingsCatalogConstants::TYPE_INTEGER, $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
        self::assertSame(30, $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
        self::assertSame(AccountDeletionGraceDaysRule::class, $entry[SettingsCatalogConstants::CATALOG_ENTRY_RULE]);
        self::assertSame('auth.account_deletion.grace_days', AccountDeletionSettings::GRACE_DAYS_KEY);
    }
}
