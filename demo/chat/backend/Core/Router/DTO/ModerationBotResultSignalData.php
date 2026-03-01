<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationBotResultSignalData - DTO for bot message moderation result (ModeratorAgent → ChatAgent)
 */
class ModerationBotResultSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly int $botId,
        public readonly string $message,
        public readonly bool $allow,
        public readonly string $reason,
    ) {
    }

    public function toArray(): array
    {
        return [
            'botId' => $this->botId,
            'message' => $this->message,
            'allow' => $this->allow,
            'reason' => $this->reason,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            botId: (int)($data['botId'] ?? 0),
            message: $data['message'] ?? '',
            allow: (bool)($data['allow'] ?? false),
            reason: $data['reason'] ?? '',
        );
    }
}
