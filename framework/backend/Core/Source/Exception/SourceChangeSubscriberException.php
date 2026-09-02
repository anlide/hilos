<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Exception;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\HilosException;
use Throwable;

/**
 * Exception: a subscriber of {@see SourceChangeBus} failed to react to an announced mutation.
 *
 * Named at the bus rather than at the interface because the interface cannot know its future
 * implementations and would have to name the root of the hierarchy. The original failure - an
 * exception or an Error alike - is kept in previous, so the cause is still reachable by walking
 * the chain.
 */
class SourceChangeSubscriberException extends HilosException
{
    /**
     * @param string $subscriberClass Class of the subscriber whose reaction failed
     * @param SourceChange $change Fact the subscriber was reacting to
     * @param Throwable $previous Original failure raised by the reaction
     */
    public function __construct(string $subscriberClass, SourceChange $change, Throwable $previous)
    {
        parent::__construct(
            sprintf(
                '%s failed to react to the %s %s of %s[%s]: %s',
                $subscriberClass,
                $change->kind,
                $change->mutationType->value,
                $change->sourceKey,
                $change->sourceId,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }
}
