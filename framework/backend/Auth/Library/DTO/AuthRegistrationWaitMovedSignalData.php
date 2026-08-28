<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → session holder: this connection now waits on THAT address (HIL-685).
 *
 * The frame that carries the one thing parking cannot do for itself. A library adds rows
 * and removes them; the row of a browser that changed its mind is already there and has
 * to be EDITED, and editing this collection belongs to its one full truth source. So the
 * payload states the wait rather than amending it - the accept key it belongs to, and the
 * address and session it stands on now - and the holder makes the row say that
 * ({@see AbstractSessionsLibraryAgent::AGENT_SIGNALS}).
 *
 * There is nothing to answer and nothing to wait for: the library has already told the
 * browser its code went out, because that answer never depended on the wait. What a lost
 * frame costs is the converge of the OTHER tabs of the session, which is the same thing a
 * lost node costs today.
 */
final class AuthRegistrationWaitMovedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Accept key of the waiting connection, which is the row's id
     * @param string $identifier Normalized identifier it waits on now (lowercased email)
     * @param string $sessionToken Session token to sign in on confirmation
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $identifier,
        public readonly string $sessionToken,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'identifier' => $this->identifier,
            'sessionToken' => $this->sessionToken,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no connection, no address, or no session
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            identifier: self::requireString($data, 'identifier'),
            sessionToken: self::requireString($data, 'sessionToken'),
        );
    }
}
