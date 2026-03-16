<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\DTO;

use Hilos\Core\Table\DTO\TableResultDTO;

/**
 * Settings table result DTO with optional catalog keys for Add modal.
 *
 * Extends TableResultDTO to include catalogKeys in payload without modifying ChatEventSignalDTO.
 * Frontend uses catalogKeys to populate the Add modal key selector (excluding already-used keys).
 */
final class SettingsTableResultDTO extends TableResultDTO
{
    /**
     * Creates settings table result with catalog keys.
     *
     * @param TableResultDTO $source Base table result from settings->get()
     * @param list<string> $catalogKeys Keys from SettingsCatalog (for Add form dropdown)
     */
    public function __construct(
        private readonly TableResultDTO $source,
        private readonly array $catalogKeys,
    ) {
        parent::__construct(
            rows: $source->rows,
            totalCount: $source->totalCount,
            offset: $source->offset,
            limit: $source->limit,
        );
    }

    /**
     * Converts to array for WebSocket serialization.
     *
     * @return array<string, mixed> Parent keys plus catalogKeys
     */
    public function toArray(): array
    {
        return array_merge($this->source->toArray(), [
            'catalogKeys' => $this->catalogKeys,
            'rowIdField' => 'key',
        ]);
    }
}
