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

    /** User registered in chat */
    case USER_REGISTERED = 'user_registered';

    /** User became online (first active connection) */
    case USER_ONLINE = 'user_online';

    /** User became offline (no active connections left) */
    case USER_OFFLINE = 'user_offline';

    /** User renamed themselves */
    case USER_RENAMED = 'user_renamed';

    /** User renamed by admin */
    case USER_RENAMED_BY_ADMIN = 'user_renamed_by_admin';

    /** User sent a message */
    case MESSAGE_SENT = 'message_sent';

    /** User shared a file */
    case FILE_SHARED = 'file_shared';

    /** System guardian report injected into event stream */
    case GUARDIAN_REPORTED = 'guardian_reported';
}
