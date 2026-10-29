<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Status\AbstractHilosStatusHistoryPage;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusHistoryData;
use Hilos\Tests\Unit\Status\StatusReadTestCase;

/** Complete nonpersonal catalog and availability over the two independent typed scalar boundaries. */
final class StatusHistoryProjectionTest extends StatusReadTestCase
{
    public function testCatalogKeepsCurrentNamesAndDistinctRetiredKeysInStableOrder(): void
    {
        $this->database->statusIncidents->historicalKeys = ['retired', 'zeta', 'retired'];
        $this->database->statusMaintenancePeriods->historicalKeys = ['old-work', 'alpha', 'retired'];
        $data = StatusHistoryData::read()->toArray();
        self::assertSame(['components' => [
            ['componentKey' => 'zeta', 'name' => 'Zeta API'],
            ['componentKey' => 'alpha', 'name' => 'Alpha mail'],
            ['componentKey' => 'old-work', 'name' => 'old-work'],
            ['componentKey' => 'retired', 'name' => 'retired'],
        ]], $data);
        self::assertSame($data, ViewerFields::hide($data, StatusHistoryData::wireFields(), static fn (): bool => false));
    }

    public function testKnownEmptyHistoryStillHasTheCompleteCurrentCatalog(): void
    {
        self::assertSame(['zeta', 'alpha'], array_column(StatusHistoryData::read()->components, 'componentKey'));
    }

    public function testEitherUnavailableHalfRefusesTheCompleteCatalog(): void
    {
        $this->database->statusIncidents->historicalKeys = ['retired'];
        $this->database->statusMaintenancePeriods->unavailable = true;
        self::assertNull(StatusHistoryData::read());
        $this->database->statusMaintenancePeriods->unavailable = false;
        $this->database->statusIncidents->unavailable = true;
        self::assertNull(StatusHistoryData::read());
    }

    public function testFreezeAndMissingSourcesNeverReachTheDatabase(): void
    {
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        self::assertNull(StatusHistoryData::read());
        self::assertSame(0, $this->database->statusIncidents->historyReads);
        self::assertSame(0, $this->database->statusMaintenancePeriods->historyReads);
    }

    public function testProductionPageDeclaresOnlyReadOnlyRouteAndItsSources(): void
    {
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosStatusHistoryPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosStatusHistoryPage::REACH);
        self::assertSame([], AbstractHilosStatusHistoryPage::ACTIONS);
        self::assertSame([], AbstractHilosStatusHistoryPage::SIGNALS);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_HISTORY,
            AbstractHilosStatusHistoryPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertContains(HilosDbContext::statusIncidents, AbstractHilosStatusHistoryPage::READS_DB);
        self::assertContains(HilosDbContext::statusMaintenancePeriods, AbstractHilosStatusHistoryPage::READS_DB);
        self::assertContains(ProtectedModeRuntime::RT_ITEM, AbstractHilosStatusHistoryPage::READS_RT);
        self::assertSame([StatusHistoryData::components], array_keys(StatusHistoryData::wireFields()));
        self::assertInstanceOf(WireField::class, StatusHistoryData::wireFields()[StatusHistoryData::components]);
    }
}
