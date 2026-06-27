<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the simple-poll demo.
 *
 * Defines agent type identifiers used in the simple-poll demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** @var string Poll agent type (monopolistic) */
    public const string POLL = 'poll';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;
}
