<?php

declare(strict_types=1);

namespace Hilos\Pages\Status;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusHistoryData;

/** Read-only completed history; projects bind the framework merged table in PAGE_TABLES. */
abstract class AbstractHilosStatusHistoryPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_STATUS_HISTORY;
    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_HISTORY,
    ];

    public const array READS_DB = [...parent::READS_DB, HilosDbContext::statusIncidents, HilosDbContext::statusMaintenancePeriods];
    public const array READS_RT = [...parent::READS_RT, ProtectedModeRuntime::RT_ITEM];

    /**
     * @param SourceChange $change Committed history or freeze fact
     * @return bool Whether the complete filter catalog must be rebuilt
     */
    public static function observesOverviewChange(SourceChange $change): bool
    {
        return $change->isDb() && in_array($change->sourceKey, [HilosDbContext::statusIncidents, HilosDbContext::statusMaintenancePeriods], true)
            || $change->isRt() && $change->sourceKey === ProtectedModeRuntime::RT_ITEM;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Empty route parameters
     * @return PagePayload Complete filter catalog beside the ordinary initial viewport
     * @throws HilosException When source declarations or catalog contracts are invalid
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [StatusHistoryData::DATA => StatusHistoryData::read()?->toArray()]);
    }

    /** @return array<string, WireField> Nonpersonal filter catalog fields */
    protected function dataFields(): array
    {
        return [StatusHistoryData::DATA => WireField::each(StatusHistoryData::wireFields())];
    }
}
