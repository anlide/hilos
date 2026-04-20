<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
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
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
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

        try {
            $this->handleHilosUserUpdate($acceptKey, $dto);
        } catch (TableActionException $e) {
            $this->broadcastAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData(TableChatContext::users, $action, $e->getMessage()),
            );
        }
    }

    /**
     * Rename user through {@see \Demo\Chat\Database\Actions\Item\UserActions::rename} and fan out updates.
     *
     * @throws TableActionException On validation or rename failure
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid user ID');
        }

        if ($dto->name === '') {
            throw new TableActionException('User name cannot be empty');
        }

        if (!isset(Hilos::$db->users[$dto->id])) {
            throw new TableActionException("User #{$dto->id} not found");
        }

        $dbUser = Hilos::$db->users[$dto->id];
        $oldName = $dbUser->name;

        try {
            $dbUser->actions->rename($dto->name);
        } catch (RtBaseException $e) {
            throw new TableActionException($e->getMessage());
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
    }

    private function broadcastAgent(): AbstractAgent
    {
        if (!$this->agent instanceof AbstractAgent) {
            throw new TableActionException('Hilos user update requires AbstractAgent (chat worker context)');
        }

        return $this->agent;
    }
}
