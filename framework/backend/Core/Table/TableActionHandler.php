<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Hilos;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\DTO\Table\TableDataDTO;
use Hilos\DTO\Table\TablesPayloadDTO;

/**
 * Handles table-scoped actions: load_page, refresh_snapshot.
 *
 * Uses Hilos::$table to resolve table by key. Returns DTO to send back to client.
 */
class TableActionHandler
{
    /**
     * Handle table action. Returns payload to send to user, or null if not a table action / unknown.
     *
     * @return TableDataDTO|TablesPayloadDTO|null Single table payload, or null if unhandled/error
     */
    public static function handle(string $action, ActionPayloadDTO $dto): TableDataDTO|TablesPayloadDTO|null
    {
        $arr = $dto->toArray();
        $data = $arr[TableActionConstants::PAYLOAD_KEY_DATA] ?? $arr;
        $tableKey = $data[TableActionConstants::PAYLOAD_KEY_TABLE_KEY] ?? '';

        if ($tableKey === '') {
            return null;
        }

        if ($action === TableActionConstants::ACTION_REFRESH_SNAPSHOT) {
            return TablePayloadBuilder::buildOneFull($tableKey);
        }

        if ($action === TableActionConstants::ACTION_LOAD_PAGE) {
            $offset = (int) ($data[TableActionConstants::PAYLOAD_KEY_OFFSET] ?? TableActionConstants::DEFAULT_PAGE_OFFSET);
            $limit = (int) ($data[TableActionConstants::PAYLOAD_KEY_LIMIT] ?? TableActionConstants::DEFAULT_PAGE_LIMIT);
            return TablePayloadBuilder::buildPage($tableKey, $offset, $limit);
        }

        return null;
    }
}
