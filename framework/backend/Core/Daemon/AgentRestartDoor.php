<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Peer\DTO\PeerAgentRestartDTO;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/** Master door for a command-channel request to restart an agent. */
interface AgentRestartDoor
{
    /**
     * @param CommandRequestDTO $request Operator request held by the command channel
     * @throws EventLoopException When the restart caller deadline cannot be armed
     */
    public function requestAgentRestart(CommandRequestDTO $request): void;

    /**
     * @param PeerAgentRestartDTO $frame Authenticated peer request; never forwarded again
     * @throws EventLoopException When the restart caller deadline cannot be armed
     */
    public function requestPeerAgentRestart(PeerAgentRestartDTO $frame): void;
}
