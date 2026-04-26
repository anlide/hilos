<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Source-agnostic event that may affect one or more table projections.
 */
final class TableSourceEventDTO extends BaseDTO
{
    public const string FIELD_SOURCE_KEY = 'sourceKey';

    public const string FIELD_SOURCE_ROW_KEY = 'sourceRowKey';

    public const string FIELD_MUTATION_TYPE = 'mutationType';

    /**
     * Creates a source event that table definitions may project into row mutations.
     *
     * @param string $sourceKey Source collection or subsystem key
     * @param string|int $sourceRowKey Source row/state key
     * @param TableMutationType $mutationType Source mutation type
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string|int $sourceRowKey,
        public readonly TableMutationType $mutationType,
    ) {
    }

    /**
     * Converts the event to a transport-safe array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_SOURCE_KEY => $this->sourceKey,
            self::FIELD_SOURCE_ROW_KEY => $this->sourceRowKey,
            self::FIELD_MUTATION_TYPE => $this->mutationType->value,
        ];
    }

    /**
     * Restores the source event from worker-to-daemon transport data.
     *
     * @param array<string, mixed> $data Serialized event payload
     */
    public static function fromArray(array $data): static
    {
        $sourceRowKey = $data[self::FIELD_SOURCE_ROW_KEY] ?? '';

        return new self(
            sourceKey: (string) ($data[self::FIELD_SOURCE_KEY] ?? ''),
            sourceRowKey: is_string($sourceRowKey) || is_int($sourceRowKey) ? $sourceRowKey : '',
            mutationType: TableMutationType::tryFrom((string) ($data[self::FIELD_MUTATION_TYPE] ?? '')) ?? TableMutationType::Update,
        );
    }
}
