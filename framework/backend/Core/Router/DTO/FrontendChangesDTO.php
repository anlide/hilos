<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;

/**
 * Frontend state changes payload.
 *
 * Carries explicit client-side state collection snapshots and mutations. Unlike
 * {@see EntitiesChangesDTO}, these collections do not need to be backed by
 * DbCollection and may be DB-backed, runtime-backed, or derived.
 */
final class FrontendChangesDTO extends BaseDTO
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private readonly array $full;

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private readonly array $updates;

    /**
     * @var array<string, list<int|string>>
     */
    private readonly array $deleted;

    /**
     * @var list<string>
     */
    private readonly array $replaceFullKeys;

    /**
     * Creates frontend state changes DTO.
     *
     * @param array<string, list<array<string, mixed>>> $full Full snapshots by frontend collection key
     * @param array<string, list<array<string, mixed>>> $updates Incremental updates by frontend collection key
     * @param array<string, list<int|string>> $deleted Deleted item IDs by frontend collection key
     * @param list<string> $replaceFullKeys Keys where full means replace entire collection
     */
    public function __construct(
        array $full = [],
        array $updates = [],
        array $deleted = [],
        array $replaceFullKeys = [],
    ) {
        $this->full = $full;
        $this->updates = $updates;
        $this->deleted = $deleted;
        $this->replaceFullKeys = $replaceFullKeys;
    }

    /**
     * Converts DTO to transport payload.
     *
     * @return array<string, mixed> Frontend state changes payload
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

        if ($this->replaceFullKeys !== []) {
            $payload['replaceFull'] = $this->replaceFullKeys;
        }

        return $payload;
    }

    /**
     * Creates DTO from transport payload.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            full: self::readItemCollections($data['full'] ?? null),
            updates: self::readItemCollections($data['updates'] ?? null),
            deleted: self::readDeletedCollections($data['deleted'] ?? null),
            replaceFullKeys: self::readStringList($data['replaceFull'] ?? null),
        );
    }

    /**
     * Reads a string-keyed map of item payload lists.
     *
     * @param mixed $value Raw transport value
     * @return array<string, list<array<string, mixed>>>
     */
    private static function readItemCollections(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $collections = [];
        foreach ($value as $collectionKey => $items) {
            if (!is_string($collectionKey) || !is_array($items)) {
                continue;
            }

            $payloadItems = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $payloadItems[] = $item;
                }
            }

            $collections[$collectionKey] = $payloadItems;
        }

        return $collections;
    }

    /**
     * Reads a string-keyed map of deleted IDs.
     *
     * @param mixed $value Raw transport value
     * @return array<string, list<int|string>>
     */
    private static function readDeletedCollections(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $collections = [];
        foreach ($value as $collectionKey => $ids) {
            if (!is_string($collectionKey) || !is_array($ids)) {
                continue;
            }

            $deletedIds = [];
            foreach ($ids as $id) {
                if (is_int($id) || is_string($id)) {
                    $deletedIds[] = $id;
                }
            }

            $collections[$collectionKey] = $deletedIds;
        }

        return $collections;
    }

    /**
     * Reads a list of string keys.
     *
     * @param mixed $value Raw transport value
     * @return list<string>
     */
    private static function readStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
