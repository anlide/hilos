<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BotAgentSignalData - DTO for bot agent lifecycle signals (start/stop/reload).
 *
 * Used by BOT_AGENT_START, BOT_AGENT_STOP, BOT_AGENT_RELOAD signals.
 */
final class BotAgentSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates bot agent signal DTO.
     *
     * @param int $botId Bot ID
     */
    public function __construct(
        public readonly int $botId,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'botId' => $this->botId,
        ];
    }

    /**
     * Create DTO from array (for deserialization).
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            botId: (int) ($data['botId'] ?? 0),
        );
    }
}
