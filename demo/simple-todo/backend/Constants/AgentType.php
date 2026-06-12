<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the simple-todo demo.
 *
 * Defines agent type identifiers used in the simple-todo demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** @var string Todo agent type (monopolistic) */
    public const string TODO = 'todo';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;
}
