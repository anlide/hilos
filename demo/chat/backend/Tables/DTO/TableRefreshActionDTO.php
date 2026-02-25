<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

/**
 * DTO for table_refresh action payload.
 */
class TableRefreshActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly string $tableKey,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::TABLE_REFRESH;
    }

    public static function fromArray(array $data): static
    {
        $d = $data['data'] ?? $data;
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
            $d = $d['data'];
        }

        return new static(
            tableKey: is_string($d['tableKey'] ?? null) ? $d['tableKey'] : '',
        );
    }

    public function toArray(): array
    {
        return ['tableKey' => $this->tableKey];
    }
}
