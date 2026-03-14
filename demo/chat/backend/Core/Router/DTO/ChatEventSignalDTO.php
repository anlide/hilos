<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Exception\NotImplementedException;

/**
 * ChatEventSignalDTO - Signal data for chat events.
 *
 * Simple pass-through of entities to frontend.
 * Optional tables payload for get() responses (e.g. admin page with users table).
 */
class ChatEventSignalDTO extends SignalData implements SignalDataInterface
{
    /**
     * Creates chat event signal DTO.
     *
     * @param EntitiesChangesDTO $entities Entity changes
     * @param array<string, TableResultDTO> $tables Table key → result DTO
     */
    public function __construct(
        public readonly EntitiesChangesDTO $entities,
        public readonly array $tables = [],
    ) {
        parent::__construct($this->toArray());
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
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

    /**
     * Create DTO from array (not implemented - response is created directly).
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws NotImplementedException Deserialization is not implemented
     */
    public static function fromArray(array $data): static
    {
        throw new NotImplementedException('ChatEventSignalDTO::fromArray() is not implemented');
    }
}
