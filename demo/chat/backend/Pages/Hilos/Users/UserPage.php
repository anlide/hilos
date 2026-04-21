<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
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
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUserPage;

/**
 * UserPage - Hilos single user page implementation for demo.
 *
 * On subscribe, sends one requested user entity. {@see ChatSignalConstants::HILOS_USER_UPDATE}
 * renames through DB actions and broadcasts the same signals as admin user update.
 *
 * @property ChatAgent|DemoHilosAgent $agent
 *     Inherited page agent narrowed from {@see AbstractPage::$agent}.
 *     Subscriptions are routed to {@see DemoHilosAgent}; update actions are routed to {@see ChatAgent}.
 *     The framework constructor still accepts {@see PageAgentInterface}.
 */
final class UserPage extends AbstractHilosUserPage
{
    /**
     * Sends the initial single-user entity snapshot to a Hilos user-detail subscriber.
     *
     * Payload carries one user in `entities.full.users`, or an empty envelope when the id
     * is missing, invalid, or unknown.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param array<string, string> $params Route params from page subscription (expects 'userId')
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $userId = (int) ($params['userId'] ?? 0);
        $dbUser = $userId > 0 ? Hilos::$db->users[$userId] : null;

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
            $acceptKey,
            HilosUserSubscriptionSignalData::fromEntities(
                $userId,
                $dbUser !== null
                    ? new EntitiesChangesDTO(full: [DbChatContext::users => Users::fromSingleItem($dbUser)])
                    : new EntitiesChangesDTO(),
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
        switch ($action) {
            case ChatSignalConstants::HILOS_USER_UPDATE:
                if ($dto instanceof HilosUserUpdateActionDTO) {
                    $this->handleHilosUserUpdate($acceptKey, $dto);
                }

                break;

            default:
                return;
        }
    }

    /**
     * Renames a user through {@see UserActions::rename} and fans out updates.
     *
     * Action-layer validation failures become a dedicated
     * {@see ChatSignalConstants::HILOS_USER_UPDATE_FAIL} ack to the initiator.
     * On success, sends {@see ChatSignalConstants::HILOS_USER_UPDATE_SUCCESS}
     * after the broadcast fan-out.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param HilosUserUpdateActionDTO $dto Update action payload
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        $agent = $this->getChatAgent();
        $dbUser = Hilos::$db->users[$dto->id];
        if ($dbUser === null) {
            $agent->sendToUser(
                ChatSignalConstants::HILOS_USER_UPDATE_FAIL,
                $acceptKey,
                new ActionFailSignalData('not_found', "User #{$dto->id} not found"),
            );
            return;
        }

        $oldName = $dbUser->name;

        try {
            $dbUser->actions->rename($dto->name);
        } catch (HilosException $e) {
            $agent->sendToUser(
                ChatSignalConstants::HILOS_USER_UPDATE_FAIL,
                $acceptKey,
                new ActionFailSignalData('rename_failed', $e->getMessage()),
            );
            return;
        }

        $newName = $dbUser->name;
        $signal = new TableMutationSignalData(
            TableChatContext::users,
            new TableMutationEntry(
                TableMutationType::Updated,
                $dto->id,
                $dbUser->toArray(toFrontend: true),
            ),
        );

        $agent->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $agent->emitChangeDb(
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
        $agent->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
                updates: [DbChatContext::users => [[User::id => $dto->id, User::name => $newName]]],
            )),
        );

        $agent->sendToUser(
            ChatSignalConstants::HILOS_USER_UPDATE_SUCCESS,
            $acceptKey,
            new ActionSuccessSignalData(),
        );
    }

    /**
     * Returns the chat worker agent used for user update actions.
     *
     * @return ChatAgent
     */
    private function getChatAgent(): ChatAgent
    {
        assert($this->agent instanceof ChatAgent);

        return $this->agent;
    }
}
