<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\TableType;
use Hilos\Database\Idea\Idea;
use Hilos\DTO\Table\TableDataDTO;
use Hilos\DTO\Table\TablesPayloadDTO;

/**
 * Builds table payload for subscription (initial load) or for a single table (refresh / page).
 *
 * Uses Idea::$table to resolve table data sources by key.
 * When Idea::$table is null, still returns payload with requested keys and empty rows so the client always gets the structure.
 */
class TablePayloadBuilder
{
    /**
     * Build full payload for several tables (e.g. on subscribe).
     * Each table: loadFull(), totalCount, meta.
     * When Idea::$table is null, returns placeholder entries (empty rows) for each key so response always has tables.key.
     *
     * @param array<string> $tableKeys
     */
    public static function buildFull(array $tableKeys): TablesPayloadDTO
    {
        $tables = [];
        $table = Idea::$table;
        foreach ($tableKeys as $key) {
            if ($table !== null) {
                $source = $table->get($key);
                if ($source !== null) {
                    $tables[$key] = new TableDataDTO(
                        type: $source->getType(),
                        rows: $source->loadFull(),
                        supportsSnapshot: $source->supportsSnapshot(),
                        totalCount: $source->getTotalCount(),
                        isPage: false,
                        offset: 0,
                        limit: 0,
                    );
                    continue;
                }
            }
            $tables[$key] = new TableDataDTO(
                type: TableType::Entity,
                rows: [],
                supportsSnapshot: true,
                totalCount: 0,
                isPage: false,
                offset: 0,
                limit: 0,
            );
        }
        return new TablesPayloadDTO(tables: $tables);
    }

    /**
     * Build payload for one table — one page (for pagination request).
     */
    public static function buildPage(string $tableKey, int $offset, int $limit): ?TableDataDTO
    {
        $table = Idea::$table;
        if ($table === null) {
            return null;
        }
        $source = $table->get($tableKey);
        if ($source === null) {
            return null;
        }
        return new TableDataDTO(
            key: $tableKey,
            type: $source->getType(),
            rows: $source->loadPage($offset, $limit),
            supportsSnapshot: $source->supportsSnapshot(),
            totalCount: $source->getTotalCount(),
            isPage: true,
            offset: $offset,
            limit: $limit,
        );
    }

    /**
     * Build payload for one table — full (e.g. refresh_snapshot).
     */
    public static function buildOneFull(string $tableKey): ?TableDataDTO
    {
        $table = Idea::$table;
        if ($table === null) {
            return null;
        }
        $source = $table->get($tableKey);
        if ($source === null) {
            return null;
        }
        return new TableDataDTO(
            key: $tableKey,
            type: $source->getType(),
            rows: $source->loadFull(),
            supportsSnapshot: $source->supportsSnapshot(),
            totalCount: $source->getTotalCount(),
            isPage: false,
            offset: 0,
            limit: 0,
        );
    }
}
