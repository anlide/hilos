<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Database\Context\HilosDbContext;

/**
 * DB and RT sources used by chat browser configs.
 */
final class ChatBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::users,
    ];

    public const array DB_EVENTS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::events,
    ];

    public const array DB_EVENT_MESSAGES = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::eventMessages,
    ];

    public const array DB_EVENT_USER_REGISTRATIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::eventUserRegistrations,
    ];

    public const array DB_EVENT_USER_RENAMES = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::eventUserRenames,
    ];

    public const array DB_EVENT_ATTACHMENTS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::eventAttachments,
    ];

    public const array DB_BOTS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::bots,
    ];

    public const array DB_MODERATOR_PROMPT_PIECES = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ChatDbContext::moderatorPromptPieces,
    ];

    public const array DB_SETTINGS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::settings,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => ChatRtContext::connections,
    ];

    public const array RT_USER_STATES = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => ChatRtContext::userStates,
    ];

    public const array RT_ATTACHMENT_DRAFTS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => ChatRtContext::attachmentDrafts,
    ];

    public const array RT_BOT_AGENT_STATUSES = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => ChatRtContext::botAgentStatuses,
    ];

    public const array RT_GUARDIAN_AGENT_STATUSES = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => ChatRtContext::guardianAgentStatuses,
    ];
}
