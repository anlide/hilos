<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Constants\ChatEventType;
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
use Demo\Chat\Pages\Hilos\Users\DTO\HilosUserUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Pages\Users\AbstractHilosUserPage;
use RuntimeException;
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
     * subscription_page_error signal while keeping the subscription active.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param array<string, string> $params Route params from page subscription
     * @throws PageResourceNotFoundException When user ID is invalid or user not found
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $userId = (int) ($params[HilosPageRouteParams::HILOS_USER_USER_ID] ?? 0);
        $dbUser = $userId > 0 ? Hilos::$db->users[$userId] : null;

        if ($dbUser === null) {
            throw new PageResourceNotFoundException("User #{$userId} not found");
        }

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
            $acceptKey,
            HilosUserSubscriptionSignalData::fromEntities(
                $userId,
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
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        $dbUser = Hilos::$db->users[$dto->id];
        if ($dbUser === null) {
            throw new RuntimeException("User #{$dto->id} not found");
        }

        $oldName = $dbUser->name;
        $dbUser->actions->rename($dto->name);

        $newName = $dbUser->name;
        $signal = new TableMutationSignalData(
            TableChatContext::users,
            new TableMutationEntry(
                TableMutationType::Updated,
                $dto->id,
                $dbUser->toArray(toFrontend: true),
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

        $event = Hilos::$db->events->actions->add(ChatEventType::USER_RENAMED_BY_ADMIN->value, $dto->id, null, [
            'oldName' => $oldName,
            'newName' => $newName,
            'adminUserId' => Hilos::$rt->connections[$acceptKey]?->userId,
        ]);
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
