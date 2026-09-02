<?php

declare(strict_types=1);

namespace Hilos\Core\Source;

use Throwable;

/**
 * Receiver of one collection mutation, called synchronously at the point of mutation.
 *
 * Implementations run inside the write that produced the fact, so they are for reactions that
 * must already have happened when the write returns - fixing a view the mutation invalidated,
 * queueing the outgoing sync. Anything a later moment can serve stays where it is: browser
 * tables and project agents are notified at the end of the tick, and moving them here would
 * let a subscriber mutate a collection from inside its own announcement.
 *
 * A reaction that fails is wrapped by {@see SourceChangeBus::publish()}, which is why this
 * contract names Throwable rather than the framework root: the implementer owes the bus no
 * exception discipline, and the caller of the write is told one narrow type instead.
 */
interface SourceChangeSubscriberInterface
{
    /**
     * Reacts to one announced collection mutation.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @param SourceChangeProvenance $provenance Whether this process authored the write
     * @throws Throwable Whatever this subscriber's own reaction raises - the bus wraps it, so no discipline is required of the implementer
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void;
}
