<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;

/**
 * Canonical DB change payload for {@see SignalTypeConstants::EMIT_DB_CHANGE}.
 *
 * Serializable for worker-to-daemon transport; rebuilds {@see TableMutationSignalData} on the daemon.
 */
final class EmitDbChangeSignalData extends BaseDTO implements SignalDataInterface
{
    public const string FIELD_ENTITY_ID = 'entityId';

    public const string FIELD_TABLE_KEY = 'tableKey';

    public const string FIELD_MUTATION = 'mutation';

    public const string FIELD_EXCLUDE_ACCEPT_KEY = 'excludeAcceptKey';

    public const string FIELD_ACTOR_USER_ID = 'actorUserId';

    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /**
     * @param string $entityId Domain entity id (e.g. user id) for subscription routing
     * @param string $tableKey Table key for {@see TableMutationSignalData}
     * @param array<string, mixed> $mutationArray {@see TableMutationEntry::toArray()}
     * @param ?string $excludeAcceptKey Initiator connection to skip on broadcast leg
     * @param ?int $actorUserId Optional acting user id (audit / future rules)
     * @param ?string $collectionKey Optional DB collection key for future rules
     */
    public function __construct(
        public readonly string $entityId,
        public readonly string $tableKey,
        public readonly array $mutationArray,
        public readonly ?string $excludeAcceptKey = null,
        public readonly ?int $actorUserId = null,
        public readonly ?string $collectionKey = null,
    ) {
    }

    /**
     * Build emit payload from an existing table mutation signal.
     */
    public static function fromTableMutationSignal(
        string $entityId,
        TableMutationSignalData $signal,
        ?string $excludeAcceptKey,
        ?int $actorUserId = null,
        ?string $collectionKey = null,
    ): self {
        return new self(
            entityId: $entityId,
            tableKey: $signal->tableKey,
            mutationArray: $signal->mutation->toArray(),
            excludeAcceptKey: $excludeAcceptKey,
            actorUserId: $actorUserId,
            collectionKey: $collectionKey,
        );
    }

    public function toTableMutationSignalData(): TableMutationSignalData
    {
        $m = $this->mutationArray;
        $typeRaw = $m[TableConstants::MUTATION_KEY_TYPE] ?? '';
        $type = TableMutationType::from((string) $typeRaw);

        return new TableMutationSignalData(
            $this->tableKey,
            new TableMutationEntry(
                type: $type,
                rowId: $m[TableConstants::MUTATION_KEY_ROW_ID] ?? 0,
                row: $m[TableConstants::MUTATION_KEY_ROW] ?? null,
            ),
        );
    }

    public function toArray(): array
    {
        return [
            self::FIELD_ENTITY_ID => $this->entityId,
            self::FIELD_TABLE_KEY => $this->tableKey,
            self::FIELD_MUTATION => $this->mutationArray,
            self::FIELD_EXCLUDE_ACCEPT_KEY => $this->excludeAcceptKey,
            self::FIELD_ACTOR_USER_ID => $this->actorUserId,
            self::FIELD_COLLECTION_KEY => $this->collectionKey,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            entityId: (string) ($data[self::FIELD_ENTITY_ID] ?? ''),
            tableKey: (string) ($data[self::FIELD_TABLE_KEY] ?? ''),
            mutationArray: $data[self::FIELD_MUTATION] ?? [],
            excludeAcceptKey: isset($data[self::FIELD_EXCLUDE_ACCEPT_KEY])
                ? (string) $data[self::FIELD_EXCLUDE_ACCEPT_KEY]
                : null,
            actorUserId: isset($data[self::FIELD_ACTOR_USER_ID]) ? (int) $data[self::FIELD_ACTOR_USER_ID] : null,
            collectionKey: isset($data[self::FIELD_COLLECTION_KEY])
                ? (string) $data[self::FIELD_COLLECTION_KEY]
                : null,
        );
    }
}
