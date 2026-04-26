<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

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
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use Throwable;

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
     * @param PageRouteParams $params Route params from page subscription (unused for profile page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
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
     * @throws AgentUnknownActionException When action is not supported by this page
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
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Sends rename failures through the profile modal ack contract.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        if ($action === ChatSignalConstants::RENAME) {
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::RENAME_FAIL,
                $acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );

            return;
        }

        parent::onActionException($acceptKey, $action, $dto, $e);
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
            throw new EmptyValueException('User name cannot be empty');
        }

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentError('ProfilePage', "User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        $user = Hilos::$db->users[$userId];
        $oldName = $user->name;
        $user->actions->rename($dto->newName);

        $event = Hilos::$db->events->actions->addUserRenamed(
            userId: $userId,
            oldName: $oldName,
            newName: $dto->newName,
        );
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
                updates: [DbChatContext::users => [$user->toArray(toFrontend: true)]],
            )),
        );

        $mutation = new TableRowMutationDTO(
            TableMutationType::Update,
            $userId,
            Hilos::$table->adminUsers->makeRow($user->toArray(toFrontend: true)),
        );
        $adminUsersSignal = new TableMutationSignalData(TableChatContext::adminUsers, $mutation);
        $hilosUsersSignal = new TableMutationSignalData(
            TableChatContext::hilosUsers,
            new TableRowMutationDTO(
                TableMutationType::Update,
                $userId,
                Hilos::$table->hilosUsers->makeRow($user->toArray(toFrontend: true)),
            ),
        );
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::TABLE_MUTATION,
            $acceptKey,
            $adminUsersSignal,
        );
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::TABLE_MUTATION,
            $acceptKey,
            $hilosUsersSignal,
        );
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::TABLE_MUTATION_PENDING,
            $adminUsersSignal,
            $acceptKey,
        );
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::TABLE_MUTATION_PENDING,
            $hilosUsersSignal,
            $acceptKey,
        );

        // Dedicated ack to the initiator: closes the modal / clears UI loading state.
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::RENAME_SUCCESS,
            $acceptKey,
            new ActionSuccessSignalData(),
        );
    }
}
