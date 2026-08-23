<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\HilosSessionHostInterface;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → session holder: the password changed, end every other session of this user.
 *
 * A changed password is a statement about all the sessions of the account, and sessions are
 * the holder's ({@see HilosSessionHostInterface::deauthenticateOtherSessions()}). The one
 * that changed it stays, marked with the ack that tells the person what just happened, and
 * signed in - a recovery is finished by becoming the account again, not by being sent to a
 * login form with the password just typed.
 *
 * The address rides along because the tabs waiting on it are settled here too
 * ({@see HilosSessionHostInterface::convergeRecovery()}): they were parked under the
 * identifier, which is the one thing about this recovery a session token cannot name.
 */
final class AuthPasswordChangedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $userId User whose password changed
     * @param string $sessionToken Session that changed it and stays signed in
     * @param string $acceptKey Accept key of the connection that saved the password
     * @param string $identifier Normalized address whose recovery this finished
     * @param ?string $requestId Request id of the action that caused this, or null when it was untracked
     * @param ?string $action Action name to answer, or null when nothing is waiting on an answer
     * @param ?array<string, mixed> $outcome Reply the answer carries ({@see AuthFlowOutcome::toArray()}), or null
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $sessionToken,
        public readonly string $acceptKey,
        public readonly string $identifier,
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
            'userId' => $this->userId,
            'sessionToken' => $this->sessionToken,
            'acceptKey' => $this->acceptKey,
            'identifier' => $this->identifier,
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
     * @throws InvalidFormatException When the payload names no user, no session, no address, or no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, 'userId'),
            sessionToken: self::requireString($data, 'sessionToken'),
            acceptKey: self::requireString($data, 'acceptKey'),
            identifier: self::requireString($data, 'identifier'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
            outcome: self::optionalArray($data, 'outcome'),
        );
    }
}
