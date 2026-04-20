<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Page\DTO\RenameActionDTO;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\HilosException;
use Hilos\Utils\Logger;

/**
 * ProfilePage - User profile page handler.
 *
 * Handles subscription, unsubscription, and actions for the user profile page.
 */
final class ProfilePage extends AbstractChatPage
{
    public const string PAGE = PageConstants::PROFILE;

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param array<string, string> $params Route params from page subscription (unused for profile page)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_PROFILE,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
        );
    }

    /**
     * Handle page-specific action logic.
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @throws HilosException On error during action handling
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
     * Handle rename action.
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     * @throws HilosException On error during rename operation
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            Logger::logAgentError('ProfilePage', "Empty new name (acceptKey={$acceptKey})");
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::RENAME_FAIL,
                $acceptKey,
                new ActionFailSignalData('empty', 'User name cannot be empty'),
            );
            return;
        }

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentError('ProfilePage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        $user = Hilos::$db->users[$userId];
        $oldName = $user->name;
        $user->actions->rename($dto->newName);

        $event = Hilos::$db->events->actions->add(ChatEventType::USER_RENAMED->value, $userId, null, [
            'oldName' => $oldName,
            'newName' => $dto->newName,
        ]);
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
                updates: [DbChatContext::users => [$user->toArray(toFrontend: true)]],
            )),
        );

        $mutation = new TableMutationEntry(
            TableMutationType::Updated,
            $userId,
            $user->toArray(toFrontend: true),
        );
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::TABLE_MUTATION,
            new TableMutationSignalData(TableChatContext::users, $mutation),
        );

        // Dedicated ack to the initiator: closes the modal / clears UI loading state.
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::RENAME_SUCCESS,
            $acceptKey,
            new ActionSuccessSignalData(),
        );
    }
}
