<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Core\Agent\AbstractAgent;

/**
 * AbstractHilosAgent - Abstract base for all Hilos admin section agents
 *
 * Base class for framework-level Hilos agents (index, guardian, analytics).
 * Projects must extend one of the concrete abstract subclasses to provide agents.
 */
abstract class AbstractHilosAgent extends AbstractAgent
{
    public function getIndex(): ?string
    {
        return null;
    }

    public function onStop(): void
    {
    }
}
