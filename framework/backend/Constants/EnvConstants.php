<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * EnvConstants - Environment variable name constants.
 *
 * Defines all environment variable names used by the framework.
 * Centralized environment variable name management prevents typos and ensures consistency.
 * Using Pure Enum where the name IS the value.
 *
 * Read values through Hilos::$env:
 * Hilos::$env[EnvConstants::HILOS_DAEMON_HOST]
 */
enum EnvConstants
{
    /** @var string Daemon host for CLI connection */
    case HILOS_DAEMON_HOST;

    /** @var string HTTP status server host */
    case HTTP_STATUS_HOST;

    /** @var string HTTP status server port */
    case HTTP_STATUS_PORT;

    /** @var string HTTP status server: allow persistent connections (keep-alive); true|false */
    case HTTP_STATUS_KEEP_ALIVE;

    /** @var string Worker communication host */
    case WORKER_COMM_HOST;

    /** @var string Worker communication port */
    case WORKER_COMM_PORT;

    /** @var string CLI command channel host */
    case COMMAND_HOST;

    /** @var string CLI command channel port */
    case COMMAND_PORT;

    /** @var string Database host */
    case DB_HOST;

    /** @var string Database port */
    case DB_PORT;

    /** @var string Database name */
    case DB_NAME;

    /** @var string Database user */
    case DB_USER;

    /** @var string Database password */
    case DB_PASSWORD;

    /** @var string Database root password */
    case DB_ROOT_PASSWORD;

    /** @var string Database username (Laravel-style, used in demo) */
    case DB_USERNAME;

    /** @var string Database name (Laravel-style, used in demo) */
    case DB_DATABASE;

    /** @var string Secondary database host */
    case DB_SECONDARY_HOST;

    /** @var string Secondary database username */
    case DB_SECONDARY_USERNAME;

    /** @var string Secondary database password */
    case DB_SECONDARY_PASSWORD;

    /** @var string Secondary database name */
    case DB_SECONDARY_DATABASE;

    /** @var string Secondary database port */
    case DB_SECONDARY_PORT;

    /** @var string Daemon log file path */
    case DAEMON_LOG_FILE;

    /** @var string Daemon error log file path */
    case DAEMON_ERROR_LOG_FILE;

    /** @var string Docker network subnet */
    case DOCKER_NETWORK_SUBNET;

    /** @var string Docker daemon IP address */
    case DOCKER_DAEMON_IP;

    /** @var string Docker detection: 'true' when running in container */
    case DOCKER;

    /** @var string Terminal type (xterm, dumb, etc.) for TUI detection */
    case TERM;

    /** @var string WebSocket server host */
    case WEBSOCKET_HOST;

    /** @var string WebSocket server port */
    case WEBSOCKET_PORT;

    /** @var string Socket read buffer size in bytes */
    case SOCKET_READ_BUFFER_SIZE;

    /** @var string Minimum number of regular worker processes to run */
    case WORKER_MIN_REGULAR;

    /** @var string Minimum number of monopolistic worker processes to run */
    case WORKER_MIN_MONOPOLISTIC;

    /** @var string Maximum number of regular worker processes that can be started */
    case WORKER_MAX_REGULAR;

    /** @var string Minimum interval between daemon restarts after errors (in seconds) */
    case DAEMON_MIN_RESTART_INTERVAL;

    // ── LLM ────────────────────────────────────────────────────────────────

    /** @var string LLM chat provider: local | external */
    case LLM_CHAT_PROVIDER;

    /** @var string Local LLM base URL (e.g. Ollama) */
    case LLM_LOCAL_URL;

    /** @var string Local LLM chat model name */
    case LLM_LOCAL_CHAT_MODEL;

    /** @var string External LLM base URL (e.g. OpenAI) */
    case LLM_EXTERNAL_URL;

    /** @var string External LLM API key */
    case LLM_EXTERNAL_API_KEY;

    /** @var string External LLM chat model name */
    case LLM_EXTERNAL_CHAT_MODEL;

    // ── Chat moderation (demo) ──────────────────────────────────────────────

    /** @var string Moderation model name */
    case CHAT_MODERATION_MODEL;

    /** @var string Moderation API base URL */
    case CHAT_MODERATION_URL;

    /** @var string Moderation request timeout in seconds */
    case CHAT_MODERATION_TIMEOUT_SEC;

    /** @var string Moderation provider: local | external */
    case CHAT_MODERATION_PROVIDER;

    /** @var string Enable moderation for bot messages: 1|0, true|false (default: false) */
    case CHAT_MODERATION_BOTS;

    // ── Chat context analyzer (demo) ────────────────────────────────────────

    /** @var string Context analyzer model name */
    case CHAT_CONTEXT_ANALYZER_MODEL;

    /** @var string Context analyzer API base URL */
    case CHAT_CONTEXT_ANALYZER_URL;

    /** @var string Context analyzer request timeout in seconds */
    case CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC;

    /** @var string Context analyzer provider: local | external */
    case CHAT_CONTEXT_ANALYZER_PROVIDER;

    // ── Chat bot (demo) ────────────────────────────────────────────────────────

    /** @var string Bot model name */
    case CHAT_BOT_MODEL;

    /** @var string Bot API base URL */
    case CHAT_BOT_URL;

    /** @var string Bot request timeout in seconds */
    case CHAT_BOT_TIMEOUT_SEC;

    /** @var string Bot provider: local | external */
    case CHAT_BOT_PROVIDER;

    /** @var string Chat language: ru, en (ISO 639-1). Bots respond in this language. */
    case CHAT_BOT_LANGUAGE;

    // ── Frontend (demo) ──────────────────────────────────────────────────────

    /** @var string Path to frontend dist for daemon static server */
    case FRONTEND_DIST_PATH;

    /** @var string Host for HTML file server */
    case FRONTEND_HTML_HOST;

    /** @var string Port for HTML file server */
    case FRONTEND_HTML_PORT;

    // ── Application environment ──────────────────────────────────────────────

    /**
     * Application environment. Use AppEnv enum values: prod, staging, dev, local, test.
     * Aliases accepted: production->prod, development->dev, etc.
     * When PROD or STAGING: database seeds are disabled.
     */
    case APP_ENV;

    /**
     * Build timestamp carried in the WebSocket handshake welcome frame.
     * Bumped at frontend build time; the frontend compares it on every
     * (re)connect and forces a page refresh on mismatch. 'dev' when unset.
     */
    case HILOS_BUILD_TIMESTAMP;

    /**
     * Name of the session-token cookie the daemon sets on the WebSocket
     * handshake (101) when the client has none. Override to rename it.
     */
    case HILOS_SESSION_COOKIE_NAME;

    /**
     * Whether the daemon issues the session-token cookie on the handshake.
     * Default true; set false to opt a project out of framework-issued cookies.
     */
    case HILOS_SESSION_COOKIE_ENABLED;

    /**
     * Whether the session-token cookie carries the Secure attribute. Default
     * false so it works over the plain-http dev stack; set true under TLS.
     */
    case HILOS_SESSION_COOKIE_SECURE;

    /**
     * Session lifetime in seconds. Drives a session row's expiry (and tracks the
     * session cookie Max-Age). Default 30 days.
     */
    case HILOS_SESSION_COOKIE_MAX_AGE;

    // ── User verification (email confirm / password recovery) ────────────────

    /**
     * Number of digits in a verification code (HIL-365). Default 6. Read as an
     * upper bound on the random_int range the issuer draws.
     */
    case HILOS_VERIFICATION_CODE_LENGTH;

    /** @var string Seconds a verification code stays valid before it expires. Default 900 (15m). */
    case HILOS_VERIFICATION_TTL_SEC;

    /** @var string Maximum verify attempts against one code before it is voided. Default 5. */
    case HILOS_VERIFICATION_MAX_ATTEMPTS;

    /** @var string Minimum seconds between two issued codes for one (type, identifier). Default 60. */
    case HILOS_VERIFICATION_RESEND_COOLDOWN_SEC;

    // ── Backup ───────────────────────────────────────────────────────────────

    /**
     * Root directory of the backup storage tree. Backups live under
     * `<BACKUP_DIR>/<scope>/`. Empty disables the storage scan.
     */
    case BACKUP_DIR;

    /**
     * Whether the backup subsystem is active. Default false: a project opts in
     * by setting it true and registering the backup agent.
     */
    case BACKUP_ENABLED;

    /**
     * Absolute path to the CLI entry script (cli.php) the backup supervisor spawns as
     * `php <BACKUP_CLI_ENTRY> backup:run <id> --scope=<scope>` to run one backup off the
     * daemon loop. Empty disables create; the read path (history scan) still works.
     */
    case BACKUP_CLI_ENTRY;

    /**
     * Backup child timeout in seconds. The supervisor kills a run that exceeds it and
     * records the attempt as an error. Default 1800 (30 min).
     */
    case BACKUP_TIMEOUT;

    /**
     * Retention depth (number of buckets kept) of the daily rotation tier. One shared
     * set of tier depths applies to every scope's grid. Default 45.
     */
    case BACKUP_RETENTION_DAILY;

    /** Retention depth of the ISO-week rotation tier, shared across scopes. Default 45. */
    case BACKUP_RETENTION_WEEKLY;

    /** Retention depth of the calendar-month rotation tier, shared across scopes. Default 45. */
    case BACKUP_RETENTION_MONTHLY;

    /** Retention depth of the calendar-year rotation tier, shared across scopes. Default 45. */
    case BACKUP_RETENTION_YEARLY;

    /**
     * How many of the newest error records rotation keeps; older ones are deleted. Error
     * records never enter the restore grids, so they get their own count. Default 20.
     */
    case BACKUP_ERROR_RETENTION_COUNT;

    // ── Cluster ──────────────────────────────────────────────────────────────

    /**
     * Whether this daemon participates in a cluster. Default false: the daemon
     * runs as a single node exactly as today (non-cluster is the first-class
     * default). When true, the node-identity variables below become required.
     */
    case CLUSTER_ENABLED;

    /** @var string Self-declared cluster node id (unique per node). Required when CLUSTER_ENABLED. */
    case CLUSTER_NODE_ID;

    /** @var string Self-declared node role: master | slave. Required when CLUSTER_ENABLED. */
    case CLUSTER_NODE_ROLE;

    /** @var string Declared node capability tags, comma-separated (e.g. "gpu-local,ssd"). */
    case CLUSTER_NODE_CAPABILITIES;

    /** @var string Host to bind this node's peer transport listener. Required when CLUSTER_ENABLED. */
    case CLUSTER_PEER_HOST;

    /** @var string Port to bind this node's peer transport listener. Required when CLUSTER_ENABLED. */
    case CLUSTER_PEER_PORT;

    /** @var string Comma-separated host:port seed peers to dial on join; empty for the first (bootstrap) node. */
    case CLUSTER_SEEDS;

    /** @var string Host:port this node advertises to peers as its own reachable address; falls back to CLUSTER_PEER_HOST:PORT. */
    case CLUSTER_PEER_ADVERTISE;

    /** @var string Comma-separated node ids of the static expected-master-set; quorum = floor(n/2)+1. Required when CLUSTER_ENABLED and role=master. */
    case CLUSTER_MASTER_SET;

    /** @var string Lower bound in ms of the randomized election timeout. Default 1500. */
    case CLUSTER_ELECTION_TIMEOUT_MIN_MS;

    /** @var string Upper bound in ms of the randomized election timeout. Default 3000. */
    case CLUSTER_ELECTION_TIMEOUT_MAX_MS;

    /** @var string Leader heartbeat interval in ms; must be below the election minimum. Default 500. */
    case CLUSTER_HEARTBEAT_INTERVAL_MS;

    /**
     * @var string Grace period in ms a slave keeps its in-flight work running after a
     * leader change while it awaits the new leader's work-decision; bounds an isolated
     * slave so it does not run forever. Default 6000. Consumed by the slave self-fence
     * (HIL-183): a slave isolated from the leader stops its placed agents after this
     * grace, and it is held at or below the failover grace so the old copy stops before
     * the leader starts a new one.
     */
    case CLUSTER_SLAVE_WORK_GRACE_MS;

    /**
     * @var string Interval in ms a peer link may stay silent before it sends a keepalive
     * ping; any inbound frame resets the timer, so a busy link never pings. Default 1000.
     */
    case CLUSTER_LINK_KEEPALIVE_INTERVAL_MS;

    /**
     * @var string Duration in ms of link silence after which a peer link is closed as
     * dead (a hung-but-connected node the keepalive ping never answered), reusing the
     * ordinary link-close failover path. Also bounds a stalled half-open handshake.
     * Must exceed the keepalive interval. Default 5000.
     */
    case CLUSTER_LINK_TIMEOUT_MS;

    /**
     * @var string Grace period in ms the leader waits after a node hosting placed agents
     * goes offline before it re-places those agents elsewhere, absorbing a brief flap.
     * Held at or above the slave work grace so an isolated node stops its copy first.
     * Default 8000.
     */
    case CLUSTER_FAILOVER_GRACE_MS;
}
