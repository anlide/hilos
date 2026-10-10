<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\ChangeLog\ChangeLogSectionReader;
use Hilos\HilosException;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;

/**
 * Change Log section page served by the hilos_change_log agent.
 * Its subscription answers with a snapshot of the journal overview.
 */
abstract class AbstractHilosChangeLogDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG;

    public const PageReach REACH = PageReach::ROUTE;

    public const string OVERVIEW = 'changeLogOverview';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG,
    ];

    /**
     * @param string $acceptKey Subscribing connection, unused for this shared snapshot
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload Journal overview for the opening response
     * @throws HilosException When the journal or live schema cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $overview = new ChangeLogSectionReader()->overview();
        return new PagePayload(data: [self::OVERVIEW => [
            'journalEntries' => $overview->journalEntries,
            'journalBytes' => $overview->journalBytes,
            'oldestAt' => $overview->oldestAt === null
                ? null : HilosChangeLogTableParts::displayTime($overview->oldestAt),
            'journaledTables' => $overview->journaledTables,
            'liveTables' => $overview->liveTables,
        ]]);
    }

    /** @return array<string, WireField> The journal counts, time, and table names are not personal */
    protected function dataFields(): array
    {
        return [self::OVERVIEW => WireField::notPersonal()];
    }
}
