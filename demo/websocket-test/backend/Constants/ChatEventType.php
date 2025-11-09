<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Constants;

/**
 * ChatEventType - Chat event type enum
 *
 * Defines all possible event types that can occur in the chat system.
 */
enum ChatEventType: string
{
    /** Chat was created/started */
    case CHAT_CREATED = 'chat_created';

    /** Chat history was cleared */
    case CHAT_CLEARED = 'chat_cleared';

    /** User joined the chat */
    case USER_JOINED = 'user_joined';

    /** User left the chat */
    case USER_LEFT = 'user_left';

    /** User renamed themselves */
    case USER_RENAMED = 'user_renamed';

    /** User sent a message */
    case MESSAGE_SENT = 'message_sent';
}
