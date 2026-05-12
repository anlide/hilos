<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Browser\Table\AttachmentDraftsBrowserTable;
use Demo\Chat\Browser\Table\BotDetailBrowserTable;
use Demo\Chat\Browser\Table\MainBotsBrowserTable;
use Demo\Chat\Browser\Table\MainEventsBrowserTable;
use Demo\Chat\Browser\Table\MainUsersBrowserTable;
use Demo\Chat\Browser\Table\SelfConnectionBrowserTable;
use Demo\Chat\Browser\Table\UserDetailBrowserTable;
use Hilos\Core\Browser\Context\BrowserContext;

/**
 * Chat demo browser-facing context.
 */
final class ChatBrowserContext extends BrowserContext
{
    public const array TABLES = [
        MainEventsBrowserTable::TABLE => MainEventsBrowserTable::BROWSER,
        MainUsersBrowserTable::TABLE => MainUsersBrowserTable::BROWSER,
        MainBotsBrowserTable::TABLE => MainBotsBrowserTable::BROWSER,
        SelfConnectionBrowserTable::TABLE => SelfConnectionBrowserTable::BROWSER,
        AttachmentDraftsBrowserTable::TABLE => AttachmentDraftsBrowserTable::BROWSER,
        BotDetailBrowserTable::TABLE => BotDetailBrowserTable::BROWSER,
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::BROWSER,
    ];

    /**
     * Registers chat browser-facing state helpers.
     */
    public function configure(): void
    {
    }
}
