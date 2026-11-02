<?php

declare(strict_types=1);

namespace Hilos\Pages\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Hilos user sessions page request to end every session of the person on the card (HIL-1662).
 *
 * Names the person alone. Which sessions end is read by the sessions library when the request
 * arrives, not counted by the browser, and who is asking is read off the connection.
 */
final class HilosUserSessionsEndAllActionDTO extends ActionPayloadDTO
{
    public const string userId = 'userId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $userId Person whose sessions end
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed request to end every session
     * @throws InvalidFormatException When the person id is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            userId: self::requireInt($inner, self::userId),
        );
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL;
    }

    /** @return array<string, int> Person */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
        ];
    }
}
