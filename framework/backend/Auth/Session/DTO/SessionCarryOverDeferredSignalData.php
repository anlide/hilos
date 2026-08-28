<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ProtectedModeLiftAnnouncer;

/**
 * SessionCarryOverDeferredSignalData - restore -> own daemon payload for SESSION_CARRY_OVER_DEFERRED.
 *
 * Sent the moment a restore leaves photographed logins in {@see DeferredSessionCarryoverQueue},
 * and only then: it is what tells this node's master that the lift has something to wait for
 * ({@see ProtectedModeLiftAnnouncer}). A node that ran no restore sends nothing and lifts at once,
 * which is why the debt travels as a frame rather than being guessed at from the freeze row.
 *
 * The count is the whole payload, and it is carried for the log line rather than for the waiting:
 * the master waits on the debt existing, not on its size, but an operator reading why a lift was
 * held wants to know how many people it was held for.
 */
final class SessionCarryOverDeferredSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: how many logins the restore left in the queue. */
    public const string sessions = 'sessions';

    /**
     * @param int $sessions Logins the restore left for the sessions library
     */
    public function __construct(
        public readonly int $sessions,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::sessions => $this->sessions,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessions: (int)$data[self::sessions],
        );
    }
}
