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
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Change Log noisy-columns page served by the hilos_change_log agent.
 * Its subscription includes the live noisy-column snapshot.
 */
abstract class AbstractHilosChangeLogNoisyPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_NOISY;

    public const PageReach REACH = PageReach::ROUTE;

    public const string NOISY_COLUMNS = 'changeLogNoisyColumns';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_NOISY,
    ];

    /**
     * @param string $acceptKey Subscribing connection, unused for this schema snapshot
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload Noisy-column metadata for the opening response
     * @throws HilosException When live schema or journal column placement cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $columns = [];
        foreach (Hilos::$db->changeLogTables->sectionNoisyColumns() as $column) {
            $columns[] = [
                ChangeLogNoisyColumn::table => $column->table,
                ChangeLogNoisyColumn::column => $column->column,
                ChangeLogNoisyColumn::reason => $column->reason,
            ];
        }
        return new PagePayload(data: [self::NOISY_COLUMNS => $columns]);
    }

    /** @return array<string, WireField> Table names, column names, and declared reasons are schema metadata */
    protected function dataFields(): array
    {
        return [self::NOISY_COLUMNS => WireField::notPersonal()];
    }
}
