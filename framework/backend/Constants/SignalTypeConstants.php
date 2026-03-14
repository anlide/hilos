<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SignalTypeConstants - Signal type constants.
 *
 * Defines standard signal type names used throughout the framework.
 */
class SignalTypeConstants
{
    // System signal types
    public const string SYSTEM = 'system';

    // Page subscription signal types
    public const string HANDSHAKE = 'handshake';
    public const string CONNECTION_CLOSE = 'connection_close';
    public const string PAGE_SUBSCRIBE = 'page_subscribe';
    public const string PAGE_UNSUBSCRIBE = 'page_unsubscribe';
    public const string PAGE_UPDATE_SUBSCRIPTION = 'page_update_subscription';

    // Group subscription signal types
    public const string GROUP_SUBSCRIBE = 'group_subscribe';
    public const string GROUP_UNSUBSCRIBE = 'group_unsubscribe';
    public const string GROUP_UPDATE_SUBSCRIPTION = 'group_update_subscription';

    // User actions signal types
    public const string ACTION = 'action';
    public const string FRAME_BINARY = 'frame_binary';

    // Cron actions signal types
    public const string CRON = 'cron';

    // Agent-to-agent signal type
    public const string AGENT_SIGNAL = 'agent_signal';

    // WebSocket response signal types (for delivery to users)
    public const string WS_USER = 'ws_user';
    public const string WS_ALL = 'ws_all';
    public const string WS_GROUP = 'ws_group';

    // Data sync signal types (DB and RT state propagation to workers/daemons)
    public const string DB_SYNC_CREATED = 'db_sync_created';
    public const string DB_SYNC_UPDATED = 'db_sync_updated';
    public const string DB_SYNC_DELETED = 'db_sync_deleted';
    public const string RT_SYNC_CREATED = 'rt_sync_created';
    public const string RT_SYNC_UPDATED = 'rt_sync_updated';
    public const string RT_SYNC_DELETED = 'rt_sync_deleted';
}
