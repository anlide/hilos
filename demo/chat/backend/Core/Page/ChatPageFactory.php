<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\DTO\FileActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Page\DTO\RenameActionDTO;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminModeratorPage;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Pages\ProfilePage;
use Demo\Chat\Pages\UserPage;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Demo\Chat\Tables\DTO\TableRefreshActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceDeleteActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Demo\Chat\Tables\User\DTO\UserUpdateActionDTO;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ChatPageFactory - Factory for creating chat page instances
 *
 * Creates and manages chat page instances.
 * Extends HilosPageFactory to inherit framework-level Hilos admin pages.
 *
 * @extends HilosPageFactory<PageAgentInterface>
 */
class ChatPageFactory extends HilosPageFactory
{
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            PageConstants::MAIN => new MainPage($this->agent),
            PageConstants::PROFILE => new ProfilePage($this->agent),
            PageConstants::USER => new UserPage($this->agent),
            PageConstants::BOT => new BotPage($this->agent),
            PageConstants::MODERATOR => new ModeratorPage($this->agent),
            PageConstants::ADMIN => new AdminPage($this->agent),
            PageConstants::ADMIN_USERS => new AdminUsersPage($this->agent),
            PageConstants::ADMIN_MODERATOR => new AdminModeratorPage($this->agent),
            PageConstants::ADMIN_BOTS => new AdminBotsPage($this->agent),
            default => parent::createPage($pageName),
        };
    }

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
        ], true) || parent::hasPage($pageName);
    }

    public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
    {
        return match ($action) {
            ChatSignalConstants::MESSAGE => MessageActionDTO::fromArray($data),
            ChatSignalConstants::RENAME => RenameActionDTO::fromArray($data),
            ChatSignalConstants::FILE => FileActionDTO::fromArray($data),
            ChatSignalConstants::TABLE_REFRESH => TableRefreshActionDTO::fromArray($data),
            ChatSignalConstants::USER_UPDATE => UserUpdateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_CREATE => BotCreateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_UPDATE => BotUpdateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_DELETE => BotDeleteActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_CREATE => ModeratorPieceCreateActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_UPDATE => ModeratorPieceUpdateActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_DELETE => ModeratorPieceDeleteActionDTO::fromArray($data),
            default => parent::createActionPayloadDTO($action, $data),
        };
    }
}
