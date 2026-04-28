<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend\DTO;

use Hilos\BaseDTO;

/**
 * Runtime-backed frontend projection for one bot agent's presence.
 */
final class FrontendBotPresenceProjection extends BaseDTO
{
    public const string botId = 'botId';
    public const string presence = 'presence';

    public function __construct(
        public readonly int $botId,
        public readonly string $presence,
    ) {
    }

    /**
     * Converts DTO to frontend payload.
     *
     * @return array{botId: int, presence: string}
     */
    public function toArray(): array
    {
        return [
            self::botId => $this->botId,
            self::presence => $this->presence,
        ];
    }

    /**
     * Creates DTO from frontend payload.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            botId: isset($data[self::botId]) && is_int($data[self::botId]) ? $data[self::botId] : 0,
            presence: isset($data[self::presence]) && is_string($data[self::presence])
                ? $data[self::presence]
                : 'offline',
        );
    }
}
