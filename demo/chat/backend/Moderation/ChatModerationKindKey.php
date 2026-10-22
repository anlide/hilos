<?php

declare(strict_types=1);

namespace Demo\Chat\Moderation;

/**
 * Chat-owned moderation kind keys (HIL-1623).
 */
final class ChatModerationKindKey
{
    /** The text of a chat message. */
    public const string MESSAGE = 'chat_message';

    /** One picture attached to a chat message. */
    public const string MESSAGE_PICTURE = 'chat_message_picture';
}
