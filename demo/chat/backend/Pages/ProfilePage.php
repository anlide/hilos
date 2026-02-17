<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Hilos;
use Demo\Chat\Hilos\Database\Collection\Events;
use Demo\Chat\DTO\Action\RenameActionDTO;
use Demo\Chat\DTO\ChatEventSignalDTO;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Hilos\Database\Actions\ObjectCollectionNullException;
use Hilos\Exception\Hilos\Database\Actions\TableNameUndeterminedException;
use Hilos\Exception\Hilos\Database\Actions\UnknownLazyStrategyException;
use Hilos\Exception\Hilos\Database\TruthSource\WriteNotAllowedException;
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
     * @throws ObjectCollectionNullException
     * @throws UnknownLazyStrategyException
     * @throws WriteNotAllowedException
     * @throws TableNameUndeterminedException
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
     * @throws ObjectCollectionNullException
     * @throws UnknownLazyStrategyException
     * @throws WriteNotAllowedException
     * @throws TableNameUndeterminedException
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            Logger::logAgentError('ProfilePage', "Empty new name (acceptKey={$acceptKey})");
            return;
        }

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentError('ProfilePage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $oldName = Hilos::$db->users[Hilos::$rt->connections[$acceptKey]->userId]->name;
        Hilos::$db->users->actions->rename(Hilos::$rt->connections[$acceptKey]->userId, $dto->newName);

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        $event = Hilos::$db->events->actions->add(ChatEventType::USER_RENAMED->value, $userId, [
            'oldName' => $oldName,
            'newName' => $dto->newName,
        ]);
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [Hilos::events => Events::fromSingleItem($event)],
                updates: [Hilos::users => [['id' => $userId, 'name' => $dto->newName]]],
            )),
        );
    }
}
