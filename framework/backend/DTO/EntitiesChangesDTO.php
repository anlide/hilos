<?php

declare(strict_types=1);

namespace Hilos\DTO;

/**
 * EntitiesChangesDTO - Entity changes payload
 *
 * Holds entity-related changes for transport.
 * Each block is optional and can be partially filled.
 *
 * @package Hilos\DTO
 */
class EntitiesChangesDTO extends BaseDTO
{
    /**
     * @param array<string, array<int, array<string, mixed>>> $full
     * @param array<string, array<int, array<string, mixed>>> $updates
     * @param array<string, array<int, int|string>> $deleted
     */
    public function __construct(
        public readonly array $full = [],
        public readonly array $updates = [],
        public readonly array $deleted = [],
    ) {
    }

    /**
     * Return new DTO with one item appended to full[$collection].
     * Does not serialize/deserialize; only merges the target collection.
     *
     * @param string $collection Collection key (e.g. 'events', 'users')
     * @param array<string, mixed> $item Item to append
     * @return static New DTO instance
     */
    public function withFullAppended(string $collection, array $item): static
    {
        $full = $this->full;
        $list = $full[$collection] ?? [];
        $list[] = $item;
        $full[$collection] = $list;
        return new static(full: $full, updates: $this->updates, deleted: $this->deleted);
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->full !== []) {
            $payload['full'] = $this->full;
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
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            full: isset($data['full']) && is_array($data['full']) ? $data['full'] : [],
            updates: isset($data['updates']) && is_array($data['updates']) ? $data['updates'] : [],
            deleted: isset($data['deleted']) && is_array($data['deleted']) ? $data['deleted'] : [],
        );
    }
}
