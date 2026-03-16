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
use Demo\Chat\Pages\Hilos\AnalyticsPage;
use Demo\Chat\Pages\Hilos\DashboardPage;
use Demo\Chat\Pages\Hilos\GuardianPage;
use Demo\Chat\Pages\Hilos\I18nPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
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
use Demo\Chat\Tables\Settings\DTO\SettingAddActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingDeleteActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingUpdateActionDTO;
use Demo\Chat\Tables\User\DTO\UserUpdateActionDTO;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ChatPageFactory - Factory for creating chat page instances.
 *
 * Creates and manages chat page instances.
 * Extends HilosPageFactory to inherit framework-level Hilos admin pages.
 *
 * @extends HilosPageFactory<PageAgentInterface>
 */
final class ChatPageFactory extends HilosPageFactory
{
    /**
     * Create page instance by name.
     *
     * @param string $pageName Page constant (e.g. PageConstants::MAIN)
     * @return AbstractPage Page instance
     * @throws PageNotFoundException When page cannot be created
     */
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
            HilosPageConstants::HILOS_DASHBOARD => new DashboardPage($this->agent),
            HilosPageConstants::HILOS_SETTINGS => new SettingsPage($this->agent),
            HilosPageConstants::HILOS_I18N => new I18nPage($this->agent),
            HilosPageConstants::HILOS_GUARDIAN => new GuardianPage($this->agent),
            HilosPageConstants::HILOS_ANALYTICS => new AnalyticsPage($this->agent),
            default => parent::createPage($pageName),
        };
    }

    /**
     * Check if page name is supported.
     *
     * @param string $pageName Page name constant
     * @return bool True if page exists
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
        ], true) || parent::hasPage($pageName);
    }

    /**
     * Creates action payload DTO for the given action and data.
     *
     * @param string $action Action name (e.g. ChatSignalConstants::MESSAGE)
     * @param array<string, mixed> $data Action payload data
     * @return ActionPayloadDTO DTO instance
     */
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
            ChatSignalConstants::SETTING_ADD => SettingAddActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_UPDATE => SettingUpdateActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_DELETE => SettingDeleteActionDTO::fromArray($data),
            default => parent::createActionPayloadDTO($action, $data),
        };
    }
}
