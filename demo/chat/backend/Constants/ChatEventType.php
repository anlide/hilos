<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatEventType - Chat event type enum
 *
 * Defines all possible event types that can occur in the chat system.
 */
enum ChatEventType: string
{
    /** Chat was started */
    case CHAT_STARTED = 'chat_started';

    /** Chat was stopped */
    case CHAT_STOPPED = 'chat_stopped';

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

    /** User shared a file */
    case FILE_SHARED = 'file_shared';
}
