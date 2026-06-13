<?php

declare(strict_types=1);

namespace Hilos\Constants;

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

    /** @var string Page unsubscribe signal type */
    public const string PAGE_UNSUBSCRIBE = 'page_unsubscribe';

    /** @var string Page update subscription signal type */
    public const string PAGE_UPDATE_SUBSCRIPTION = 'page_update_subscription';

    /** @var string Page response signal type (server-to-client page scope payload) */
    public const string PAGE_RESPONSE = 'page_response';

    /** @var string Group subscribe signal type */
    public const string GROUP_SUBSCRIBE = 'group_subscribe';

    /** @var string Group unsubscribe signal type */
    public const string GROUP_UNSUBSCRIBE = 'group_unsubscribe';

    /** @var string Group update subscription signal type */
    public const string GROUP_UPDATE_SUBSCRIPTION = 'group_update_subscription';

    /** @var string User action signal type */
    public const string ACTION = 'action';

    /** @var string Binary frame signal type */
    public const string FRAME_BINARY = 'frame_binary';

    /** @var string Cron signal type */
    public const string CRON = 'cron';

    /** @var string Agent-to-agent signal type */
    public const string AGENT_SIGNAL = 'agent_signal';

    /** @var string WebSocket signal to single user */
    public const string WS_USER = 'ws_user';

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

    /** @var string RT sync created signal type */
    public const string RT_SYNC_CREATED = 'rt_sync_created';

    /** @var string RT sync updated signal type */
    public const string RT_SYNC_UPDATED = 'rt_sync_updated';

    /** @var string RT sync deleted signal type */
    public const string RT_SYNC_DELETED = 'rt_sync_deleted';
}
