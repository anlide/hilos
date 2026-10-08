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

    /** Change Log section agent: the only reader of the journal for the section. */
    public const string HILOS_CHANGE_LOG = 'hilos_change_log';

    /** Legal section agent: serves its five pages and holds their tallies. */
    public const string HILOS_LEGAL = 'hilos_legal';

    /** @var string Hilos logs overview agent (rotation metrics under daemon log archive) */
    public const string HILOS_LOGS = 'hilos_logs';

    /** Daemon section page agent. */
    public const string HILOS_DAEMON = 'hilos_daemon';

    /** Daemon section agent running on every node. */
    public const string HILOS_DAEMON_NODE = 'hilos_daemon_node';

    /** Daemon section collector placed once per cluster. */
    public const string HILOS_DAEMON_COLLECTOR = 'hilos_daemon_collector';

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

    /** @var string Hilos auth throttle agent (the cluster's one truth source of the anti-abuse attempt counters and blocks) */
    public const string HILOS_AUTH_THROTTLE = 'hilos_auth_throttle';

    /** @var string Hilos uploads agent (sole owner of the upload sessions: declared files, their signed chunks and temporary files) */
    public const string HILOS_UPLOADS = 'hilos_uploads';

    /** @var string Hilos images agent (renders registry picture variants into temporary files for the files library) */
    public const string HILOS_IMAGES = 'hilos_images';

    /** @var string Hilos auth code agent (async owner of probing, minting and delivering phone one-time codes) */
    public const string HILOS_AUTH_CODE = 'hilos_auth_code';

    /** @var string Hilos users library agent (owner of the user set and of every sign-in command over it) */
    public const string HILOS_USERS_LIBRARY = 'hilos_users_library';

    /** Agent for one person's row and child sets. */
    public const string HILOS_USER = 'hilos_user';

    /** @var string Hilos sessions library agent (owner of the session set, its handshake and the sockets' identity) */
    public const string HILOS_SESSIONS_LIBRARY = 'hilos_sessions_library';

    /** @var string Hilos notifications library agent (owner of the notification set, its preferences, deliveries and push endpoints) */
    public const string HILOS_NOTIFICATIONS_LIBRARY = 'hilos_notifications_library';

    /** @var string Hilos files library agent (owner of the files registry, its bind door and the janitor of unbound files) */
    public const string HILOS_FILES_LIBRARY = 'hilos_files_library';

    /** @var string Hilos settings library agent (single writer of the settings collection) */
    public const string HILOS_SETTINGS_LIBRARY = 'hilos_settings_library';

    /** Cluster library owning the language, country and locale reference. */
    public const string HILOS_I18N_LIBRARY = 'hilos_i18n_library';

    /** @var string Hilos log store agent (per-node monopolistic owner of the log directory and of the node's log index) */
    public const string HILOS_LOG_STORE = 'hilos_log_store';

    /** Cluster-wide monopolistic builder of personal data archives. */
    public const string HILOS_DATA_EXPORT = 'hilos_data_export';

    /** @var string Hilos log carrier agent (per-node monopolistic mover of rotated batches from staging into the archive) */
    public const string HILOS_LOG_CARRIER = 'hilos_log_carrier';

    /** @var string Hilos log aggregator agent (cluster-wide owner of the merged log index across nodes) */
    public const string HILOS_LOG_AGGREGATOR = 'hilos_log_aggregator';

    /** @var string Hilos analytics journal agent (per-node monopolistic owner of the node's analytics journal files) */
    public const string HILOS_ANALYTICS_JOURNAL = 'hilos_analytics_journal';

    /** @var string Hilos analytics writer agent (cluster-wide monopolistic loader of journal files into the analytics tables) */
    public const string HILOS_ANALYTICS_WRITER = 'hilos_analytics_writer';

    /** @var string Cluster probe: member of the placed fleet of synthetic workers, one row of the fleet statuses each */
    public const string HILOS_PROBE_FLEET = 'hilos_probe_fleet';

    /** @var string Cluster probe: deliberate second owner of the whole fleet status collection */
    public const string HILOS_PROBE_CLAIMER = 'hilos_probe_claimer';

    /** @var string Cluster probe: placed agent that does nothing but hold a slice of its node's declared ram */
    public const string HILOS_PROBE_BALLAST = 'hilos_probe_ballast';

    /** @var string Cluster probe: per-node replica that writes and reads a settings row of the shared database */
    public const string HILOS_PROBE_DB = 'hilos_probe_db';

    /** @var string Cluster probe: per-node replica that owns this node's set of the probe notes */
    public const string HILOS_PROBE_RT_SET = 'hilos_probe_rt_set';
}
