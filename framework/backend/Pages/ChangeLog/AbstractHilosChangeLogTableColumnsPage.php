<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Hilos;
use Hilos\HilosException;

/** Answers with the shared live-table section under its own route identity. */
abstract class AbstractHilosChangeLogTableColumnsPage extends AbstractHilosChangeLogTableContextPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE_COLUMNS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_COLUMNS,
    ];

    protected const bool REQUIRE_JOURNALED = true;

    /**
     * @param PageRouteParams $ownerParams Table owner's route parameters
     * @return bool Whether the live table is journaled
     * @throws HilosException When table metadata cannot be read
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        $tableId = $ownerParams->getString(HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID);
        return $tableId !== null && $tableId !== ''
            && Hilos::$db->changeLogTables->sectionTable($tableId)?->summary->journaled === true;
    }
}
