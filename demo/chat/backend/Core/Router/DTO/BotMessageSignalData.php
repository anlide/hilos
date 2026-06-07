<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Bot message payload sent from BotAgent to ChatAgent for publication.
 */
final class BotMessageSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates a bot message signal payload.
     *
     * @param int $botId Bot ID
     * @param string $message Generated message text
     */
    public function __construct(
        public readonly int $botId,
        public readonly string $message,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, int|string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'botId' => $this->botId,
            'message' => $this->message,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            botId: (int)($data['botId'] ?? 0),
            message: (string)($data['message'] ?? ''),
        );
    }
}
