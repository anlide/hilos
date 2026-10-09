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
    /** Analytics section agent type. */
    public const string HILOS_ANALYTICS = HilosAgentType::HILOS_ANALYTICS;

    /** @var string Online-testing app agent type (monopolistic) */
    public const string ONLINE_TESTING = 'online_testing';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;

    /** @var string Hilos notifications library agent type */
    public const string HILOS_NOTIFICATIONS_LIBRARY = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;

    /** @var string Hilos logs section agent type */
    public const string HILOS_LOGS = HilosAgentType::HILOS_LOGS;

    /** Daemon section page agent type. */
    public const string HILOS_DAEMON = HilosAgentType::HILOS_DAEMON;
}
