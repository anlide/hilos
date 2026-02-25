<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Exception\TableSignalNotDeserializableException;
use Hilos\Core\Table\Mutation\TableMutationEntry;

/**
 * Signal data for pushing a single table mutation to subscribers.
 *
 * Sent via WebSocket as `table_mutation` signal.
 */
class TableMutationSignalData extends SignalData implements SignalDataInterface
{
    public function __construct(
        public readonly string $tableKey,
        public readonly TableMutationEntry $mutation,
    ) {
    }

    public function toArray(): array
    {
        return [
            'tableKey' => $this->tableKey,
            'mutation' => $this->mutation->toArray(),
        ];
    }

    public static function fromArray(array $data): static
    {
        throw new TableSignalNotDeserializableException(static::class);
    }
}
