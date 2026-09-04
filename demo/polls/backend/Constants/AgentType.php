<?php

declare(strict_types=1);

namespace Demo\Polls\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the polls demo.
 *
 * Defines agent type identifiers used in the polls demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** @var string Polls agent type (monopolistic) */
    public const string POLLS = 'polls';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;

    /** @var string Hilos logs agent type (serves every screen of the logs section) */
    public const string HILOS_LOGS = HilosAgentType::HILOS_LOGS;
}
