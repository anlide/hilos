<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Bot asked to answer now. */
final class BotAnswerNowCommandData extends BaseDTO implements SignalDataInterface
{
    public const string botId = 'botId';

    /**
     * @param int $botId Bot whose agent goes to the model on its next free tick
     * @throws InvalidFormatException When the id is not positive
     */
    public function __construct(public readonly int $botId)
    {
        if ($botId <= 0) {
            throw new InvalidFormatException('Answering now needs a positive botId');
        }
    }

    /** @return array{botId: int} Command payload */
    public function toArray(): array
    {
        return [self::botId => $this->botId];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Strictly parsed bot
     * @throws InvalidFormatException When the id is absent, mistyped or not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::botId));
    }
}
