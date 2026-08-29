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

    /** @var string Hilos SMS agent (sharded pool delivering the SMS channel and raw sends) */
    public const string HILOS_SMS = 'hilos_sms';

    /** @var string Hilos web-push agent (sharded pool delivering the push channel to a recipient's device endpoints) */
    public const string HILOS_PUSH = 'hilos_push';

    /** @var string Hilos log rotation agent (per-node worker owner of the time/size log rotation trigger) */
    public const string HILOS_LOG_ROTATION = 'hilos_log_rotation';

    /** @var string Hilos auth throttle agent (per-node truth source of the anti-abuse attempt counters and blocks) */
    public const string HILOS_AUTH_THROTTLE = 'hilos_auth_throttle';

    /** @var string Hilos auth code agent (async owner of probing, minting and delivering phone one-time codes) */
    public const string HILOS_AUTH_CODE = 'hilos_auth_code';

    /** @var string Hilos users library agent (owner of the user set and of every sign-in command over it) */
    public const string HILOS_USERS_LIBRARY = 'hilos_users_library';

    /** @var string Hilos sessions library agent (owner of the session set, its handshake and the sockets' identity) */
    public const string HILOS_SESSIONS_LIBRARY = 'hilos_sessions_library';

    /** @var string Hilos notifications library agent (owner of the notification set, its preferences, deliveries and push endpoints) */
    public const string HILOS_NOTIFICATIONS_LIBRARY = 'hilos_notifications_library';

    /** @var string Hilos log store agent (per-node monopolistic owner of the log directory and of the node's log index) */
    public const string HILOS_LOG_STORE = 'hilos_log_store';

    /** @var string Hilos log aggregator agent (cluster-wide owner of the merged log index across nodes) */
    public const string HILOS_LOG_AGGREGATOR = 'hilos_log_aggregator';
}
