<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Exception\TableSignalNotDeserializableException;

/**
 * Signal data for pushing a table action error to the originating client.
 *
 * Sent via WebSocket as `table_action_error` signal.
 */
class TableActionErrorSignalData extends SignalData implements SignalDataInterface
{
    public function __construct(
        public readonly string $tableKey,
        public readonly string $action,
        public readonly string $message,
    ) {
    }

    public function toArray(): array
    {
        return [
            'tableKey' => $this->tableKey,
            'action' => $this->action,
            'message' => $this->message,
        ];
    }

    public static function fromArray(array $data): static
    {
        throw new TableSignalNotDeserializableException(static::class);
    }
}
