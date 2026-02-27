<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DB sync signal data for created row.
 * Full row data (all columns).
 */
class DbSyncCreatedSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $collectionKey,
        /** @var string Collection key from Object::getIdString() */
        public readonly string $idString,
        /** @var array<string, mixed> Full row (all columns) */
        public readonly array $row,
    ) {
    }

    public function toArray(): array
    {
        return [
            'collectionKey' => $this->collectionKey,
            'idString' => $this->idString,
            'row' => $this->row,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: $data['collectionKey'] ?? '',
            idString: $data['idString'] ?? '',
            row: $data['row'] ?? [],
        );
    }
}
