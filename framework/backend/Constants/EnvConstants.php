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

    /**
     * Seconds a quiesce round may stay open before the freeze it belongs to is reported as stuck
     * (HIL-482). The round is a handful of peer frames over an established mesh, so this measures
     * a node that is not answering rather than one that is busy: seconds, not minutes. Nothing is
     * lifted when it elapses - the leader only tells a person. Default 30.
     */
    case HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT;

    /**
     * Seconds a freeze may go without a sign of life before it is reported as stuck (HIL-482).
     * What proves life is that the WORK moved: the operation behind the freeze stamps the row when
     * something of its own advanced, so the LENGTH of that operation is not a parameter here and a
     * long restore never needs a longer value. What this measures is silence. Default 600.
     */
    case HILOS_PROTECTED_MODE_SILENCE_TIMEOUT;

    /**
     * Seconds between one alert about a stuck freeze and the next reminder (HIL-482). A stuck
     * freeze is a condition and not an event: the operator who was asleep, or whose mail bounced,
     * is the normal case, so the alert repeats while the node stays frozen. Default 900.
     */
    case HILOS_PROTECTED_MODE_ALERT_INTERVAL;

    /**
     * Comma-separated addresses the stuck-freeze alert is sent to (HIL-482). Addresses and not
     * users on purpose: the alarm fires precisely when the database may be half-written or
     * unreadable, and the master may not read it in any case. An empty list is legal and means
     * the watchdog logs and sends nothing - refusing to start over unconfigured mail would take
     * down nodes that will never need the watchdog at all. Default empty.
     */
    case HILOS_PROTECTED_MODE_ALERT_EMAILS;

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

    /**
     * Cron schedule of the sweep that clears abandoned registrations off session
     * rows (HIL-612). Five fields; default every five minutes. An EMPTY value
     * builds no rule at all, which is how a project that never registers anybody
     * pays nothing for the sweep.
     */
    case HILOS_PENDING_REGISTRATION_SWEEP_CRON;

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

    /**
     * Networks whose X-Real-IP header names the visitor, comma-separated, each in
     * CIDR notation and a single address written as /32 or /128 (HIL-680). Empty by
     * default, which trusts nobody and leaves every connection counted by the address
     * of its TCP peer. A deployment behind its own nginx puts that nginx's network
     * here; one that leaves it empty counts everyone behind the proxy as one client.
     * A zero-length prefix is refused (HIL-714): an entry trusting every peer would
     * hand the choice of address back to the client this variable guards against.
     */
    case HILOS_TRUSTED_PROXIES;

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
     * Total byte ceiling for the backup store, chosen from the size of the disk the backups
     * live on. Default 0 - no ceiling, and rotation is the age ladder alone. Above it rotation
     * thins further than the ladder would, oldest first, until the store fits again; it never
     * removes a pin, the newest backup of each scope, or an archive that has not been shipped
     * yet, so the ceiling is soft and an overflow it cannot reach is logged rather than forced.
     */
    case BACKUP_MAX_TOTAL_BYTES;

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

    /**
     * Where successful backups are copied to, so an archive does not only exist on the machine
     * that made it. Two schemes: `ssh://<user>@<host>[:<port>]/<absolute-path>` copies over
     * rsync/ssh, `file:///<absolute-path>` mirrors into a local directory (which also covers a
     * mounted network share). Empty - the default - means backups are not copied anywhere and
     * the subsystem behaves exactly as it did before shipping existed. Absence of a value, not
     * a third mode.
     */
    case BACKUP_SHIP_TARGET;

    /**
     * Absolute path to the private key file the `ssh` scheme authenticates with. Default empty;
     * unused by the `file` scheme.
     */
    case BACKUP_SHIP_SSH_KEY;

    /**
     * Absolute path to the `known_hosts` file the `ssh` scheme pins the receiver against. Default
     * empty, and an empty value turns the `ssh` scheme OFF rather than relaxing the check: host-key
     * checking stays strict, because the alternative is shipping an unencrypted copy of the whole
     * database to whoever answers on that address.
     */
    case BACKUP_SHIP_SSH_KNOWN_HOSTS;

    /**
     * Absolute path to the file of `age` recipients the copy leaving this machine is encrypted to,
     * one public key per line. Default empty, and an empty value is the ABSENCE of encryption
     * rather than a third mode: the copy leaves in the clear, exactly as it did before this
     * existed. A configured file that cannot be read, is empty, or names no recipient turns
     * shipping OFF altogether rather than falling back to a clear copy - the fallback is the very
     * exposure the key was configured against. The file holds PUBLIC keys only: this machine can
     * encrypt and not decrypt, so breaking into it does not open what has already left, and a
     * restore from the remote copy is the operator's own `age -d -i <private key>`.
     */
    case BACKUP_SHIP_ENCRYPT_RECIPIENTS;

    /**
     * Seconds after which the agent kills a hung transfer, modelled on {@see BACKUP_TIMEOUT} and
     * {@see BACKUP_RESTORE_TIMEOUT}. Default 3600 (60 min) - a copy crosses a link the local run
     * never touches, so it gets the wider of the two. Killing it is not a data loss: the local
     * archive is untouched and the copy is retried.
     */
    case BACKUP_SHIP_TIMEOUT;

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

    // ── Watchdog alert (HIL-617) ─────────────────────────────────────────────

    /**
     * @var string SMTP relay host the container watchdog mails its own incidents through.
     * Empty means "not configured": the watchdog logs the missing names and sends nothing —
     * unlike MAIL_*, it never falls back to the file transport.
     */
    case WATCHDOG_ALERT_SMTP_HOST;

    /** @var string SMTP relay port for the watchdog alert. Default 587 (STARTTLS submission). */
    case WATCHDOG_ALERT_SMTP_PORT;

    /** @var string SMTP transport security for the watchdog alert: starttls | tls | none. Default starttls. */
    case WATCHDOG_ALERT_SMTP_SECURITY;

    /** @var string SMTP AUTH username for the watchdog alert; empty for an unauthenticated relay. Env-only secret. */
    case WATCHDOG_ALERT_SMTP_USERNAME;

    /** @var string SMTP AUTH password for the watchdog alert; empty for an unauthenticated relay. Env-only secret. */
    case WATCHDOG_ALERT_SMTP_PASSWORD;

    /** @var string From address of the watchdog alert; empty means "not configured". */
    case WATCHDOG_ALERT_FROM_ADDRESS;

    /** @var string The single operator address the watchdog alert goes to; empty means "not configured". */
    case WATCHDOG_ALERT_TO_ADDRESS;

    /**
     * @var string Per-send timeout in milliseconds for the watchdog alert. Default 5000 —
     * shorter than MAIL_TIMEOUT_MS, because a dying process must not sit on a socket. Bounds
     * the send itself, not the name lookup that precedes the connect; the WatchdogAlertMailer
     * class docblock says what that leaves open and why it stays that way.
     */
    case WATCHDOG_ALERT_TIMEOUT_MS;

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

    // ── Telegram Gateway: one-time codes to a phone number (HIL-492) ─────────

    /**
     * @var string Telegram Gateway access token. Empty leaves the telegram code channel
     * unconfigured, and a project that registered it then reports every number unreachable
     * rather than failing - so a missing token costs the messenger, never the SMS beside it.
     * Env-only secret.
     */
    case TELEGRAM_GATEWAY_TOKEN;

    /**
     * @var string Telegram Gateway base URL. Default https://gatewayapi.telegram.org; the test
     * stand points it at its own mock, which is the only reason it is configurable at all.
     */
    case TELEGRAM_GATEWAY_ENDPOINT_URL;

    /**
     * @var string Sender username shown on the delivered code message; empty lets the Gateway
     * pick. Not a secret - the recipient reads it.
     */
    case TELEGRAM_GATEWAY_SENDER_USERNAME;

    /** @var string Per-request timeout in milliseconds for the Gateway calls. Default 5000. */
    case TELEGRAM_GATEWAY_TIMEOUT_MS;

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
     * @var string Seconds of elapsed time since the last rotation after which the per-node log
     * store owner rotates the live logs into the archive. Default 0 disables the age criterion;
     * 0 for both age and size preserves the start-only rotation behavior.
     * Serves as the default of the logs.rotation.max_age_seconds setting, which overrides it.
     */
    case LOG_ROTATION_MAX_AGE_SECONDS;

    /**
     * @var string Summed size in bytes of the live *.log files above which the per-node log store
     * owner rotates them into the archive. Default 0 disables the size criterion; 0 for both age
     * and size preserves the start-only rotation behavior.
     * Serves as the default of the logs.rotation.max_live_size_bytes setting, which overrides it.
     */
    case LOG_ROTATION_MAX_LIVE_SIZE_BYTES;

    /**
     * @var string Five-field cron expression (server timezone) on which the per-node log store
     * owner rotates the live logs — the planned-rotation axis alongside the age and size axes.
     * Empty disables the schedule axis; an unparseable expression is logged and also leaves it
     * disabled. A missed window is not caught up (the state is worker-process-local).
     * Serves as the default of the logs.rotation.cron setting, which overrides it.
     */
    case LOG_ROTATION_CRON;

    // ── Log archive retention (HIL-381) ──────────────────────────────────────

    /**
     * @var string How many of the newest archived rotation batches are always kept, exempt
     * from eviction regardless of age. Default 20; 0 disables the count criterion (with a
     * 0 max-age too, nothing is ever a candidate).
     * Serves as the default of the logs.archive_retention.keep_batches setting, which overrides it.
     */
    case LOG_ARCHIVE_RETENTION_KEEP_BATCHES;

    /**
     * @var string Age in seconds beyond which an archived rotation batch becomes an eviction
     * candidate, provided it is also outside the newest kept batches. Default 2592000 (30 days);
     * 0 disables the age criterion (with a 0 keep-batches too, nothing is ever a candidate).
     * Serves as the default of the logs.archive_retention.max_age_seconds setting, which overrides it.
     */
    case LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS;

    // ── Log takeout undo window (HIL-759) ────────────────────────────────────

    /**
     * @var string How long in seconds a batch an operator confirmed carrying off is still
     * protected from the pruner, counted from the confirmation. Default 86400 (a day); 0 means
     * the pruner does not wait at all and may remove the batch on its next pass. It promises
     * nothing about WITHDRAWING the confirmation: that stays possible for as long as the batch
     * directory is on the node, which is what the screen judges by.
     * Serves as the default of the logs.takeout.undo_window_seconds setting, which overrides it.
     */
    case LOG_TAKEOUT_UNDO_WINDOW_SECONDS;

    // ── Log write level (HIL-761) ────────────────────────────────────────────

    /**
     * @var string Name of the lowest level a process still writes to its log: DEBUG, INFO,
     * WARNING or ERROR, read as "write from this one and worse". Default INFO, which is what
     * an installation did before the threshold existed. An unrecognized name is treated as
     * INFO rather than refused - a process that cannot read its own level still has to log.
     * Serves as the default of the logs.write_level setting, which overrides it.
     */
    case LOG_WRITE_LEVEL;

    // ── Log index (HIL-754) ──────────────────────────────────────────────────

    /**
     * @var string Smallest interval in milliseconds between two log-index frames one node sends
     * the cluster aggregator. Default 5000, the step at which a node notices a change at all;
     * the minimum accepted is 100, and there is no value that turns the reporting off.
     * Serves as the default of the logs.index.push_interval_ms setting, which overrides it.
     */
    case LOG_INDEX_PUSH_INTERVAL_MS;
}
