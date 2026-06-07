<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HilosAgentType - Agent type constants for framework-level Hilos agents.
 *
 * Defines agent type identifiers for Hilos admin page agents.
 * Projects must create concrete agent classes extending the corresponding
 * abstract agents, or not use the related Hilos pages.
 */
final class HilosAgentType
{
    /** @var string Hilos index agent (dashboard, settings, i18n) */
    public const string HILOS_INDEX = 'hilos_index';

    /** @var string Hilos guardian agent (project validation robots) */
    public const string HILOS_GUARDIAN = 'hilos_guardian';

    /** @var string Hilos analytics agent (visit statistics) */
    public const string HILOS_ANALYTICS = 'hilos_analytics';

    /** @var string Hilos logs overview agent (rotation metrics under daemon log archive) */
    public const string HILOS_LOGS = 'hilos_logs';
}
