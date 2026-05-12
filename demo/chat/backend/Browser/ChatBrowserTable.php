<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

/**
 * Browser-only table keys used by chat page configs.
 */
final class ChatBrowserTable
{
    public const string MAIN_EVENTS = 'mainEvents';
    public const string MAIN_USERS = 'mainUsers';
    public const string MAIN_BOTS = 'mainBots';
    public const string SELF_CONNECTION = 'selfConnection';
    public const string ATTACHMENT_DRAFTS = 'attachmentDrafts';
    public const string BOT_DETAIL = 'botDetail';
    public const string USER_DETAIL = 'userDetail';
}
