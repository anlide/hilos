<?php

declare(strict_types=1);

namespace Hilos\Pages\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Hilos user sessions page request to end one session of the person on the card (HIL-1662).
 *
 * Names the person and the session row the administrator chose, nothing else: who is asking is
 * read off the connection by the sessions library, so a field naming the administrator, sent or
 * not, never reaches the write.
 */
final class HilosUserSessionEndActionDTO extends ActionPayloadDTO
{
    public const string userId = 'userId';
    public const string sessionId = 'sessionId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $userId Person whose session ends
     * @param int $sessionId Session row to end
     * @throws InvalidFormatException When the person or the session id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $sessionId,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if ($sessionId <= 0) {
            throw new InvalidFormatException('Session id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed request to end one session
     * @throws InvalidFormatException When the person or the session id is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            userId: self::requireInt($inner, self::userId),
            sessionId: self::requireInt($inner, self::sessionId),
        );
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_USER_SESSION_END;
    }

    /** @return array<string, int> Person and session */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::sessionId => $this->sessionId,
        ];
    }
}
