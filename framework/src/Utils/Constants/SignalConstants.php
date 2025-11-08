<?php

declare(strict_types=1);

namespace Hilos\Utils\Constants;

/**
 * SignalConstants - Signal type constants
 *
 * Defines standard signal type names used throughout the framework.
 */
class SignalConstants
{
    // WebSocket signal types
    public const string SIGNAL_HANDSHAKE = 'handshake';
    public const string SIGNAL_FRAME = 'frame';
    public const string SIGNAL_FRAME_BINARY = 'frame_binary';
    public const string SIGNAL_CLOSE = 'close';

    // User subscription signal types
    public const string SIGNAL_ACTION = 'action';

    // Page subscription signal types
    public const string SIGNAL_SUBSCRIBE_PAGE = 'subscribe_page';
    public const string SIGNAL_UNSUBSCRIBE_PAGE = 'unsubscribe_page';
    public const string SIGNAL_UPDATE_SUBSCRIPTION_PAGE = 'update_subscription_page';

    // Group subscription signal types
    public const string SIGNAL_SUBSCRIBE_GROUP = 'subscribe_group';
    public const string SIGNAL_UNSUBSCRIBE_GROUP = 'unsubscribe_group';
    public const string SIGNAL_UPDATE_SUBSCRIPTION_GROUP = 'update_subscription_group';
}
