<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Database\Idea;
use Demo\Chat\DTO\Action\RenameActionDTO;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Logging\Logger\Logger;

/**
 * ProfilePage - User profile page handler
 *
 * Handles subscription, unsubscription, and actions for the user profile page.
 */
class ProfilePage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::PROFILE;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        // TODO: Implement profile page subscription logic
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        // TODO: Implement profile page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::RENAME:
                if ($dto instanceof RenameActionDTO) {
                    $this->handleRename($acceptKey, $dto);
                }
                break;

            default:
                Logger::logAgentError('ProfilePage', "Unknown action: {$action}");
        }
    }

    /**
     * Handle rename action
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            Logger::logAgentError('ProfilePage', "Empty new name (acceptKey={$acceptKey})");
            return;
        }

        $userId = Idea::$rt->connections->getUserId($acceptKey);
        if ($userId === null) {
            Logger::logAgentError('ProfilePage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $renameResult = Idea::$db->users->actions->rename($userId, $dto->newName);
        if ($renameResult === null) {
            return; // No change
        }

        // Build entities update
        $user = Idea::$db->users[$userId];
        if ($user !== null) {
            $publicUser = $user->toArray(idAsIndex: false, toFrontend: true);

            $entities = new EntitiesChangesDTO(
                updates: [Idea::users => [$publicUser]],
            );

            $this->getChatAgent()->addEvent(ChatEventType::USER_RENAMED, $userId, [
                'oldName' => $renameResult['oldName'],
                'newName' => $renameResult['newName'],
            ], $entities);
        }
    }
}
