<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;

/**
 * AbandonedCommandSink - master-side seam the command channel reports a departed caller on.
 *
 * A command addressed to an agent that is still starting is held in the master like any other
 * frame, and that hold has no deadline of its own (HIL-1040): it ends on a fact. One of those
 * facts is that nobody is waiting for the answer any more - the operator's terminal closed, or
 * the channel gave up on its own window. The channel is the only side that knows it, and the
 * master is the only side holding the frame, so the fact has to cross between them.
 *
 * Narrow on purpose, like {@see AgentLossSink} and {@see ConnectionDropper} beside it: the
 * command server learns one door and not the manager behind it.
 *
 * It carries the correlation id and nothing else, because that is the whole of what the two
 * sides share. {@see CommandServer::abandon()} is the near side of it and
 * {@see CommandClient} the two events that reach it.
 *
 * There is no answer to give back. Whoever this frame was held for is gone, which is the very
 * thing being reported - a refusal written now would be addressed to nobody.
 */
interface AbandonedCommandSink
{
    /**
     * Takes the correlation id of a request whose caller is no longer waiting for it.
     *
     * Called once per abandoned request, after the channel has dropped its own hold, so an
     * implementation walking its own records finds the channel's already gone.
     *
     * @param string $correlationId Correlation id nobody is waiting on any more
     */
    public function onCommandAbandoned(string $correlationId): void;
}
