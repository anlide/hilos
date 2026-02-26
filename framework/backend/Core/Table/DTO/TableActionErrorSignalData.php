<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Exception\TableSignalNotDeserializableException;
use Hilos\Core\Table\TableConstants;

/**
 * Signal data for pushing a table action error to the originating client.
 *
 * Sent via WebSocket as `table_action_error` signal.
 */
class TableActionErrorSignalData extends SignalData implements SignalDataInterface
{
    /**
     * @param string $tableKey Table identifier (e.g. users, bots)
     * @param string $action Action name that failed
     * @param string $message Error message
     */
    public function __construct(
        public readonly string $tableKey,
        public readonly string $action,
        public readonly string $message,
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
            TableConstants::PAYLOAD_KEY_ACTION => $this->action,
            TableConstants::PAYLOAD_KEY_MESSAGE => $this->message,
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
