<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;

/** Unfinished effects of one SessionState already delivered to the Chat owner. */
final class SessionStateApplication
{
    public ?HandshakeResponseSignalData $identity = null;
    public bool $refused = false;

    /** @var array<string, true> Addresses already sent the identity */
    public array $applied = [];

    public ?SessionStateVisit $initiator = null;

    /**
     * @param SessionStateSignalData $frame Delivered identity and any reply it carries
     * @param int $remaining Number of accepted addresses still awaiting a decision
     */
    public function __construct(
        public readonly SessionStateSignalData $frame,
        public int $remaining,
    ) {
    }

    /** @return int|string Correlation within the initiating socket's pending replies */
    public function replyKey(): int|string
    {
        return $this->frame->requestId === null ? spl_object_id($this) : ':' . $this->frame->requestId;
    }
}
