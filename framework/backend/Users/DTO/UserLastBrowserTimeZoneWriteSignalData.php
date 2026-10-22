<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Sessions library → the agent of the person at the keyboard: the usable zone a browser named (HIL-1582).
 *
 * A background write nobody waits on, so the frame carries no continuation. The zone was normalized where
 * the browser reported it; the person's agent still validates it on the write.
 */
final class UserLastBrowserTimeZoneWriteSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string lastBrowserTimeZone = 'lastBrowserTimeZone';

    /**
     * @param int $userId Person at the keyboard of the browser that named the zone
     * @param string $lastBrowserTimeZone Exact IANA identifier the browser named
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $lastBrowserTimeZone,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored zone report
     * @throws InvalidFormatException When a field is absent or malformed, or the person id is not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            lastBrowserTimeZone: self::requireString($data, self::lastBrowserTimeZone),
        );
    }

    /** @return array{userId: int, lastBrowserTimeZone: string} Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::lastBrowserTimeZone => $this->lastBrowserTimeZone,
        ];
    }
}
