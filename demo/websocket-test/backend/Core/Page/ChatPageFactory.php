<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Page;

use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Page\Pages\AdminBotsPage;
use Demo\WebSocketTest\Core\Page\Pages\AdminModeratorPage;
use Demo\WebSocketTest\Core\Page\Pages\AdminPage;
use Demo\WebSocketTest\Core\Page\Pages\AdminUsersPage;
use Demo\WebSocketTest\Core\Page\Pages\BotPage;
use Demo\WebSocketTest\Core\Page\Pages\MainPage;
use Demo\WebSocketTest\Core\Page\Pages\ModeratorPage;
use Demo\WebSocketTest\Core\Page\Pages\ProfilePage;
use Demo\WebSocketTest\Core\Page\Pages\UserPage;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\Page\PageNotFoundException;

/**
 * ChatPageFactory - Factory for creating chat page instances
 *
 * Creates and manages chat page instances.
 */
class ChatPageFactory extends AbstractPageFactory
{
    /** @var ChatPageContextInterface Chat agent context */
    private ChatPageContextInterface $chatContext;

    /**
     * Constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     * @param ChatPageContextInterface $chatContext Chat agent context
     */
    public function __construct(SignalRouter $signalRouter, ChatPageContextInterface $chatContext)
    {
        parent::__construct($signalRouter);
        $this->chatContext = $chatContext;
    }

    /**
     * Create page instance
     *
     * @param string $pageName Page name/identifier
     * @return AbstractPage Page instance
     * @throws PageNotFoundException If page cannot be created
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            PageConstants::MAIN => new MainPage($this->signalRouter, $this->chatContext),
            PageConstants::PROFILE => new ProfilePage($this->signalRouter, $this->chatContext),
            PageConstants::USER => new UserPage($this->signalRouter, $this->chatContext),
            PageConstants::BOT => new BotPage($this->signalRouter, $this->chatContext),
            PageConstants::MODERATOR => new ModeratorPage($this->signalRouter, $this->chatContext),
            PageConstants::ADMIN => new AdminPage($this->signalRouter, $this->chatContext),
            PageConstants::ADMIN_USERS => new AdminUsersPage($this->signalRouter, $this->chatContext),
            PageConstants::ADMIN_MODERATOR => new AdminModeratorPage($this->signalRouter, $this->chatContext),
            PageConstants::ADMIN_BOTS => new AdminBotsPage($this->signalRouter, $this->chatContext),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * Check if page exists
     *
     * @param string $pageName Page name/identifier
     * @return bool True if page can be created
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [
            PageConstants::MAIN,
            PageConstants::PROFILE,
            PageConstants::USER,
            PageConstants::BOT,
            PageConstants::MODERATOR,
            PageConstants::ADMIN,
            PageConstants::ADMIN_USERS,
            PageConstants::ADMIN_MODERATOR,
            PageConstants::ADMIN_BOTS,
        ], true);
    }
}
