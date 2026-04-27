<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend\DTO;

use Hilos\BaseDTO;

/**
 * Runtime-backed frontend projection for one user's connection counters.
 */
final class FrontendUserConnectionStatsProjection extends BaseDTO
{
    public const string userId = 'userId';
    public const string onlineSessionCount = 'onlineSessionCount';

    public function __construct(
        public readonly int $userId,
        public readonly int $onlineSessionCount,
    ) {
    }

    /**
     * Converts DTO to frontend payload.
     *
     * @return array{userId: int, onlineSessionCount: int}
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::onlineSessionCount => $this->onlineSessionCount,
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
            userId: isset($data[self::userId]) && is_int($data[self::userId]) ? $data[self::userId] : 0,
            onlineSessionCount: isset($data[self::onlineSessionCount]) && is_int($data[self::onlineSessionCount])
                ? $data[self::onlineSessionCount]
                : 0,
        );
    }
}
