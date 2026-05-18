<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStartActionDTO;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStopActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Page\DTO\RenameActionDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Demo\Chat\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceDeleteActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingAddActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingDeleteActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingUpdateActionDTO;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ChatPageFactory - Factory for creating chat page instances.
 *
 * Creates and manages page instances from the project topology registry.
 * Extends HilosPageFactory for framework-level fallback behavior.
 */
final class ChatPageFactory extends HilosPageFactory
{
    /**
     * Create a page instance from the project topology registry.
     *
     * @param string $pageName Page identifier
     * @return AbstractPage Page instance
     * @throws PageNotFoundException When page cannot be created
     */
    protected function createPage(string $pageName): AbstractPage
    {
        $pageClass = Hilos::PAGES[$pageName] ?? null;
        if ($pageClass === null) {
            return parent::createPage($pageName);
        }

        return new $pageClass($this->agent);
    }

    /**
     * Check if the page is registered locally or supported by the framework fallback.
     *
     * @param string $pageName Page name constant
     * @return bool True if page exists
     */
    public function hasPage(string $pageName): bool
    {
        return isset(Hilos::PAGES[$pageName]) || parent::hasPage($pageName);
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
            ChatSignalConstants::FILE_UPLOAD_INIT => FileUploadInitActionDTO::fromArray($data),
            ChatSignalConstants::ATTACHMENT_DRAFT_DELETE => AttachmentDraftDeleteActionDTO::fromArray($data),
            ChatSignalConstants::USER_UPDATE => AdminUserUpdateActionDTO::fromArray($data),
            ChatSignalConstants::HILOS_USER_UPDATE => HilosUserUpdateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_CREATE => BotCreateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_UPDATE => BotUpdateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_DELETE => BotDeleteActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_CREATE => ModeratorPieceCreateActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_UPDATE => ModeratorPieceUpdateActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_DELETE => ModeratorPieceDeleteActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_ADD => SettingAddActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_UPDATE => SettingUpdateActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_DELETE => SettingDeleteActionDTO::fromArray($data),
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => GuardianAgentRunStartActionDTO::fromArray($data),
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => GuardianAgentRunStopActionDTO::fromArray($data),
            default => parent::createActionPayloadDTO($action, $data),
        };
    }
}
