<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the online-testing demo.
 *
 * Defines agent type identifiers used in the online-testing demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** @var string Online-testing app agent type (monopolistic) */
    public const string ONLINE_TESTING = 'online_testing';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;
}
