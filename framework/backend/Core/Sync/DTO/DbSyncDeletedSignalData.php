<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DB sync signal data for deleted row.
 * Only idString (collection key).
 */
class DbSyncDeletedSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $collectionKey,
        /** @var string Collection key from Object::getIdString() */
        public readonly string $idString,
    ) {
    }

    public function toArray(): array
    {
        return [
            'collectionKey' => $this->collectionKey,
            'idString' => $this->idString,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: $data['collectionKey'] ?? '',
            idString: $data['idString'] ?? '',
        );
    }
}
