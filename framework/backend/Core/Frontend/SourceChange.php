<?php

declare(strict_types=1);

namespace Hilos\Core\Frontend;

use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * DB/RT sync fact that can invalidate a worker-local frontend projection.
 *
 * This object deliberately describes the backend source state, not the frontend
 * payload. A project projection decides how one source fact maps to page-local
 * frontend collections, table rows, and WebSocket signal names.
 */
final class SourceChange
{
    public const string KIND_DB = 'db';
    public const string KIND_RT = 'rt';

    /**
     * @param string $kind Source kind: db or rt
     * @param string $sourceKey DB collection key or RT collection key
     * @param string $sourceId Row id or runtime state id
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
}
