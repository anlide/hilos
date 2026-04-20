<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\DTO\HilosUserUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Runtime\Exception\RtBaseException;

/**
 * UserPage - Hilos single user page implementation for demo.
 *
 * Sends full users table snapshot on subscribe; {@see ChatSignalConstants::HILOS_USER_UPDATE} renames via DB actions
 * and broadcasts the same signals as admin user update (table_mutation + chat event).
 */
final class UserPage extends AbstractHilosUserPage
{
    /**
     * {@inheritDoc}
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $result = Hilos::$table->users->get();

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::users => $result],
            ),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        if (!$dto instanceof HilosUserUpdateActionDTO) {
            return;
        }

        $this->handleHilosUserUpdate($acceptKey, $dto);
    }

    /**
     * Rename user through {@see \Demo\Chat\Database\Actions\Item\UserActions::rename} and fan out updates.
     *
     * On validation or runtime failure, sends a dedicated {@see ChatSignalConstants::HILOS_USER_UPDATE_FAIL}
     * ack to the initiator (reason + human-readable message); on success, sends
     * {@see ChatSignalConstants::HILOS_USER_UPDATE_SUCCESS} after the broadcast fan-out.
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            $this->sendFail($acceptKey, 'invalid_id', 'Invalid user ID');
            return;
        }

        if ($dto->name === '') {
            $this->sendFail($acceptKey, 'empty_name', 'User name cannot be empty');
            return;
        }

        if (!isset(Hilos::$db->users[$dto->id])) {
            $this->sendFail($acceptKey, 'not_found', "User #{$dto->id} not found");
            return;
        }

        $dbUser = Hilos::$db->users[$dto->id];
        $oldName = $dbUser->name;

        try {
            $dbUser->actions->rename($dto->name);
        } catch (RtBaseException $e) {
            $this->sendFail($acceptKey, 'rename_failed', $e->getMessage());
            return;
        }

        $mutation = new TableMutationEntry(
            TableMutationType::Updated,
            $dto->id,
            $dbUser->toArray(toFrontend: true),
        );
        $signal = new TableMutationSignalData(TableChatContext::users, $mutation);

        $agent = $this->broadcastAgent();
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
            'newName' => $dto->name,
            'adminUserId' => Hilos::$rt->connections[$acceptKey]?->userId,
        ]);
        $agent->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
                updates: [DbChatContext::users => [[User::id => $dto->id, User::name => $dto->name]]],
            )),
        );

        $agent->sendToUser(
            ChatSignalConstants::HILOS_USER_UPDATE_SUCCESS,
            $acceptKey,
            new ActionSuccessSignalData(),
        );
    }

    /**
     * Send a {@see ChatSignalConstants::HILOS_USER_UPDATE_FAIL} ack to the initiator.
     *
     * Reason is a stable enum-like code for programmatic handling; message is
     * a human-readable text (backend-owned, i18n-ready, safe for direct display).
     */
    private function sendFail(string $acceptKey, string $reason, string $message): void
    {
        $this->broadcastAgent()->sendToUser(
            ChatSignalConstants::HILOS_USER_UPDATE_FAIL,
            $acceptKey,
            new ActionFailSignalData($reason, $message),
        );
    }

    private function broadcastAgent(): AbstractAgent
    {
        if (!$this->agent instanceof AbstractAgent) {
            throw new TableActionException('Hilos user update requires AbstractAgent (chat worker context)');
        }

        return $this->agent;
    }
}
