<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * SessionRotateSignalData - the login rotation's hand-off to the browser (HIL-582).
 *
 * Delivered WS_USER to the connection that logged in, and to no other. The ticket is
 * the whole payload: the rotated session token is deliberately NOT here, because a
 * token on the wire to a frontend is a token JavaScript can read, and the session
 * cookie is HttpOnly precisely so it cannot be. The frontend learns nothing about the
 * new session beyond "one is waiting for you" - it parks the ticket in the helper
 * cookie and reconnects, and the master does the rest on the 101.
 *
 * The ticket's form and lifetime belong to {@see SessionRotationTicket}.
 */
final class SessionRotateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ticket = 'ticket';

    /**
     * @param string $ticket One-time ticket to present on the next handshake
     */
    public function __construct(
        public readonly string $ticket,
    ) {
    }

    /**
     * @return array<string, string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::ticket => $this->ticket,
        ];
    }

    /**
     * The ticket is read without a fallback: a payload arriving without one is not a
     * rotation with an empty ticket, it is a frame this DTO cannot describe, and minting
     * a blank would hand the frontend a value the master is guaranteed to refuse.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ticket: (string)$data[self::ticket],
        );
    }
}
