<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\ChangeLog\Section\ChangeLogColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogTrigger;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogTablePageSubscribeParams;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;

/**
 * Change Log single-table page served by the hilos_change_log agent.
 * Its subscription answers with a snapshot of one live table: its journal declaration and activity,
 * its columns in live schema order with their placement, and its service triggers.
 * A table outside the journal answers the same section with no placement and no triggers.
 */
abstract class AbstractHilosChangeLogTablePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SECTION = 'changeLogTable';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
    ];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When the table name is absent or empty
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        HilosChangeLogTablePageSubscribeParams::fromPageRouteParams($params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused for this schema snapshot
     * @param PageRouteParams $params Raw route parameters
     * @return PagePayload One live table's header, columns, and service triggers for the opening response
     * @throws MissingPageRouteParamException When the table name is absent or empty
     * @throws PageResourceNotFoundException When no live table has this name
     * @throws HilosException When schema, placement, or trigger files cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $route = HilosChangeLogTablePageSubscribeParams::fromPageRouteParams($params);
        $detail = Hilos::$db->changeLogTables->sectionTable($route->tableId);
        if ($detail === null) {
            throw new PageResourceNotFoundException('No such table');
        }

        return new PagePayload(data: [self::SECTION => [
            'name' => $detail->summary->name,
            'owner' => $detail->summary->owner->value,
            'journaled' => $detail->summary->journaled,
            'changesLast24h' => $detail->summary->changesLast24h,
            'lastChangeAt' => $detail->summary->lastChangeAt === null
                ? null : HilosChangeLogTableParts::displayTime($detail->summary->lastChangeAt),
            'columns' => array_map(static fn(ChangeLogColumn $column): array => [
                'name' => $column->name,
                'sqlType' => $column->sqlType,
                'mode' => $column->mode?->value,
                'storage' => $column->storage,
                'noiseReason' => $column->noiseReason,
            ], $detail->columns),
            'triggers' => array_map(static fn(ChangeLogTrigger $trigger): array => [
                'name' => $trigger->name,
                'event' => $trigger->event,
                'table' => $trigger->table,
                'validFromMigration' => $trigger->validFromMigration,
            ], $detail->triggers),
        ]]);
    }

    /** @return array<string, WireField> Schema metadata, placement, trigger identities, and write counts are not personal */
    protected function dataFields(): array
    {
        return [self::SECTION => WireField::notPersonal()];
    }
}
