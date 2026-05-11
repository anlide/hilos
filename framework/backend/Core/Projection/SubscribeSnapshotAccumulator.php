<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Accumulator for projection rules contributing to one subscribe snapshot.
 *
 * Rules write into this accumulator via {@see self::addEntitiesFull()},
 * {@see self::addFrontendFull()}, and {@see self::addTableSnapshot()}.
 * The owning {@see PageProjection} converts the accumulated state into the
 * page-specific wire DTO inside its wrap method.
 */
final class SubscribeSnapshotAccumulator
{
    /** @var array<string, DbCollection> */
    private array $entitiesFull = [];

    /** @var list<string> */
    private array $entitiesReplaceFullKeys = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $frontendFull = [];

    /** @var list<string> */
    private array $frontendReplaceFullKeys = [];

    /** @var array<string, TableSnapshotDTO> */
    private array $tableSnapshots = [];

    public function addEntitiesFull(string $sourceKey, DbCollection $collection, bool $replaceFull = false): void
    {
        $this->entitiesFull[$sourceKey] = $collection;
        if ($replaceFull) {
            $this->entitiesReplaceFullKeys[] = $sourceKey;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function addFrontendFull(string $frontendKey, array $rows, bool $replaceFull = true): void
    {
        $this->frontendFull[$frontendKey] = $rows;
        if ($replaceFull) {
            $this->frontendReplaceFullKeys[] = $frontendKey;
        }
    }

    /**
     * Merges another frontend changes payload into the accumulator's full block.
     *
     * Useful for rules that already build a {@see FrontendChangesDTO} (e.g. a
     * connection-local snapshot helper) and want to fold it into the page snapshot.
     */
    public function mergeFrontendFull(FrontendChangesDTO $changes, bool $replaceFull = true): void
    {
        $payload = $changes->toArray();
        $full = $payload['full'] ?? [];
        if (!is_array($full)) {
            return;
        }
        foreach ($full as $key => $rows) {
            if (!is_string($key) || !is_array($rows)) {
                continue;
            }
            $this->addFrontendFull($key, array_values(array_filter(
                $rows,
                static fn($row): bool => is_array($row),
            )), $replaceFull);
        }
    }

    public function addTableSnapshot(string $tableKey, TableSnapshotDTO $snapshot): void
    {
        $this->tableSnapshots[$tableKey] = $snapshot;
    }

    public function buildEntitiesChanges(): EntitiesChangesDTO
    {
        return new EntitiesChangesDTO(
            full: $this->entitiesFull,
            replaceFullKeys: array_values(array_unique($this->entitiesReplaceFullKeys)),
        );
    }

    public function buildFrontendChanges(): FrontendChangesDTO
    {
        return new FrontendChangesDTO(
            full: $this->frontendFull,
            replaceFullKeys: array_values(array_unique($this->frontendReplaceFullKeys)),
        );
    }

    /**
     * @return array<string, TableSnapshotDTO>
     */
    public function getTableSnapshots(): array
    {
        return $this->tableSnapshots;
    }

    public function getTableSnapshot(string $tableKey): ?TableSnapshotDTO
    {
        return $this->tableSnapshots[$tableKey] ?? null;
    }
}
