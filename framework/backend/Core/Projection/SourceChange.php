<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\BaseDTO;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * DB/RT sync fact that can invalidate a worker-local projection.
 *
 * Describes the backend source state, not the frontend payload. A project
 * page/group projection decides how one source fact maps to wire signals.
 * Serializable as a BaseDTO so the fact can also travel inside
 * EmitDbChangeSignalData on the worker-to-daemon transport.
 */
final class SourceChange extends BaseDTO
{
    public const string KIND_DB = 'db';
    public const string KIND_RT = 'rt';

    public const string FIELD_KIND = 'kind';
    public const string FIELD_SOURCE_KEY = 'sourceKey';
    public const string FIELD_SOURCE_ID = 'sourceId';
    public const string FIELD_MUTATION_TYPE = 'mutationType';
    public const string FIELD_ROW = 'row';

    /**
     * @param string $kind Source kind: KIND_DB or KIND_RT
     * @param string $sourceKey DB collection key or RT collection key
     * @param string $sourceId Row id or runtime state id, always serialized as string
     * @param TableMutationType $mutationType Source mutation type
     * @param array<string, mixed> $row Full row for create, diff for update, previous row for delete when available
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $sourceKey,
        public readonly string $sourceId,
        public readonly TableMutationType $mutationType,
        public readonly array $row = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row Full persisted row
     */
    public static function dbCreated(string $collectionKey, string $idString, array $row): self
    {
        return new self(self::KIND_DB, $collectionKey, $idString, TableMutationType::Create, $row);
    }

    /**
     * @param array<string, mixed> $row Changed columns
     */
    public static function dbUpdated(string $collectionKey, string $idString, array $row): self
    {
        return new self(self::KIND_DB, $collectionKey, $idString, TableMutationType::Update, $row);
    }

    /**
     * @param array<string, mixed> $row Previous persisted row, when the source can provide it
     */
    public static function dbDeleted(string $collectionKey, string $idString, array $row = []): self
    {
        return new self(self::KIND_DB, $collectionKey, $idString, TableMutationType::Delete, $row);
    }

    /**
     * @param array<string, mixed> $row Full runtime row
     */
    public static function rtCreated(string $collectionKey, string $stateId, array $row): self
    {
        return new self(self::KIND_RT, $collectionKey, $stateId, TableMutationType::Create, $row);
    }

    /**
     * @param array<string, mixed> $row Changed runtime fields
     */
    public static function rtUpdated(string $collectionKey, string $stateId, array $row): self
    {
        return new self(self::KIND_RT, $collectionKey, $stateId, TableMutationType::Update, $row);
    }

    /**
     * @param array<string, mixed> $row Previous runtime row, when the source can provide it
     */
    public static function rtDeleted(string $collectionKey, string $stateId, array $row = []): self
    {
        return new self(self::KIND_RT, $collectionKey, $stateId, TableMutationType::Delete, $row);
    }

    /**
     * Checks whether this change originated from a DB collection sync.
     */
    public function isDb(): bool
    {
        return $this->kind === self::KIND_DB;
    }

    /**
     * Checks whether this change originated from an RT collection sync.
     */
    public function isRt(): bool
    {
        return $this->kind === self::KIND_RT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_KIND => $this->kind,
            self::FIELD_SOURCE_KEY => $this->sourceKey,
            self::FIELD_SOURCE_ID => $this->sourceId,
            self::FIELD_MUTATION_TYPE => $this->mutationType->value,
            self::FIELD_ROW => $this->row,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $sourceId = $data[self::FIELD_SOURCE_ID] ?? '';
        $row = $data[self::FIELD_ROW] ?? [];

        return new self(
            kind: (string) ($data[self::FIELD_KIND] ?? self::KIND_DB),
            sourceKey: (string) ($data[self::FIELD_SOURCE_KEY] ?? ''),
            sourceId: is_string($sourceId) || is_int($sourceId) ? (string) $sourceId : '',
            mutationType: TableMutationType::tryFrom((string) ($data[self::FIELD_MUTATION_TYPE] ?? '')) ?? TableMutationType::Update,
            row: is_array($row) ? $row : [],
        );
    }
}
