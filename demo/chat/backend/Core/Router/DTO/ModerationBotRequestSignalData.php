<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationBotRequestSignalData - DTO for bot message moderation request (BotAgent → ModeratorAgent)
 */
class ModerationBotRequestSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly int $botId,
        public readonly string $message,
    ) {
    }

    public function toArray(): array
    {
        return [
            'botId' => $this->botId,
            'message' => $this->message,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            botId: (int)($data['botId'] ?? 0),
            message: $data['message'] ?? '',
        );
    }
}
