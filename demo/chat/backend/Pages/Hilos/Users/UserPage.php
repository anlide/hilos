<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\HilosUserSubscriptionSignalData;
use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Database\View\Collection\Users;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\TableChatContext;
use Demo\Chat\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;
use Throwable;

/**
 * UserPage - Hilos single user page implementation for demo.
 *
 * On subscribe, sends one requested user entity. {@see ChatSignalConstants::HILOS_USER_UPDATE}
 * renames through DB actions and broadcasts the same signals as admin user update.
 */
final class UserPage extends AbstractHilosUserPage
{
    /**
     * Sends the initial single-user entity snapshot to a Hilos user-detail subscriber.
     *
     * Throws PageResourceNotFoundException when user is not found, which triggers
     * subscription_page_error signal while keeping the subscription active. The
     * `userId` param is already validated to be `> 0` by
     * {@see HilosUserPageSubscribeParams::fromPageRouteParams()}.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param HilosUserPageSubscribeParams $params Parsed subscribe params
     * @throws PageResourceNotFoundException When the user does not exist in the DB
     * @throws ObjectGetIdStringNotImplementedException When user entity cannot be converted to an ID string
     * @throws CollectionNotManualException When user snapshot cannot be created from an automatic collection
     */
    protected function onHilosUserSubscribe(string $acceptKey, HilosUserPageSubscribeParams $params): void
    {
        $dbUser = Hilos::$db->users[$params->userId];
        if ($dbUser === null) {
            throw new PageResourceNotFoundException("User #{$params->userId} not found");
        }

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
            $acceptKey,
            HilosUserSubscriptionSignalData::fromEntities(
                $params->userId,
                new EntitiesChangesDTO(full: [DbChatContext::users => Users::fromSingleItem($dbUser)]),
            ),
        );
    }

    /**
     * Routes Hilos user-detail actions to page handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            switch ($action) {
                case ChatSignalConstants::HILOS_USER_UPDATE:
                    if ($dto instanceof HilosUserUpdateActionDTO) {
                        $this->handleHilosUserUpdate($acceptKey, $dto);
                    }

                    break;

                default:
                    return;
            }
        } catch (Throwable $e) {
            $this->sendToUser(
                ChatSignalConstants::HILOS_USER_UPDATE_FAIL,
                $acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );
        }
    }

    /**
     * Renames a user through {@see UserActions::rename} and fans out updates.
     *
     * Thrown failures become a dedicated
     * {@see ChatSignalConstants::HILOS_USER_UPDATE_FAIL} ack to the initiator.
     * On success, sends {@see ChatSignalConstants::HILOS_USER_UPDATE_SUCCESS}
     * after the broadcast fan-out.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param HilosUserUpdateActionDTO $dto Update action payload
     * @throws ValidationException When rename payload violates user validation rules
     * @throws HilosException On database error or broadcast failure
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        $dbUser = Hilos::$db->users[$dto->id];
        if ($dbUser === null) {
            throw new ItemNotFoundForUpdateException("User #{$dto->id} not found");
        }

        $oldName = $dbUser->name;
        $mutation = Hilos::$table->hilosUsers[$dto->id]->actions->update($dto);
        $newName = $dbUser->name;
        $signal = new TableMutationSignalData(TableChatContext::hilosUsers, $mutation);
        $adminUsersSignal = new TableMutationSignalData(
            TableChatContext::adminUsers,
            new TableMutationEntry(
                TableMutationType::Updated,
                $dto->id,
                Hilos::$table->adminUsers->makeRow($dbUser->toArray(toFrontend: true)),
            ),
        );

        $this->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->emitChangeDb(
            ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED,
            EmitDbChangeSignalData::fromTableMutationSignal(
                entityId: (string) $dto->id,
                signal: $signal,
                excludeAcceptKey: $acceptKey,
                actorUserId: Hilos::$rt->connections[$acceptKey]?->userId,
            ),
        );
        $this->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $adminUsersSignal, $acceptKey);

        $event = Hilos::$db->events->actions->addUserRenamedByAdmin(
            userId: $dto->id,
            oldName: $oldName,
            newName: $newName,
            adminUserId: Hilos::$rt->connections[$acceptKey]?->userId,
        );
        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
                updates: [DbChatContext::users => [[User::id => $dto->id, User::name => $newName]]],
            )),
        );

        $this->sendToUser(
            ChatSignalConstants::HILOS_USER_UPDATE_SUCCESS,
            $acceptKey,
            new ActionSuccessSignalData(),
        );
    }
}
