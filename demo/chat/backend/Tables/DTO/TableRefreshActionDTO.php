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
    public function __construct(
        public readonly string $tableKey,
    ) {
    }

    /**
     * Returns the action name this DTO represents.
     */
    public function getAction(): string
    {
        return ChatSignalConstants::TABLE_REFRESH;
    }

    /**
     * Creates DTO from payload array. Unwraps nested data key if present.
     *
     * @param array<string, mixed> $data Raw payload (may contain SignalPayloadConstants::FIELD_DATA wrapper)
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $d = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($d) && isset($d[SignalPayloadConstants::FIELD_DATA]) && is_array($d[SignalPayloadConstants::FIELD_DATA])) {
            $d = $d[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            tableKey: is_string($d[TableConstants::PAYLOAD_KEY_TABLE_KEY] ?? null) ? $d[TableConstants::PAYLOAD_KEY_TABLE_KEY] : '',
        );
    }

    /**
     * Converts DTO to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [TableConstants::PAYLOAD_KEY_TABLE_KEY => $this->tableKey];
    }
}
