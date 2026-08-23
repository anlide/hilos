<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\HilosSessionHostInterface;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → session holder: this registration landed, settle everyone waiting on it.
 *
 * Registration is the one flow with more than one browser in it: other tabs, and other
 * sessions entirely, can be parked on the same identifier. Only the holder sees them - the
 * waits are its runtime rows, the sockets are its connections - so the library reports the
 * outcome and names the losers, and the holder converges them
 * ({@see HilosSessionHostInterface::convergeRegistration()}).
 */
final class AuthRegistrationLandedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $identifier Normalized identifier the registration confirmed
     * @param int $userId User the confirmation created
     * @param string $winnerSessionToken Session token of the browser whose registration this was
     * @param string $initiatorAcceptKey Accept key of the connection that submitted the proof
     * @param list<string> $losingSessionTokens Session tokens whose hold on the identifier is dropped
     * @param ?string $requestId Request id of the action that caused this, or null when it was untracked
     * @param ?string $action Action name to answer, or null when nothing is waiting on an answer
     * @param ?array<string, mixed> $outcome Reply the answer carries ({@see AuthFlowOutcome::toArray()}), or null
     */
    public function __construct(
        public readonly string $identifier,
        public readonly int $userId,
        public readonly string $winnerSessionToken,
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
            'userId' => $this->userId,
            'winnerSessionToken' => $this->winnerSessionToken,
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
     * @throws InvalidFormatException When the payload names no identifier, no user, or no winning session
     */
    public static function fromArray(array $data): static
    {
        return new static(
            identifier: self::requireString($data, 'identifier'),
            userId: self::requireInt($data, 'userId'),
            winnerSessionToken: self::requireString($data, 'winnerSessionToken'),
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
