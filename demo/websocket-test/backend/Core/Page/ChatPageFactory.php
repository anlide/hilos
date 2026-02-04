<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Page;

use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\DTO\Action\FileActionDTO;
use Demo\WebSocketTest\DTO\Action\MessageActionDTO;
use Demo\WebSocketTest\DTO\Action\RenameActionDTO;
use Demo\WebSocketTest\Pages\AdminBotsPage;
use Demo\WebSocketTest\Pages\AdminModeratorPage;
use Demo\WebSocketTest\Pages\AdminPage;
use Demo\WebSocketTest\Pages\AdminUsersPage;
use Demo\WebSocketTest\Pages\BotPage;
use Demo\WebSocketTest\Pages\MainPage;
use Demo\WebSocketTest\Pages\ModeratorPage;
use Demo\WebSocketTest\Pages\ProfilePage;
use Demo\WebSocketTest\Pages\UserPage;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\Exception\Page\PageNotFoundException;

/**
 * ChatPageFactory - Factory for creating chat page instances
 *
 * Creates and manages chat page instances.
 *
 * @extends AbstractPageFactory<ChatPageAgentInterface>
 */
class ChatPageFactory extends AbstractPageFactory
{
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
            PageConstants::MAIN => new MainPage($this->signalRouter, $this->agent),
            PageConstants::PROFILE => new ProfilePage($this->signalRouter, $this->agent),
            PageConstants::USER => new UserPage($this->signalRouter, $this->agent),
            PageConstants::BOT => new BotPage($this->signalRouter, $this->agent),
            PageConstants::MODERATOR => new ModeratorPage($this->signalRouter, $this->agent),
            PageConstants::ADMIN => new AdminPage($this->signalRouter, $this->agent),
            PageConstants::ADMIN_USERS => new AdminUsersPage($this->signalRouter, $this->agent),
            PageConstants::ADMIN_MODERATOR => new AdminModeratorPage($this->signalRouter, $this->agent),
            PageConstants::ADMIN_BOTS => new AdminBotsPage($this->signalRouter, $this->agent),
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

    /**
     * Create ActionPayloadDTO for chat actions
     *
     * @param string $action Action name
     * @param array $data Payload data
     * @return ActionPayloadDTO Action payload DTO
     */
    public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
    {
        return match ($action) {
            ChatSignalConstants::MESSAGE => MessageActionDTO::fromArray($data),
            ChatSignalConstants::RENAME => RenameActionDTO::fromArray($data),
            ChatSignalConstants::FILE => FileActionDTO::fromArray($data),
            default => parent::createActionPayloadDTO($action, $data),
        };
    }
}
