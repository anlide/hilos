<?php

declare(strict_types=1);

namespace Hilos\AI\Agent;

/**
 * AbstractAiAgent - Base class for AI agent families.
 */
abstract class AbstractAiAgent implements AiAgentInterface
{
    /**
     * Run one lightweight AI agent tick.
     */
    abstract public function onTick(): void;
}
