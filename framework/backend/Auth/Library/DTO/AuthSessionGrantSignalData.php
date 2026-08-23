<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\HilosSessionHostInterface;
use Hilos\Auth\Session\SessionAck;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → session holder: bind this session to this user, then answer the caller.
 *
 * The end of every command that leaves a person signed in - password login, magic link,
 * phone code, passkey, a proven address. The library has done the proving and knows who the
 * person is; the holder owns the session row and the sockets standing on it, so the last two
 * steps belong to it ({@see HilosSessionHostInterface::authenticateSession()}).
 *
 * The ack and the answer travel together for the sake of ORDER: the holder marks the
 * session, authenticates it and only then sends the reply, because a client told "done"
 * before its own identity changed would read the wrong user out of its next frame. Inside
 * one process that order came free; across two it has to be carried (HIL-622).
 */
final class AuthSessionGrantSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session cookie token to authenticate
     * @param int $userId User the proof resolved to
     * @param string $acceptKey Accept key of the connection that signed in
     * @param ?string $requestId Request id of the action that caused this, or null when it was untracked
     * @param ?string $action Action name to answer, or null when nothing is waiting on an answer
     * @param ?array<string, mixed> $outcome Reply the answer carries ({@see AuthFlowOutcome::toArray()}), or null
     * @param ?string $ack Ack to show on the session's tabs (a {@see SessionAck} value), or null for none
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly int $userId,
        public readonly string $acceptKey,
        public readonly ?string $requestId = null,
        public readonly ?string $action = null,
        public readonly ?array $outcome = null,
        public readonly ?string $ack = null,
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
            'sessionToken' => $this->sessionToken,
            'userId' => $this->userId,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'outcome' => $this->outcome,
            'ack' => $this->ack,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session, no user, or no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: self::requireString($data, 'sessionToken'),
            userId: self::requireInt($data, 'userId'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
            outcome: self::optionalArray($data, 'outcome'),
            ack: self::optionalString($data, 'ack'),
        );
    }
}
