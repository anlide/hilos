<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Core\Page\PageAccessReassessment;

/**
 * SignalTypeConstants - Signal type constants.
 *
 * Defines standard signal type names used throughout the framework.
 */
final class SignalTypeConstants
{
    /** @var string System signal type */
    public const string SYSTEM = 'system';

    /** @var string WebSocket handshake signal type */
    public const string HANDSHAKE = 'handshake';

    /** @var string Connection close signal type */
    public const string CONNECTION_CLOSE = 'connection_close';

    /** @var string Page subscribe signal type */
    public const string PAGE_SUBSCRIBE = 'page_subscribe';

    /**
     * Page access re-decision signal type (server-internal, never reaches a browser).
     *
     * Carries a live subscription back through the subscribe verdict after the acting
     * user's rights changed, so an open page stops showing what the person may no longer
     * see - or starts showing what they now may. It is deliberately NOT a page_subscribe:
     * the subscription itself does not change, only the answer to it
     * ({@see PageAccessReassessment}).
     *
     * @var string
     */
    public const string PAGE_ACCESS_REASSESS = 'page_access_reassess';

    /**
     * Access re-decision announcement (server-internal, never reaches a browser).
     *
     * Names one person whose rights just changed and nothing else. The worker that wrote
     * the rights announces it once; the master writes it to every worker of this node; each
     * worker sweeps its own subscription mirror and turns the matches into PAGE_ACCESS_REASSESS
     * frames ({@see PageAccessReassessment}). It exists because identity is resolved in a
     * worker while the pages of one person are spread across all of them.
     *
     * @var string
     */
    public const string PAGE_ACCESS_REASSESS_USER = 'page_access_reassess_user';

    /**
     * Access re-decision announcement by connection (server-internal, never reaches a browser).
     *
     * Names the accept keys whose pages are to be re-judged, and nothing else. It stands
     * beside {@see self::PAGE_ACCESS_REASSESS_USER} rather than generalizing it because the
     * two ask different questions - "the pages of this person" and "the pages of these
     * connections" - and a receiving worker would otherwise have to establish which of the
     * two it is holding before it could act.
     *
     * This is the criterion a DOWNGRADE needs (HIL-652): signing out removes the very
     * identity the user criterion matches on, so "the pages of user N" announced after the
     * runtime write matches nothing, and announced before it judges with an identity that is
     * about to be destroyed. An accept key survives the write untouched.
     *
     * @var string
     */
    public const string PAGE_ACCESS_REASSESS_CONNECTIONS = 'page_access_reassess_connections';

    /** @var string Page unsubscribe signal type */
    public const string PAGE_UNSUBSCRIBE = 'page_unsubscribe';

    /** @var string Page update subscription signal type */
    public const string PAGE_UPDATE_SUBSCRIPTION = 'page_update_subscription';

    /** @var string Page response signal type (server-to-client page scope payload) */
    public const string PAGE_RESPONSE = 'page_response';

    /** @var string Table viewport signal type (client-to-server window descriptor for one table) */
    public const string TABLE_VIEWPORT = 'table_viewport';

    /** @var string Table window signal type (server-to-client window snapshot for one table) */
    public const string TABLE_WINDOW = 'table_window';

    /** @var string Table viewport delta signal type (server-to-client live pending change for one table) */
    public const string TABLE_VIEWPORT_DELTA = 'table_viewport_delta';

    /** @var string Table viewport count signal type (server-to-client live page/total count update for one table) */
    public const string TABLE_VIEWPORT_COUNT = 'table_viewport_count';

    /** @var string Table viewport append signal type (server-to-client live tail append for one table) */
    public const string TABLE_VIEWPORT_APPEND = 'table_viewport_append';

    /** @var string Table viewport own-create signal type (server-to-client live placed insert of the author's own row) */
    public const string TABLE_VIEWPORT_OWN_CREATE = 'table_viewport_own_create';

    /** @var string Group subscribe signal type */
    public const string GROUP_SUBSCRIBE = 'group_subscribe';

    /** @var string Group unsubscribe signal type */
    public const string GROUP_UNSUBSCRIBE = 'group_unsubscribe';

    /** @var string Group update subscription signal type */
    public const string GROUP_UPDATE_SUBSCRIPTION = 'group_update_subscription';

    /** @var string Group response signal type (server-to-client answer to a group join) */
    public const string GROUP_RESPONSE = 'group_response';

    /** @var string Group join signal type (worker -> own daemon: record this membership) */
    public const string GROUP_JOIN = 'group_join';

    /** @var string User action signal type */
    public const string ACTION = 'action';

    /** @var string CLI command request signal type (master -> agent) */
    public const string COMMAND_REQUEST = 'command_request';

    /** @var string CLI command reply signal type (agent -> master, addressed to the held CLI connection) */
    public const string COMMAND_REPLY = 'command_reply';

    /** @var string Binary frame signal type */
    public const string FRAME_BINARY = 'frame_binary';

    /** @var string Cron signal type */
    public const string CRON = 'cron';

    /** @var string Agent-to-agent signal type */
    public const string AGENT_SIGNAL = 'agent_signal';

    /** @var string WebSocket signal to single user */
    public const string WS_USER = 'ws_user';

    /** @var string WebSocket signal to every connection of one browser session */
    public const string WS_SESSION = 'ws_session';

    /** @var string WebSocket signal to all page-subscribed users */
    public const string WS_ALL = 'ws_all';

    /** @var string WebSocket signal to every connected client (broadcast) */
    public const string WS_ALL_CONNECTED = 'ws_all_connected';

    /** @var string WebSocket signal to group */
    public const string WS_GROUP = 'ws_group';

    /** @var string DB sync created signal type */
    public const string DB_SYNC_CREATED = 'db_sync_created';

    /** @var string DB sync updated signal type */
    public const string DB_SYNC_UPDATED = 'db_sync_updated';

    /** @var string DB sync deleted signal type */
    public const string DB_SYNC_DELETED = 'db_sync_deleted';

    /** @var string DB sync cleared signal type (whole collection truncated) */
    public const string DB_SYNC_CLEARED = 'db_sync_cleared';

    /** @var string DB re-hydrate signal type (whole DB replaced under a live daemon, e.g. restore) */
    public const string DB_REHYDRATE = 'db_rehydrate';

    /** @var string RT sync created signal type */
    public const string RT_SYNC_CREATED = 'rt_sync_created';

    /** @var string RT sync updated signal type */
    public const string RT_SYNC_UPDATED = 'rt_sync_updated';

    /** @var string RT sync deleted signal type */
    public const string RT_SYNC_DELETED = 'rt_sync_deleted';

    /** @var string Protected-mode enable request signal type (worker -> own daemon, drained worker-locally) */
    public const string PROTECTED_MODE_ENABLE = 'protected_mode_enable';

    /** @var string Protected-mode disable request signal type (worker -> own daemon, drained worker-locally) */
    public const string PROTECTED_MODE_DISABLE = 'protected_mode_disable';

    /** @var string Protected-mode verify request signal type (worker -> own daemon, drained worker-locally) */
    public const string PROTECTED_MODE_VERIFY = 'protected_mode_verify';

    /** @var string Protected-mode progress mark (worker -> own daemon, drained worker-locally) */
    public const string PROTECTED_MODE_PROGRESS = 'protected_mode_progress';

    /** @var string Protected-mode pass request signal type (worker -> own daemon, drained worker-locally) */
    public const string PROTECTED_MODE_PASS = 'protected_mode_pass';

    /** @var string Protected-mode refreeze request signal type (worker -> own daemon, drained worker-locally) */
    public const string PROTECTED_MODE_REFREEZE = 'protected_mode_refreeze';

    /** @var string Protected-mode state frame type (daemon master -> every connected browser client) */
    public const string PROTECTED_MODE = 'protected_mode';

    /**
     * @var string Restored logins are owed on this node (restore worker -> own daemon, drained worker-locally)
     *
     * The two frames below are what the lift waits on: a restore leaves the logins it photographed
     * in a file the frozen sessions library will pick up, and the master must not tell the browsers
     * to reload until they are back in the database. This one says the debt exists and is sent only
     * where it was taken on, so a node that ran no restore never waits (HIL-771).
     */
    public const string SESSION_CARRY_OVER_DEFERRED = 'session_carry_over_deferred';

    /** @var string The owed logins are back in the database (sessions library worker -> own daemon) */
    public const string SESSION_CARRY_OVER_DONE = 'session_carry_over_done';

    /**
     * @var string Frozen-replica frame type (worker -> the one browser client whose page reads it).
     *
     * Addressed and not broadcast, unlike the freeze above: what it answers is whether anything
     * the connection's own page reads has stopped being kept up to date (HIL-711), and a node
     * where something unrelated froze has nothing to tell this reader.
     */
    public const string RT_STALENESS = 'rt_staleness';
}
