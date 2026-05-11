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
 * Rules add full entity collections, frontend collections, and table snapshots.
 * The owning PageProjection converts the accumulated state into the
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

    /**
     * Adds a DB collection to the legacy entities full snapshot block.
     *
     * @param string $sourceKey Frontend source key for the collection
     * @param DbCollection $collection DB collection to send as full state
     * @param bool $replaceFull Whether the client should replace existing full state for this key
     */
    public function addEntitiesFull(string $sourceKey, DbCollection $collection, bool $replaceFull = false): void
    {
        $this->entitiesFull[$sourceKey] = $collection;
        if ($replaceFull) {
            $this->entitiesReplaceFullKeys[] = $sourceKey;
        }
    }

    /**
     * Adds frontend rows to the full snapshot block.
     *
     * @param string $frontendKey Frontend collection key
     * @param list<array<string, mixed>> $rows Rows already shaped for the frontend
     * @param bool $replaceFull Whether the client should replace existing full state for this key
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
     * Useful for rules that already build a FrontendChangesDTO and want to fold
     * it into the page snapshot.
     *
     * @param FrontendChangesDTO $changes Full frontend changes to merge
     * @param bool $replaceFull Whether merged full keys should replace client state
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

    /**
     * Adds a table snapshot to the subscribe payload.
     *
     * @param string $tableKey Table key inside Hilos::$table
     * @param TableSnapshotDTO $snapshot Full table snapshot for the subscriber
     */
    public function addTableSnapshot(string $tableKey, TableSnapshotDTO $snapshot): void
    {
        $this->tableSnapshots[$tableKey] = $snapshot;
    }

    /**
     * Builds the legacy entities changes DTO for the subscribe snapshot.
     *
     * @return EntitiesChangesDTO Full DB entity snapshot changes
     */
    public function buildEntitiesChanges(): EntitiesChangesDTO
    {
        return new EntitiesChangesDTO(
            full: $this->entitiesFull,
            replaceFullKeys: array_values(array_unique($this->entitiesReplaceFullKeys)),
        );
    }

    /**
     * Builds the frontend changes DTO for the subscribe snapshot.
     *
     * @return FrontendChangesDTO Full frontend snapshot changes
     */
    public function buildFrontendChanges(): FrontendChangesDTO
    {
        return new FrontendChangesDTO(
            full: $this->frontendFull,
            replaceFullKeys: array_values(array_unique($this->frontendReplaceFullKeys)),
        );
    }

    /**
     * Returns all table snapshots accumulated for this subscription.
     *
     * @return array<string, TableSnapshotDTO>
     */
    public function getTableSnapshots(): array
    {
        return $this->tableSnapshots;
    }

    /**
     * Returns one table snapshot by table key.
     *
     * @param string $tableKey Table key inside Hilos::$table
     * @return ?TableSnapshotDTO Snapshot for the table, or null when absent
     */
    public function getTableSnapshot(string $tableKey): ?TableSnapshotDTO
    {
        return $this->tableSnapshots[$tableKey] ?? null;
    }
}
