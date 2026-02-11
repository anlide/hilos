<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Database\Idea;
use Demo\Chat\Database\IdeaCollection\Events as IdeaEvents;
use Demo\Chat\DTO\Action\RenameActionDTO;
use Demo\Chat\DTO\ChatEventSignalDTO;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Actions\IdeaActionsObjectCollectionNullException;
use Hilos\Exception\Idea\Actions\IdeaActionsTableNameUndeterminedException;
use Hilos\Exception\Idea\Actions\IdeaActionsUnknownLazyStrategyException;
use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;
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
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_PROFILE,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
        );
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
     * @throws DatabaseException
     * @throws IdeaActionsObjectCollectionNullException
     * @throws IdeaActionsUnknownLazyStrategyException
     * @throws IdeaTruthSourceWriteNotAllowedException
     * @throws IdeaActionsTableNameUndeterminedException
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
     * @throws DatabaseException
     * @throws IdeaActionsObjectCollectionNullException
     * @throws IdeaActionsUnknownLazyStrategyException
     * @throws IdeaTruthSourceWriteNotAllowedException
     * @throws IdeaActionsTableNameUndeterminedException
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            Logger::logAgentError('ProfilePage', "Empty new name (acceptKey={$acceptKey})");
            return;
        }

        if (!isset(Idea::$rt->connections[$acceptKey])) {
            Logger::logAgentError('ProfilePage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $oldName = Idea::$db->users[Idea::$rt->connections[$acceptKey]->userId]->name;
        Idea::$db->users->actions->rename(Idea::$rt->connections[$acceptKey]->userId, $dto->newName);

        $userId = Idea::$rt->connections[$acceptKey]->userId;
        $event = Idea::$db->events->actions->add(ChatEventType::USER_RENAMED->value, $userId, [
            'oldName' => $oldName,
            'newName' => $dto->newName,
        ]);
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [Idea::events => IdeaEvents::fromSingleItem($event)],
                updates: [Idea::users => [['id' => $userId, 'name' => $dto->newName]]],
            )),
        );
    }
}
