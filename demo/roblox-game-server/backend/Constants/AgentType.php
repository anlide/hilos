<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for the roblox-game-server demo.
 *
 * Defines agent type identifiers used in the roblox-game-server demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** Change Log section reader agent type. */
    public const string HILOS_CHANGE_LOG = HilosAgentType::HILOS_CHANGE_LOG;

    /** Analytics section agent type. */
    public const string HILOS_ANALYTICS = HilosAgentType::HILOS_ANALYTICS;

    /** @var string Roblox game server app agent type (monopolistic) */
    public const string ROBLOX_GAME_SERVER = 'roblox_game_server';

    /** @var string Hilos index agent type (dashboard the shell gear links to) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;

    /** @var string Hilos notifications library agent type (owns the rows and the bell's group) */
    public const string HILOS_NOTIFICATIONS_LIBRARY = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;

    /** @var string Hilos logs agent type (serves every screen of the logs section) */
    public const string HILOS_LOGS = HilosAgentType::HILOS_LOGS;

    /** Daemon section page agent type. */
    public const string HILOS_DAEMON = HilosAgentType::HILOS_DAEMON;
}
