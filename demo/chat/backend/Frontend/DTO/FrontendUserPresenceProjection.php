<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend\DTO;

use Hilos\BaseDTO;

/**
 * Runtime-backed frontend projection for one user's presence.
 */
final class FrontendUserPresenceProjection extends BaseDTO
{
    public const string userId = 'userId';
    public const string presence = 'presence';

    public function __construct(
        public readonly int $userId,
        public readonly string $presence,
    ) {
    }

    /**
     * Converts DTO to frontend payload.
     *
     * @return array{userId: int, presence: string}
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
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
            userId: isset($data[self::userId]) && is_int($data[self::userId]) ? $data[self::userId] : 0,
            presence: isset($data[self::presence]) && is_string($data[self::presence])
                ? $data[self::presence]
                : 'offline',
        );
    }
}
