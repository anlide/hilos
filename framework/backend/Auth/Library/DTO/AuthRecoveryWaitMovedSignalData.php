<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → session holder: this connection now recovers THAT address (HIL-685).
 *
 * The recovery twin of {@see AuthRegistrationWaitMovedSignalData}, and the only frame in
 * the pair whose arrival takes something away: the grant belongs to the address it was
 * earned for, so a waiter pointed at a new one loses it. A second code asked for from the
 * same tab therefore cannot open the password step of the address the person just left,
 * which is the case this frame exists for.
 *
 * The payload states the wait rather than amending it - the accept key it belongs to, and
 * the address and session it stands on now - because the row it describes may not exist
 * yet, and the holder is the one that writes it either way
 * ({@see AbstractSessionsLibraryAgent::AGENT_SIGNALS}).
 *
 * There is nothing to answer and nothing to wait for: the library has already told the
 * browser its code went out, because that answer never depended on the wait. What a lost
 * frame costs is the converge of the OTHER tabs of the session, which is the same thing a
 * lost node costs today.
 */
final class AuthRecoveryWaitMovedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Accept key of the waiting connection, which is the row's id
     * @param string $identifier Normalized address it recovers now (lowercased email)
     * @param string $sessionToken Session token the grant is bound to
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
