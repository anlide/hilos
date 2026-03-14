<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Exception\TableSignalNotDeserializableException;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\TableConstants;

/**
 * Signal data for pushing a single table mutation to subscribers.
 *
 * Sent via WebSocket as `table_mutation` signal.
 */
class TableMutationSignalData extends SignalData implements SignalDataInterface
{
    /**
     * Creates table mutation signal data.
     *
     * @param string $tableKey Table identifier (e.g. users, bots)
     * @param TableMutationEntry $mutation Mutation entry (type, rowId, row)
     */
    public function __construct(
        public readonly string $tableKey,
        public readonly TableMutationEntry $mutation,
    ) {
        parent::__construct();
    }

    /**
     * Converts to array for WebSocket serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            TableConstants::PAYLOAD_KEY_TABLE_KEY => $this->tableKey,
            TableConstants::PAYLOAD_KEY_MUTATION => $this->mutation->toArray(),
        ];
    }

    /**
     * Not supported: this signal is server-to-client only.
     *
     * @param array<string, mixed> $data
     *
     * @throws TableSignalNotDeserializableException
     */
    public static function fromArray(array $data): static
    {
        throw new TableSignalNotDeserializableException(static::class);
    }
}
