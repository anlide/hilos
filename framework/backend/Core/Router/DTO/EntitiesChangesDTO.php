<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * EntitiesChangesDTO - Entity changes payload.
 *
 * Holds entity-related changes for transport.
 * Full block stores one DbCollection per key; serialization (toArray with toFrontend/idAsIndex)
 * happens only in toArray().
 * Updates and deleted remain plain arrays.
 *
 * @package Hilos\DTO
 */
class EntitiesChangesDTO extends BaseDTO
{
    /**
     * One collection per key.
     *
     * @var array<string, DbCollection>
     */
    private readonly array $full;

    /**
     * Collection keys for which full is a full replace (not merge). Empty = current append behavior.
     *
     * @var array<int, string>
     */
    private readonly array $replaceFullKeys;

    /**
     * Creates entities changes DTO.
     *
     * @param array<string, DbCollection> $full Full snapshot: collection key => one collection
     * @param array<string, array<int, array<string, mixed>>> $updates Per-collection updates
     * @param array<string, array<int, int|string>> $deleted Per-collection deleted IDs
     * @param array<int, string> $replaceFullKeys Keys where full means replace entire collection
     */
    public function __construct(
        array $full = [],
        public readonly array $updates = [],
        public readonly array $deleted = [],
        array $replaceFullKeys = [],
    ) {
        $this->full = $full;
        $this->replaceFullKeys = $replaceFullKeys;
    }

    /**
     * Return new DTO with full[$key] set to the given collection.
     *
     * @param string $key Collection key (e.g. 'events', 'users')
     * @param DbCollection $collection Collection for this key
     * @return static New DTO instance
     */
    public function withFull(string $key, DbCollection $collection): static
    {
        $full = $this->full;
        $full[$key] = $collection;
        return new static(full: $full, updates: $this->updates, deleted: $this->deleted, replaceFullKeys: $this->replaceFullKeys);
    }

    /**
     * Return new DTO with items from $toAppend merged into full[$collection].
     * Only items not already in the existing collection (by ID) are added.
     *
     * @param string $collection Collection key (e.g. 'events', 'users')
     * @param DbCollection $toAppend Collection whose items to merge in
     * @return static New DTO instance
     * @throws CollectionNotManualException If $toAppend is not manual (cannot be merged)
     * @throws ObjectGetIdStringNotImplementedException If any item in $toAppend has an Object that does not implement getIdString() (required for merging)
     */
    public function withFullAppended(string $collection, DbCollection $toAppend): static
    {
        $existing = $this->full[$collection] ?? null;
        $merged = $existing !== null ? $existing->mergeWith($toAppend) : $toAppend;
        $full = $this->full;
        $full[$collection] = $merged;
        return new static(full: $full, updates: $this->updates, deleted: $this->deleted, replaceFullKeys: $this->replaceFullKeys);
    }

    /**
     * Converts DTO to array.
     *
     * Serializes full from DbCollections with idAsIndex: false, toFrontend: true.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->full !== []) {
            $payload['full'] = [];
            foreach ($this->full as $key => $coll) {
                $payload['full'][$key] = $coll->toArray(idAsIndex: false, toFrontend: true);
            }
        }

        if ($this->updates !== []) {
            $payload['updates'] = $this->updates;
        }

        if ($this->deleted !== []) {
            $payload['deleted'] = $this->deleted;
        }

        if ($this->replaceFullKeys !== []) {
            $payload['replaceFull'] = $this->replaceFullKeys;
        }

        return $payload;
    }

    /**
     * Creates DTO from array.
     *
     * Full cannot be restored from array (DbCollection instances required); full will be empty.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $replaceFullKeys = [];
        if (isset($data['replaceFull']) && is_array($data['replaceFull'])) {
            foreach ($data['replaceFull'] as $key) {
                if (is_string($key)) {
                    $replaceFullKeys[] = $key;
                }
            }
        }

        return new static(
            full: [],
            updates: isset($data['updates']) && is_array($data['updates']) ? $data['updates'] : [],
            deleted: isset($data['deleted']) && is_array($data['deleted']) ? $data['deleted'] : [],
            replaceFullKeys: $replaceFullKeys,
        );
    }
}
