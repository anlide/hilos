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

    /**
     * Seconds the daemon waits for every process it told to re-read a replaced database before
     * it gives up and reports the barrier as unclosed (HIL-436). Re-reading runs over the
     * connection each process already holds, so minutes here would not mean slow work - they
     * would mean a dead process, and the wait stands between the operator and the answer of
     * whether the restore actually came back. Default 30.
     */
    case HILOS_DB_REHYDRATE_TIMEOUT;

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

    /** @var string Consecutive failed daemon starts after which the watchdog logs an error */
    case DAEMON_FAILED_START_THRESHOLD;

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
     * Session lifetime in seconds, the single number behind both halves of a
     * session: the row's expiry and the cookie's Max-Age, each slid forward by
     * the same handshake. Default two years; shorten it to tighten sessions.
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

    /**
     * Length in seconds of the window issued codes are counted in (HIL-421).
     * Default 3600 (1h). The cooldown alone stops a burst; this stops a patient
     * drip that keeps sending forever, one code per cooldown.
     */
    case HILOS_VERIFICATION_SEND_WINDOW_SEC;

    /** @var string Codes one (type, identifier) may be sent per window. Default 5. */
    case HILOS_VERIFICATION_SEND_CAP;

    /**
     * Codes one (type, identifier) may be sent per window over SMS. Default 3:
     * lower than the email cap because every message costs money.
     */
    case HILOS_VERIFICATION_SEND_CAP_SMS;

    /**
     * Absolute address of the screen a magic link comes back to (HIL-417). The
     * issued token and the address it was issued for are appended to it as query
     * params, and the result is what the letter carries - so a relative path
     * would not survive the mail client. The framework knows no base URL of its
     * own, which is why this is the project's to set, symmetric with the OAuth
     * redirect URI.
     */
    case HILOS_MAGIC_LINK_URL;

    // ── Auth throttle (anti-abuse on expensive auth actions) ─────────────────

    /**
     * Whether the throttle layer refuses anything (HIL-420). Default true. Set
     * false to let every guarded action through - which is what the test
     * environment does, since counting attempts across a suite makes each test
     * depend on the ones that ran before it.
     */
    case HILOS_AUTH_THROTTLE_ENABLED;

    /** @var string Length in seconds of the fixed window attempts are counted in. Default 60. */
    case HILOS_AUTH_THROTTLE_WINDOW;

    /** @var string Attempts one session may make on one action per window. Default 10. */
    case HILOS_AUTH_THROTTLE_MAX_SESSION;

    /**
     * Attempts one IP may make on one action per window. Default 30: higher than
     * the session limit because an IP is shared by everyone behind a NAT.
     */
    case HILOS_AUTH_THROTTLE_MAX_IP;

    /**
     * Block durations in seconds, comma-separated, one per escalation step
     * (HIL-420). Default `30,120,600,3600`. A key that breaches again while it
     * is already at the last step stays there - the ladder does not run off its
     * own end.
     */
    case HILOS_AUTH_THROTTLE_STEPS;

    /**
     * Milliseconds a deferred action waits for the agent's verdict before it is
     * executed anyway. Default 1000. A verdict that never arrives is a fault of
     * this server, not evidence against the client; blocks already consummated
     * do not leak through it, since those are refused by the fast path.
     */
    case HILOS_AUTH_THROTTLE_VERDICT_TIMEOUT_MS;

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
     * Restore child timeout in seconds. The supervisor kills a hot restore that exceeds it
     * and records the run as failed; the database may be left partially restored (HIL-436).
     * The cold path (`backup:restore --cold`) runs in the operator's own process and is not
     * subject to it. Default 3600 (60 min) - a restore replays every dump serially, so it
     * gets more room than a single create pass.
     */
    case BACKUP_RESTORE_TIMEOUT;

    /**
     * Age **in days** from which rotation keeps only the newest backup of each day; nothing
     * younger is thinned at all. One shared ladder applies to every scope's grid. Default 45.
     */
    case BACKUP_RETENTION_DAILY;

    /** Age in weeks from which only the newest backup of each ISO week is kept. Default 45. */
    case BACKUP_RETENTION_WEEKLY;

    /** Age in months from which only the newest backup of each month is kept. Default 45. */
    case BACKUP_RETENTION_MONTHLY;

    /** Age in years from which only the newest backup of each year is kept. Default 45. */
    case BACKUP_RETENTION_YEARLY;

    /**
     * How many of the newest error records rotation keeps; older ones are deleted. Error
     * records never enter the restore grids, so they get their own count. Default 20.
     */
    case BACKUP_ERROR_RETENTION_COUNT;

    /**
     * Multiplier the space guard applies to the estimated uncompressed peak before comparing
     * it to free space, so a run is refused with headroom rather than at the exact edge.
     * Float, default 1.5.
     */
    case BACKUP_SPACE_MARGIN;

    /**
     * Absolute free-space floor in bytes: a run is refused when free space is below it, checked
     * on every run whether or not an estimate exists. It also covers a database that grew
     * sharply since the last measured runs. Default 1073741824 (1 GiB).
     */
    case BACKUP_MIN_FREE_BYTES;

    /**
     * What to do when no prior successful run of the scope carries a dump size to estimate from:
     * false proceeds (the default, so a first backup on a clean install is never blocked), true
     * refuses. Default false.
     */
    case BACKUP_REFUSE_WITHOUT_ESTIMATE;

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

    /**
     * @var string Comma-separated node ids of the static expected-master-set; quorum = floor(n/2)+1.
     *     Required when CLUSTER_ENABLED and role=master.
     */
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

    // ── WebAuthn / passkey (HIL-284) ─────────────────────────────────────────

    /**
     * @var string WebAuthn Relying Party id — the registrable domain the passkey is
     * scoped to (no scheme/port), e.g. "example.com". The authenticator hashes it into
     * authenticatorData; verification compares against SHA-256 of this value. Default
     * "localhost" for the dev stack.
     */
    case HILOS_WEBAUTHN_RP_ID;

    /** @var string Human-readable Relying Party name shown by the authenticator UI. Default "Hilos". */
    case HILOS_WEBAUTHN_RP_NAME;

    /**
     * @var string Comma-separated list of allowed ceremony origins (scheme://host[:port]),
     * e.g. "https://example.com,https://www.example.com". clientDataJSON.origin must match
     * one entry exactly. Default "http://localhost" for the dev stack.
     */
    case HILOS_WEBAUTHN_ORIGIN;

    /** @var string Seconds a WebAuthn challenge stays valid before it expires. Default 300 (5m). */
    case HILOS_WEBAUTHN_CHALLENGE_TTL_SEC;

    /** @var string Requested user-verification level: required | preferred | discouraged. Default "preferred". */
    case HILOS_WEBAUTHN_USER_VERIFICATION;

    /** @var string Client-side ceremony timeout in milliseconds carried in the publicKey options. Default 60000. */
    case HILOS_WEBAUTHN_TIMEOUT_MS;

    /**
     * @var string HMAC secret for the stateless WebAuthn challenge token. Env-only; must be a
     * long random value in any real deployment. Empty in dev (weak, dev-only signing).
     */
    case HILOS_WEBAUTHN_CHALLENGE_SECRET;

    // ── Mail (HIL-197) ───────────────────────────────────────────────────────

    /**
     * @var string Forced mail driver: smtp | file. Empty auto-selects the file transport
     * whenever MAIL_SMTP_HOST is empty, so a project with no relay still writes a .eml.
     */
    case MAIL_TRANSPORT;

    /** @var string SMTP relay host; empty (with no forced driver) auto-selects the file transport. */
    case MAIL_SMTP_HOST;

    /** @var string SMTP relay port. Default 587 (STARTTLS submission). */
    case MAIL_SMTP_PORT;

    /** @var string SMTP transport security: starttls | tls | none. Default starttls. */
    case MAIL_SMTP_SECURITY;

    /** @var string SMTP AUTH username; empty for an unauthenticated relay. Env-only secret. */
    case MAIL_SMTP_USERNAME;

    /** @var string SMTP AUTH password; empty for an unauthenticated relay. Env-only secret. */
    case MAIL_SMTP_PASSWORD;

    /** @var string Envelope/From address applied when encoding an outgoing message. */
    case MAIL_FROM_ADDRESS;

    /** @var string Sender display name; empty sends the bare From address. */
    case MAIL_FROM_NAME;

    /** @var string Per-send timeout in milliseconds. Default 10000. */
    case MAIL_TIMEOUT_MS;

    /**
     * @var string Number of mail agents in the sharded hilos_mail pool; the shard key is
     * 1 + crc32(address) % this. Default 1.
     */
    case MAIL_WORKER_COUNT;

    /** @var string Concurrent SMTP dialogs one mail agent runs at once. Default 4. */
    case MAIL_MAX_CONCURRENT;

    /** @var string Directory the file transport writes .eml artifacts to; empty writes none. */
    case MAIL_FILE_DIR;

    // ── SMS (HIL-285) ────────────────────────────────────────────────────────

    /**
     * @var string Forced SMS provider: generic | stub. Empty auto-selects the stub whenever
     * SMS_ENDPOINT_URL is empty, so a project with no gateway still writes a .txt artifact.
     */
    case SMS_PROVIDER;

    /** @var string SMS gateway endpoint URL; empty (with no forced provider) auto-selects the stub. */
    case SMS_ENDPOINT_URL;

    /** @var string Sender id / from number applied to outgoing messages; empty sends none. */
    case SMS_FROM;

    /** @var string SMS gateway API key/token; empty for an unauthenticated gateway. Env-only secret. */
    case SMS_API_KEY;

    /** @var string SMS gateway API password (basic auth); empty for none. Env-only secret. */
    case SMS_API_PASSWORD;

    /** @var string Per-send timeout in milliseconds. Default 10000. */
    case SMS_TIMEOUT_MS;

    /**
     * @var string Number of SMS agents in the sharded hilos_sms pool; the shard key is
     * 1 + crc32(number) % this. Default 1.
     */
    case SMS_WORKER_COUNT;

    /** @var string Directory the stub provider writes .txt artifacts to; empty writes none. */
    case SMS_FILE_DIR;

    // ── Web push (HIL-199) ───────────────────────────────────────────────────

    /**
     * @var string VAPID application-server public key (base64url, uncompressed P-256 point).
     * Baked into every browser subscription; empty leaves the push channel unconfigured. Not a secret —
     * it is served to the frontend so the browser can subscribe. Generate the pair once and keep it stable.
     */
    case VAPID_PUBLIC;

    /**
     * @var string VAPID application-server private key (base64url). Signs the push requests; empty leaves the
     * push channel unconfigured. Env-only secret — never editable and never sent to the browser.
     */
    case VAPID_PRIVATE;

    /**
     * @var string VAPID contact subject carried in the signed request JWT: a `mailto:` address or an operator URL.
     * The push service uses it to reach the operator about a misbehaving application server; empty leaves the push
     * channel unconfigured. Not a secret, but not served to the browser either.
     */
    case VAPID_SUBJECT;

    /**
     * @var string Number of push agents in the sharded hilos_push pool; the shard key is
     * 1 + user_id % this. Default 1.
     */
    case PUSH_WORKER_COUNT;

    // ── Log rotation (HIL-379) ───────────────────────────────────────────────

    /**
     * @var string Seconds of elapsed time since the last rotation after which the per-node
     * LogRotationAgent rotates the live logs into the archive. Default 0 disables the age
     * criterion; 0 for both age and size preserves the start-only rotation behavior.
     */
    case LOG_ROTATION_MAX_AGE_SECONDS;

    /**
     * @var string Summed size in bytes of the live *.log files above which the per-node
     * LogRotationAgent rotates them into the archive. Default 0 disables the size criterion;
     * 0 for both age and size preserves the start-only rotation behavior.
     */
    case LOG_ROTATION_MAX_LIVE_SIZE_BYTES;

    /**
     * @var string Five-field cron expression (server timezone) on which the per-node
     * LogRotationAgent rotates the live logs — the planned-rotation axis alongside the age and
     * size axes. Empty disables the schedule axis; an unparseable expression is logged and also
     * leaves it disabled. A missed window is not caught up (the state is worker-process-local).
     */
    case LOG_ROTATION_CRON;

    // ── Log archive retention (HIL-381) ──────────────────────────────────────

    /**
     * @var string How many of the newest archived rotation batches are always kept, exempt
     * from eviction regardless of age. Default 20; 0 disables the count criterion (with a
     * 0 max-age too, nothing is ever a candidate).
     */
    case LOG_ARCHIVE_RETENTION_KEEP_BATCHES;

    /**
     * @var string Age in seconds beyond which an archived rotation batch becomes an eviction
     * candidate, provided it is also outside the newest kept batches. Default 2592000 (30 days);
     * 0 disables the age criterion (with a 0 keep-batches too, nothing is ever a candidate).
     */
    case LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS;
}
