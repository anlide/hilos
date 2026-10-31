<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BotAgentSignalData - DTO for the signals addressed to one bot's agent.
 *
 * Used by BOT_AGENT_START and BOT_ANSWER_NOW signals.
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
     * @throws InvalidFormatException When the payload names no bot
     */
    public static function fromArray(array $data): static
    {
        return new static(
            botId: self::requireInt($data, 'botId'),
        );
    }
}
