<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableActionException;

/**
 * AdminBotsPage — Admin bots table page handler.
 *
 * Handles initial data load on subscribe and bot create/update/delete actions.
 */
class AdminBotsPage extends AbstractChatPage
{
    public function getPageName(): string
    {
        return PageConstants::ADMIN_BOTS;
    }

    public function onSubscribe(string $acceptKey): void
    {
        $result = Hilos::$table->bots->get();

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::bots => $result],
            ),
        );
    }

    public function onUnsubscribe(string $acceptKey): void
    {
    }

    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            match (true) {
                $dto instanceof BotCreateActionDTO => $this->handleCreate($acceptKey, $dto),
                $dto instanceof BotUpdateActionDTO => $this->handleUpdate($acceptKey, $dto),
                $dto instanceof BotDeleteActionDTO => $this->handleDelete($acceptKey, $dto),
                default => throw new TableActionException("Unexpected action payload for bots page"),
            };
        } catch (TableActionException $e) {
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData(TableChatContext::bots, $action, $e->getMessage()),
            );
        }
    }

    private function handleCreate(string $acceptKey, BotCreateActionDTO $dto): void
    {
        $mutation = Hilos::$table->bots->actions->create($dto);
        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }

    /**
     * @throws TableActionException
     */
    private function handleUpdate(string $acceptKey, BotUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid bot ID');
        }

        $objectCollection = Hilos::$db->bots->getObjectCollection();
        if (!isset($objectCollection[(string) $dto->id])) {
            throw new TableActionException("Bot #{$dto->id} not found");
        }

        $mutation = Hilos::$table->bots[$dto->id]->actions->update($dto);
        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }

    /**
     * @throws TableActionException
     */
    private function handleDelete(string $acceptKey, BotDeleteActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid bot ID');
        }

        $objectCollection = Hilos::$db->bots->getObjectCollection();
        if (!isset($objectCollection[(string) $dto->id])) {
            throw new TableActionException("Bot #{$dto->id} not found");
        }

        $mutation = Hilos::$table->bots[$dto->id]->actions->delete();
        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }
}
