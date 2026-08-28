<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\PageAccessGate;

/**
 * CliCommands - CLI command name constants.
 *
 * Defines all available CLI command names used throughout the framework.
 * Centralized command name management prevents typos and ensures consistency.
 */
final class CliCommands
{
    /** @var string Command: Show daemon status */
    public const string DAEMON_STATUS = 'daemon:status';

    /** @var string Command: Monitor daemon in real-time */
    public const string DAEMON_MONITOR = 'daemon:monitor';

    /** @var string Command: Ping the daemon over the command channel */
    public const string DAEMON_PING = 'daemon:ping';

    /** @var string Command: List the cluster nodes the daemon knows about */
    public const string CLUSTER_NODES = 'cluster:nodes';

    /** @var string Command: Reload cluster config and re-announce the local node */
    public const string CLUSTER_RELOAD = 'cluster:reload';

    /** @var string Command: Inspect the daemon's cluster/consensus/placement state (test-only) */
    public const string CLUSTER_TEST_INSPECT = 'test:cluster:inspect';

    /** @var string Command: Index an accept key as a browser attached to this node (test-only) */
    public const string CLUSTER_TEST_CLIENT_ATTACH = 'test:cluster:client:attach';

    /** @var string Command: Take an attached accept key back off this node (test-only) */
    public const string CLUSTER_TEST_CLIENT_DETACH = 'test:cluster:client:detach';

    /** @var string Command: Send a signal to one browser, wherever in the cluster it hangs (test-only) */
    public const string CLUSTER_TEST_CLIENT_SEND = 'test:cluster:client:send';

    /** @var string Command: Broadcast a signal to every browser of the cluster (test-only) */
    public const string CLUSTER_TEST_CLIENT_FANOUT = 'test:cluster:client:fanout';

    /** @var string Command: Announce a database row change to the other nodes (test-only) */
    public const string CLUSTER_TEST_DB_ANNOUNCE = 'test:cluster:db:announce';

    /** @var string Command: Ask the leader to place an agent, as addressing one does (test-only) */
    public const string CLUSTER_TEST_AGENT_PLACE = 'test:cluster:agent:place';

    /** @var string Command: Show help information */
    public const string HELP = 'help';

    /** @var string Command: Apply pending migrations */
    public const string MIGRATION_UP = 'db:migration:up';

    /** @var string Command: Rollback migrations */
    public const string MIGRATION_DOWN = 'db:migration:down';

    /** @var string Command: Show migration status */
    public const string MIGRATION_STATUS = 'db:migration:status';

    /** @var string Command: Retry failed migration */
    public const string MIGRATION_RETRY = 'db:migration:retry';

    /** @var string Command: Apply database seeds */
    public const string SEED_APPLY = 'db:seed:apply';

    /** @var string Command: Show database schema status */
    public const string DB_SCHEMA_STATUS = 'db:schema:status';

    /** @var string Command: Wait for MySQL to become ready */
    public const string DB_WAIT = 'db:wait';

    /** @var string Command: Reset test database (DROP, migrate, seed) */
    public const string DB_TEST_RESET = 'db:test:reset';

    /** @var string Command: Expire an active auth verification challenge (test-only) */
    public const string VERIFICATION_TEST_EXPIRE = 'verification:test:expire';

    /** @var string Command: Verify stored backup archives against their recorded checksums */
    public const string BACKUP_VERIFY = 'backup:verify';

    /** @var string Command: Restore a stored backup into the configured databases */
    public const string BACKUP_RESTORE = 'backup:restore';

    /** @var string Command: Age a stored backup's sidecar createdAt into the past (test-only) */
    public const string BACKUP_TEST_AGE = 'test:backup:age';

    /** @var string Command: Force a backup retention prune through the live agent (test-only) */
    public const string BACKUP_TEST_PRUNE = 'test:backup:prune';

    /** @var string Command: Force a backup shipping pass through the live agent (test-only) */
    public const string BACKUP_TEST_SHIP = 'test:backup:ship';

    /** @var string Command: Force a scheduled backup through the live agent (test-only) */
    public const string BACKUP_TEST_RUN_SCHEDULE = 'test:backup:run-schedule';

    /** @var string Command: Force-close a live WebSocket connection by acceptKey (test-only) */
    public const string CONNECTION_TEST_DROP = 'test:connection:drop';

    /** @var string Command: Resolve an LLM profile and optionally probe its endpoint */
    public const string LLM_PING = 'llm:ping';

    /** @var string Command: Bulk-seed N fixture users with password identities (test-only) */
    public const string USER_TEST_SEED = 'test:user:seed';

    /**
     * Emit one durable notification to a user through the live daemon (test-only).
     *
     * Doubles as the command-channel wire name routed to
     * {@see AbstractHilosIndexAgent}: unlike the backup pair, the CLI name and the
     * wire name are one string because there is exactly one route for it.
     *
     * @var string Command: Emit one notification to a user through the live daemon (test-only)
     */
    public const string NOTIFICATION_TEST_EMIT = 'test:notification:emit';

    /**
     * Forget every anti-abuse counter and stored block (test-only).
     *
     * Doubles as the command-channel wire name routed to {@see AuthThrottleAgent}, the same
     * one-string arrangement {@see self::NOTIFICATION_TEST_EMIT} uses: there is exactly one
     * route for it. It has to reach the agent rather than clear the table from the CLI
     * because the counters are the agent's runtime state, which no other process holds.
     *
     * @var string Command: Forget every anti-abuse counter and stored block (test-only)
     */
    public const string THROTTLE_TEST_RESET = 'test:throttle:reset';

    /**
     * Print this node's protected-mode snapshot as JSON (test-only).
     *
     * Answered synchronously by the master, so unlike its two siblings below it is
     * never routed to an agent: during a freeze every agent but the initiator is
     * stopped, so an agent-answered inspector would go silent in exactly the phase
     * it exists to report on.
     *
     * @var string Command: Print this node's protected-mode snapshot as JSON (test-only)
     */
    public const string PROTECTED_MODE_TEST_INSPECT = 'test:protected-mode:inspect';

    /**
     * Enter protected mode through the live initiator agent (test-only).
     *
     * Doubles as the command-channel wire name routed to the agents that declare it
     * ({@see AbstractHilosIndexAgent} and the cluster demo's worker agent), the same
     * one-string arrangement {@see self::NOTIFICATION_TEST_EMIT} uses. The freeze has
     * exactly one entry and this command does not add a second: it asks an agent to
     * call the same request every production initiator calls.
     *
     * @var string Command: Enter protected mode through the live initiator agent (test-only)
     */
    public const string PROTECTED_MODE_TEST_ENTER = 'test:protected-mode:enter';

    /**
     * End the operation and open the verification window, through the initiator agent (test-only).
     *
     * Named `leave` because it is what a test calls when the operation it froze the node for is
     * over - the same moment production reaches. It no longer opens the system: that step is
     * {@see self::PROTECTED_MODE_TEST_OPEN}, exactly as it is a separate operator command in
     * production.
     *
     * @var string Command: End the operation and open the verification window (test-only)
     */
    public const string PROTECTED_MODE_TEST_LEAVE = 'test:protected-mode:leave';

    /**
     * Open the system to everyone from the verification window, through the initiator (test-only).
     *
     * The test path's own explicit open. It cannot be {@see self::PROTECTED_MODE_OPEN}: a command
     * routes to exactly one agent type per project, that one belongs to the agent that initiates
     * a real operation, and the freeze may only be driven by the agent the row records as its
     * initiator - which on the test path is this driver's carrier.
     *
     * @var string Command: Open the system to everyone from the verification window (test-only)
     */
    public const string PROTECTED_MODE_TEST_OPEN = 'test:protected-mode:open';

    /**
     * Mint one pass into the verification window and print it, through the initiator (test-only).
     *
     * The test path's own mint, and it has to exist: the operator's {@see self::PROTECTED_MODE_PASS}
     * belongs to the agent that runs real operations, and a freeze may only be driven by the agent
     * the row records as its initiator - which on the test path is this driver's carrier. Both names
     * are answered by the same handler and mint through the same request, so nothing about what a
     * pass is differs between the two.
     *
     * @var string Command: Mint one pass into the verification window and print it (test-only)
     */
    public const string PROTECTED_MODE_TEST_PASS = 'test:protected-mode:pass';

    /**
     * Mint one pass into the verification window and print it (operator).
     *
     * Not test-only: the three commands below are how a human ends a real destructive operation,
     * and they run on production. Answered by the initiator agent, which is the only party the
     * freeze row authorizes to drive it.
     *
     * @var string Command: Mint one pass into the verification window and print it
     */
    public const string PROTECTED_MODE_PASS = 'protected-mode:pass';

    /** @var string Command: Open the system to everyone, ending the verification window */
    public const string PROTECTED_MODE_OPEN = 'protected-mode:open';

    /** @var string Command: Close the system again from the verification window, voiding every pass */
    public const string PROTECTED_MODE_CLOSE = 'protected-mode:close';

    /**
     * Grant a user the admin flag that opens the Hilos admin pages (operator).
     *
     * Not test-only: an installation with no admin has no way into `/hilos/*` at all
     * ({@see PageAccessGate} closes those pages until a project answers
     * {@see BrowserContext::isAdmin}), so this is how the first one is made. Doubles as
     * the command-channel wire name routed to {@see AbstractHilosIndexAgent}, the same
     * one-string arrangement {@see self::NOTIFICATION_TEST_EMIT} uses: there is exactly
     * one route for it. It reaches an agent rather than writing the row from the CLI
     * because the grant also has to tell the user's live connections, and only a worker
     * holds them.
     *
     * @var string Command: Grant a user the Hilos admin flag
     */
    public const string ADMIN_GRANT = 'admin:grant';

    /** @var string Command: Take the Hilos admin flag away from a user */
    public const string ADMIN_REVOKE = 'admin:revoke';

    /**
     * Make one browser session an administrator, minting its user when it has none (operator).
     *
     * The command {@see self::ADMIN_GRANT} needs and does not have: it flags a user row that
     * already exists, and a fresh installation has no way to make the first one. This names a
     * SESSION by its cookie token instead, so the operator can point at the browser in front
     * of him rather than at an id he has no admin surface to look up.
     *
     * Routed to the sessions library - {@see AbstractSessionsLibraryAgent} -
     * because the operation ends in a session bind, and the session's runtime connections and
     * handshake payload belong there. A project mounts it by naming it in that agent's
     * AGENT_COMMANDS; one that does not simply never answers it.
     *
     * @var string Command: Make a browser session an administrator
     */
    public const string ADMIN_CREATE = 'admin:create';
}
