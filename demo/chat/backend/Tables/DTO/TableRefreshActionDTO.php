<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Table\TableConstants;

/**
 * DTO for table_refresh action payload.
 *
 * tableKey identifies which table to refresh (e.g. TableChatContext::users).
 */
class TableRefreshActionDTO extends ChatActionPayloadDTO
{
    /**
     * @param string $tableKey Table identifier (e.g. TableChatContext::users)
     */
    public function __construct(
        public readonly string $tableKey,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string
     */
    public function getAction(): string
    {
        return ChatSignalConstants::TABLE_REFRESH;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     *
     * @return self
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            tableKey: is_string($inner[TableConstants::PAYLOAD_KEY_TABLE_KEY] ?? null) ? $inner[TableConstants::PAYLOAD_KEY_TABLE_KEY] : '',
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [TableConstants::PAYLOAD_KEY_TABLE_KEY => $this->tableKey];
    }
}
