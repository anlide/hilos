<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsJournalCeilingRule;
use Hilos\Core\Analytics\AnalyticsJournalDirectory;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Database\Settings\SettingsCatalogConstants;
use PHPUnit\Framework\TestCase;

/** The analytics ceiling is a cluster setting each node reads for its own journal. */
final class AnalyticsSettingsCatalogTest extends TestCase
{
    public function testCeilingDefaultAndRuleAgree(): void
    {
        $entry = AnalyticsSettingsCatalog::getCatalog()[AnalyticsSettingsCatalog::JOURNAL_MAX_BYTES];
        self::assertSame(SettingsCatalogConstants::TYPE_INTEGER, $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE]);
        self::assertSame(1073741824, $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
        self::assertSame(AnalyticsJournalCeilingRule::class, $entry[SettingsCatalogConstants::CATALOG_ENTRY_RULE]);
        self::assertNull(AnalyticsJournalCeilingRule::validate($entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]));
        self::assertNull(AnalyticsJournalCeilingRule::validate((string)AnalyticsJournalDirectory::ROTATE_BYTES));
    }

    public function testCeilingRefusesLessThanAFileAndNonnumericInput(): void
    {
        self::assertNotNull(AnalyticsJournalCeilingRule::validate(AnalyticsJournalDirectory::ROTATE_BYTES - 1));
        self::assertNotNull(AnalyticsJournalCeilingRule::validate('not a size'));
    }
}
