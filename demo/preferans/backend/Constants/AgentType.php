<?php

declare(strict_types=1);

namespace Demo\Preferans\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the preferans demo.
 *
 * Defines agent type identifiers used in the preferans demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** Analytics section agent type. */
    public const string HILOS_ANALYTICS = HilosAgentType::HILOS_ANALYTICS;

    /** @var string Preferans app agent type (monopolistic) */
    public const string PREFERANS = 'preferans';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;

    /** @var string Hilos notifications library agent type */
    public const string HILOS_NOTIFICATIONS_LIBRARY = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;

}
