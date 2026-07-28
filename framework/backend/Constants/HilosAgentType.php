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

    /** @var string Hilos backup agent (monopoly owner of the backup index and storage) */
    public const string HILOS_BACKUP = 'hilos_backup';

    /** @var string Hilos OAuth agent (pre-auth async owner of in-flight OAuth login exchanges) */
    public const string HILOS_OAUTH = 'hilos_oauth';

    /** @var string Hilos mail agent (sharded pool delivering the email channel and raw sends) */
    public const string HILOS_MAIL = 'hilos_mail';
}
