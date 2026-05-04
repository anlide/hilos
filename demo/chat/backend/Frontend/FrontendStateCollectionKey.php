<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

/**
 * Stable frontend state collection keys for the chat demo.
 */
final class FrontendStateCollectionKey
{
    public const string USERS = 'users';
    public const string USER_PRESENCE = 'userPresence';
    public const string USER_CONNECTION_STATS = 'userConnectionStats';
    public const string BOT_PRESENCE = 'botPresence';
    public const string ATTACHMENT_DRAFTS = 'attachmentDrafts';
    public const string SELF_CONNECTION = 'selfConnection';
}
