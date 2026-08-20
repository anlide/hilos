<?php

declare(strict_types=1);

namespace Demo\Tasks\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the tasks demo.
 *
 * Defines agent type identifiers used in the tasks demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** @var string Tasks agent type (monopolistic) */
    public const string TASKS = 'tasks';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;
}
