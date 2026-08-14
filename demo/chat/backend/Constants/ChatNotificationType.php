<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatNotificationType - the notification types the chat demo raises from its own domain (HIL-557).
 *
 * Names only: the demo deliberately declares no notification-type registry, because a
 * descriptor carries nothing but the mandatory flag today and an unregistered type is
 * already non-mandatory - registering these would be code without behavior. All three
 * stay non-mandatory on purpose: none of them is a security notification, so the
 * channel preferences (HIL-485) are entitled to mute them.
 */
final class ChatNotificationType
{
    /** Someone named the recipient in a published feed message. */
    public const string MENTION = 'chat.message.mention';

    /** Moderation rejected the recipient's message, so it never reached the feed. */
    public const string MESSAGE_REJECTED = 'chat.moderation.message_rejected';

    /** Moderation rejected the display name the recipient asked for. */
    public const string RENAME_REJECTED = 'chat.moderation.rename_rejected';
}
