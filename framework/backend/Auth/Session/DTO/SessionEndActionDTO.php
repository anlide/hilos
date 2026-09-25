<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Names another browser session the signed-in user wants to end.
 */
final class SessionEndActionDTO extends ActionPayloadDTO
{
    public const string sessionId = 'sessionId';

    /**
     * @param int $sessionId Positive database session row id
     */
    public function __construct(
        public readonly int $sessionId,
    ) {
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_SESSION_END;
    }

    /**
     * @param array<string, mixed> $data Raw payload, optionally wrapped in the action-data envelope
     * @return static Parsed session-end request
     * @throws InvalidFormatException When the session id is absent, mistyped or not positive
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $sessionId = self::requireInt($inner, self::sessionId);
        if ($sessionId <= 0) {
            throw new InvalidFormatException('Session id must be positive');
        }

        return new static($sessionId);
    }

    /** @return array{sessionId: int} Session row id */
    public function toArray(): array
    {
        return [self::sessionId => $this->sessionId];
    }
}
