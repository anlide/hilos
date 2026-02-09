<?php

declare(strict_types=1);

namespace Hilos\DTO;

use Hilos\Database\Idea\IdeaCollection;
use Hilos\Exception\Database\Object\ObjectGetIdStringNotImplementedException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotManualException;

/**
 * EntitiesChangesDTO - Entity changes payload
 *
 * Holds entity-related changes for transport.
 * Full block stores one IdeaCollection per key; serialization (toArray with toFrontend/idAsIndex)
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
     * @var array<string, IdeaCollection>
     */
    private readonly array $full;

    /**
     * @param array<string, IdeaCollection> $full Full snapshot: collection key => one collection
     * @param array<string, array<int, array<string, mixed>>> $updates
     * @param array<string, array<int, int|string>> $deleted
     */
    public function __construct(
        array $full = [],
        public readonly array $updates = [],
        public readonly array $deleted = [],
    ) {
        $this->full = $full;
    }

    /**
     * Return new DTO with full[$key] set to the given collection.
     *
     * @param string $key Collection key (e.g. 'events', 'users')
     * @param IdeaCollection $collection Collection for this key
     * @return static New DTO instance
     */
    public function withFull(string $key, IdeaCollection $collection): static
    {
        $full = $this->full;
        $full[$key] = $collection;
        return new static(full: $full, updates: $this->updates, deleted: $this->deleted);
    }

    /**
     * Return new DTO with items from $toAppend merged into full[$collection].
     * Only items not already in the existing collection (by ID) are added.
     *
     * @param string $collection Collection key (e.g. 'events', 'users')
     * @param IdeaCollection $toAppend Collection whose items to merge in
     * @return static New DTO instance
     * @throws IdeaCollectionNotManualException If $toAppend is not manual (cannot be merged)
     * @throws ObjectGetIdStringNotImplementedException If any item in $toAppend has an Object that does not implement getIdString() (required for merging)
     */
    public function withFullAppended(string $collection, IdeaCollection $toAppend): static
    {
        $existing = $this->full[$collection] ?? null;
        $merged = $existing !== null ? $existing->mergeWith($toAppend) : $toAppend;
        $full = $this->full;
        $full[$collection] = $merged;
        return new static(full: $full, updates: $this->updates, deleted: $this->deleted);
    }

    /**
     * Convert DTO to array.
     * Serializes full from IdeaCollections with idAsIndex: false, toFrontend: true.
     *
     * @return array DTO data as array
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

        return $payload;
    }

    /**
     * Create DTO from array.
     * Full cannot be restored from array (IdeaCollection instances required); full will be empty.
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            full: [],
            updates: isset($data['updates']) && is_array($data['updates']) ? $data['updates'] : [],
            deleted: isset($data['deleted']) && is_array($data['deleted']) ? $data['deleted'] : [],
        );
    }
}
