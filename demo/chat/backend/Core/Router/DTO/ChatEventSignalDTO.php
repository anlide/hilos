<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableResultDTO;
use RuntimeException;

/**
 * ChatEventSignalDTO - Signal data for chat events
 *
 * Simple pass-through of entities to frontend.
 * Optional tables payload for get() responses (e.g. admin page with users table).
 */
class ChatEventSignalDTO extends SignalData implements SignalDataInterface
{
    /**
     * @param EntitiesChangesDTO $entities Entity changes
     * @param array<string, TableResultDTO> $tables Table key → result DTO
     */
    public function __construct(
        public readonly EntitiesChangesDTO $entities,
        public readonly array $tables = [],
    ) {
    }

    public function toArray(): array
    {
        $data = ['entities' => $this->entities->toArray()];
        if (!empty($this->tables)) {
            $tablesArr = array_map(function ($dto) {
                return $dto->toArray();
            }, $this->tables);
            $data['tables'] = $tablesArr;
        }
        return $data;
    }

    public static function fromArray(array $data): static
    {
        throw new RuntimeException('ChatEventSignalDTO::fromArray() is not implemented');
    }
}
