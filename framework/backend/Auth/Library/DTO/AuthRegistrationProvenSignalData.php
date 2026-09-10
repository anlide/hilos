<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → session holder: this registration's address was proved, move its tabs along.
 *
 * The registration counterpart of {@see AuthRecoveryGrantedSignalData} (HIL-825). No
 * account exists yet and nobody is signed in by it - the code proved the address and
 * left the proof on the hold, and what changes is which screen the waiting tabs of that
 * browser are on. Those tabs are the holder's ({@see AbstractSessionsLibraryAgent}).
 *
 * `losingSessionTokens` is carried for the shape's sake and is always empty here: the
 * address belongs to nobody until a password is saved, so the browsers racing for it
 * keep their holds and lose them at the landing instead
 * ({@see AuthRegistrationLandedSignalData}).
 */
final class AuthRegistrationProvenSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $identifier Normalized address that was proved
     * @param string $sessionToken Session token that proved the code
     * @param string $initiatorAcceptKey Accept key of the connection that submitted it
     * @param list<string> $losingSessionTokens Session tokens whose wait on this address ends
     * @param ?string $requestId Request id of the action that caused this, or null when it was untracked
     * @param ?string $action Action name to answer, or null when nothing is waiting on an answer
     * @param ?array<string, mixed> $outcome Reply the answer carries ({@see AuthFlowOutcome::toArray()}), or null
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $sessionToken,
        public readonly string $initiatorAcceptKey,
        public readonly array $losingSessionTokens = [],
        public readonly ?string $requestId = null,
        public readonly ?string $action = null,
        public readonly ?array $outcome = null,
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
            'identifier' => $this->identifier,
            'sessionToken' => $this->sessionToken,
            'initiatorAcceptKey' => $this->initiatorAcceptKey,
            'losingSessionTokens' => $this->losingSessionTokens,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'outcome' => $this->outcome,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no address, no session, or no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            identifier: self::requireString($data, 'identifier'),
            sessionToken: self::requireString($data, 'sessionToken'),
            initiatorAcceptKey: self::requireString($data, 'initiatorAcceptKey'),
            losingSessionTokens: array_values(array_filter(
                self::optionalArray($data, 'losingSessionTokens') ?? [],
                static fn(mixed $token): bool => is_string($token) && $token !== '',
            )),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
            outcome: self::optionalArray($data, 'outcome'),
        );
    }
}
