<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

/**
 * Browser-only table keys used by chat page configs.
 */
final class ChatBrowserTable
{
    public const string SELF_CONNECTION = 'selfConnection';
    public const string BOT_STATUS = 'botStatus';
    public const string USER_DETAIL = 'userDetail';
    public const string USER_PRESENCE = 'userPresence';
    public const string GUARDIAN_AGENT_STATUSES = 'guardianAgentStatuses';
    public const string GUARDIAN_AGENT_STATUS_DETAIL = 'guardianAgentStatusDetail';
}
