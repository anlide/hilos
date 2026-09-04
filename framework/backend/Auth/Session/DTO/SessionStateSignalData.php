<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\SessionAck;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;

/**
 * Sessions library → project agent: this is what the session is now, tell its sockets.
 *
 * The library's whole half of the seam (HIL-710). It owns the session row and the parked
 * sign-in surfaces; the project owns the connection rows and knows the person's name, so
 * every ending the library reaches - a handshake, a sign-in, a sign-out, an impersonation,
 * an ack raised or dismissed - is said in this one frame and finished by the project.
 *
 * It names a LIST of sockets rather than one, because the mechanics behind it are
 * per-session: signing out, marking an ack and clearing it bring every live socket of one
 * session to a single state. A handshake is the case where that list holds one, not a
 * different kind of frame. The list is built by the library from the project's own
 * connection rows, which it may read but not write.
 *
 * A frame that carries a {@see self::$rotationTicket} or an answer to an action names
 * exactly ONE socket - the one that acted. Both are addressed to it: a one-time rotation
 * ticket has a single rightful holder, and an action is answered to the connection that
 * submitted it.
 *
 * {@see self::$pendingAuthStep} travels here rather than being read again by the
 * project: the unfinished authentication step is the library's knowledge, and a project
 * asking for it would be a second reader of a table it does not own.
 */
final class SessionStateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session cookie token the named sockets belong to now
     * @param ?int $userId User the session is bound to, or null when it is anonymous
     * @param list<string> $acceptKeys Accept keys of the live connections this state applies to
     * @param ?string $pendingAck Ack the session owes (a {@see SessionAck} value), or null for none
     * @param ?array{identifier: string, kind: string, intent: string, step: string, channel: ?string, expiresAt: int} $pendingAuthStep
     *     Authentication step the session has not finished ({@see HandshakeResponseSignalData}), or null
     * @param ?string $rotationTicket Ticket the named socket trades for the rotated cookie, or null when nothing rotated
     * @param ?string $requestId Request id of the action waiting on this ending, or null when nobody waits
     * @param ?string $action Action name to answer, or null when this ending finished none
     * @param ?array<string, mixed> $outcome Reply the answer carries ({@see AuthFlowOutcome::toArray()}), or null
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly ?int $userId,
        public readonly array $acceptKeys,
        public readonly ?string $pendingAck = null,
        public readonly ?array $pendingAuthStep = null,
        public readonly ?string $rotationTicket = null,
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
            'sessionToken' => $this->sessionToken,
            'userId' => $this->userId,
            'acceptKeys' => $this->acceptKeys,
            'pendingAck' => $this->pendingAck,
            'pendingAuthStep' => $this->pendingAuthStep,
            'rotationTicket' => $this->rotationTicket,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'outcome' => $this->outcome,
        ];
    }

    /**
     * Create DTO from array.
     *
     * The token is required and the socket list is not: a session whose every tab has gone
     * still has a state, and the frame that says so is what re-decides its pages. What the
     * frame cannot be read without is the session it is about.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: self::requireString($data, 'sessionToken'),
            userId: self::optionalInt($data, 'userId'),
            acceptKeys: array_values(array_map(
                static fn(mixed $acceptKey): string => (string)$acceptKey,
                self::optionalArray($data, 'acceptKeys') ?? [],
            )),
            pendingAck: self::optionalString($data, 'pendingAck'),
            pendingAuthStep: self::optionalArray($data, 'pendingAuthStep'),
            rotationTicket: self::optionalString($data, 'rotationTicket'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
            outcome: self::optionalArray($data, 'outcome'),
        );
    }

    /**
     * Names the one socket a ticket or an answer is addressed to.
     *
     * A frame carrying either is built with a single accept key by
     * {@see AbstractSessionsLibraryAgent}, so this reads that key rather than choosing
     * among several. A frame that somehow carries none answers null, and the project sends
     * nothing - which is the honest outcome for an ending whose connection has gone.
     *
     * @return ?string Accept key of the connection that acted, or null when the frame names none
     */
    public function initiatorAcceptKey(): ?string
    {
        return $this->acceptKeys[0] ?? null;
    }
}
