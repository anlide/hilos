<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Auth\Session\DTO\SessionStateSignalData;

/** A socket and its identity at the moment Chat accepted a SessionState. */
final readonly class SessionStateTarget
{
    private ?int $sessionId;
    private ?int $userId;
    private ?string $token;

    /**
     * @param SessionStateApplication $application The accepted frame's remaining effects
     * @param ?Connection $connection The original socket row, if already registered
     * @param SessionStateVisit $visit Result awaited by replies to this socket
     */
    public function __construct(
        public SessionStateApplication $application,
        private ?Connection $connection,
        public SessionStateVisit $visit = new SessionStateVisit(),
    ) {
        $this->sessionId = $connection?->sessionId;
        $this->userId = $connection?->userId;
        $this->token = $connection?->sessionToken;
    }

    /** @return bool Whether applying the frame ends the original composer's authority */
    public function changesComposer(): bool
    {
        return $this->sessionId !== $this->application->frame->sessionId || $this->userId !== $this->application->frame->userId;
    }

    /** @return bool Whether the frame supersedes the previous session identity */
    public function changesIdentity(): bool
    {
        return $this->changesComposer() || $this->token !== $this->application->frame->sessionToken;
    }

    /**
     * @param SessionStateSignalData $frame New projection for the address
     * @param ?Connection $current Current socket row
     * @return bool Whether the new projection can complete the same handshake obligation
     */
    public function canReplaceWith(SessionStateSignalData $frame, ?Connection $current): bool
    {
        $previous = $this->application->frame;
        return $this->stillAddresses($current) && $previous->sessionId === $frame->sessionId
            && $previous->userId === $frame->userId && $previous->sessionToken === $frame->sessionToken;
    }

    /**
     * @param ?SessionStateVisit $visit Handshake awaited by a pending reply at this address
     * @return bool Whether that reply belongs to the same pending visit or already greeted row
     */
    public function servesReplyAt(?SessionStateVisit $visit): bool
    {
        return $visit === $this->visit || ($this->connection !== null && $visit?->connection?->sameIncarnation($this->connection));
    }

    /**
     * @param ?Connection $current Connection read by the owner for this visit
     * @return bool Whether the accepted target still addresses the same row and identity
     */
    public function stillAddresses(?Connection $current): bool
    {
        if ($current === null) {
            return $this->connection === null;
        }

        return $this->connection !== null && $this->connection->sameIncarnation($current)
            && $this->sessionId === $current->sessionId && $this->userId === $current->userId
            && $this->token === $current->sessionToken;
    }
}
