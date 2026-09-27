<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Database\View\Item\Session;

/**
 * Session a handshake answers with, and any ticket owed to the connecting socket.
 * The shared resolver also serves an operator, whose ticket is delivered to a live tab
 * during resolution instead of being returned for a handshake frame.
 */
final class HandshakeSession
{
    /**
     * @param Session $session Session resolved for the connecting browser
     * @param ?string $rotationTicket Ticket for its new cookie, or null when its token was kept
     */
    public function __construct(
        public readonly Session $session,
        public readonly ?string $rotationTicket,
    ) {
    }
}
