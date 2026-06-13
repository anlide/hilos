<?php

declare(strict_types=1);

namespace Hilos\Core\Source;

use Hilos\BaseDTO;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * DB/RT sync fact that can invalidate worker-local browser state.
 *
 * Describes the backend source state, not the frontend payload. A project
 * browser context decides how one source fact maps to wire
 * signals.
 * Serializable as a BaseDTO so the fact can also travel inside worker-to-daemon
 * transport payloads.
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
     * Creates a source fact for one DB or RT collection mutation.
     *
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
     * Creates a DB create source fact.
     *
     * @param string $collectionKey DB collection key
     * @param string $idString Created row id serialized as a string
     * @param array<string, mixed> $row Full persisted row
     * @return self Source change for the created DB row
     */
    public static function dbCreated(string $collectionKey, string $idString, array $row): self
    {
        return new self(self::KIND_DB, $collectionKey, $idString, TableMutationType::Create, $row);
    }

    /**
     * Creates a DB update source fact.
     *
     * @param string $collectionKey DB collection key
     * @param string $idString Updated row id serialized as a string
     * @param array<string, mixed> $row Changed columns
     * @return self Source change for the updated DB row
     */
    public static function dbUpdated(string $collectionKey, string $idString, array $row): self
    {
        return new self(self::KIND_DB, $collectionKey, $idString, TableMutationType::Update, $row);
    }

    /**
     * Creates a DB delete source fact.
     *
     * @param string $collectionKey DB collection key
     * @param string $idString Deleted row id serialized as a string
     * @param array<string, mixed> $row Previous persisted row, when the source can provide it
     * @return self Source change for the deleted DB row
     */
    public static function dbDeleted(string $collectionKey, string $idString, array $row = []): self
    {
        return new self(self::KIND_DB, $collectionKey, $idString, TableMutationType::Delete, $row);
    }

    /**
     * Creates a DB clear source fact for a whole-collection truncate.
     *
     * Collection-scoped: there is no single row id, so sourceId is empty and the
     * row is empty. A subscribed table observing this collection wipes its rows.
     *
     * @param string $collectionKey DB collection key whose rows were all removed
     * @return self Source change for the cleared DB collection
     */
    public static function dbCleared(string $collectionKey): self
    {
        return new self(self::KIND_DB, $collectionKey, '', TableMutationType::Clear, []);
    }

    /**
     * Creates an RT create source fact.
     *
     * @param string $collectionKey RT collection key
     * @param string $stateId Created runtime state id
     * @param array<string, mixed> $row Full runtime row
     * @return self Source change for the created runtime row
     */
    public static function rtCreated(string $collectionKey, string $stateId, array $row): self
    {
        return new self(self::KIND_RT, $collectionKey, $stateId, TableMutationType::Create, $row);
    }

    /**
     * Creates an RT update source fact.
     *
     * @param string $collectionKey RT collection key
     * @param string $stateId Updated runtime state id
     * @param array<string, mixed> $row Changed runtime fields
     * @return self Source change for the updated runtime row
     */
    public static function rtUpdated(string $collectionKey, string $stateId, array $row): self
    {
        return new self(self::KIND_RT, $collectionKey, $stateId, TableMutationType::Update, $row);
    }

    /**
     * Creates an RT delete source fact.
     *
     * @param string $collectionKey RT collection key
     * @param string $stateId Deleted runtime state id
     * @param array<string, mixed> $row Previous runtime row, when the source can provide it
     * @return self Source change for the deleted runtime row
     */
    public static function rtDeleted(string $collectionKey, string $stateId, array $row = []): self
    {
        return new self(self::KIND_RT, $collectionKey, $stateId, TableMutationType::Delete, $row);
    }

    /**
     * Checks whether this change originated from a DB collection sync.
     *
     * @return bool True when this is a DB source fact
     */
    public function isDb(): bool
    {
        return $this->kind === self::KIND_DB;
    }

    /**
     * Checks whether this change originated from an RT collection sync.
     *
     * @return bool True when this is an RT source fact
     */
    public function isRt(): bool
    {
        return $this->kind === self::KIND_RT;
    }

    /**
     * Serializes the source fact for worker-to-daemon transport.
     *
     * @return array<string, mixed> Source change payload
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
     * Restores a source fact from a serialized payload.
     *
     * @param array<string, mixed> $data Source change payload
     * @return static Restored source change
     */
    public static function fromArray(array $data): static
    {
        $sourceId = $data[self::FIELD_SOURCE_ID] ?? '';
        $row = $data[self::FIELD_ROW] ?? [];

        return new static(
            kind: (string) ($data[self::FIELD_KIND] ?? self::KIND_DB),
            sourceKey: (string) ($data[self::FIELD_SOURCE_KEY] ?? ''),
            sourceId: is_string($sourceId) || is_int($sourceId) ? (string) $sourceId : '',
            mutationType: TableMutationType::tryFrom((string) ($data[self::FIELD_MUTATION_TYPE] ?? '')) ?? TableMutationType::Update,
            row: is_array($row) ? $row : [],
        );
    }
}
