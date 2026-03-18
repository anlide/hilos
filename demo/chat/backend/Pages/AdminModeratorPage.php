<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceDeleteActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\HilosException;

/**
 * AdminModeratorPage - Admin moderator prompt pieces page handler.
 *
 * Handles initial data load on subscribe and piece create/update/delete actions.
 */
final class AdminModeratorPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::ADMIN_MODERATOR;

    /**
     * Sends initial moderator prompt pieces table data to the user on page subscription.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param array<string, string> $params Route params from page subscription (unused for moderator page)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $result = Hilos::$table->moderatorPromptPieces->get();

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::moderatorPromptPieces => $result],
            ),
        );
    }

    /**
     * Routes incoming moderator piece actions (create/update/delete) to the appropriate handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name (for error reporting)
     * @param ActionPayloadDTO $dto Action payload (ModeratorPieceCreateActionDTO|ModeratorPieceUpdateActionDTO|ModeratorPieceDeleteActionDTO)
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            match (true) {
                $dto instanceof ModeratorPieceCreateActionDTO => $this->handleCreate($acceptKey, $dto),
                $dto instanceof ModeratorPieceUpdateActionDTO => $this->handleUpdate($acceptKey, $dto),
                $dto instanceof ModeratorPieceDeleteActionDTO => $this->handleDelete($acceptKey, $dto),
                default => throw new TableActionException("Unexpected action payload for moderator page"),
            };
        } catch (TableActionException $e) {
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData(TableChatContext::moderatorPromptPieces, $action, $e->getMessage()),
            );
        }
    }

    /**
     * Creates a new moderator prompt piece and broadcasts the mutation to all clients.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param ModeratorPieceCreateActionDTO $dto Create action payload
     * @throws TableActionException If create fails
     * @throws HilosException If mutation broadcasting fails
     */
    private function handleCreate(string $acceptKey, ModeratorPieceCreateActionDTO $dto): void
    {
        $mutation = Hilos::$table->moderatorPromptPieces->actions->create($dto);
        $signal = new TableMutationSignalData(TableChatContext::moderatorPromptPieces, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }

    /**
     * Updates an existing moderator prompt piece and broadcasts the mutation to all clients.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param ModeratorPieceUpdateActionDTO $dto Update action payload
     *
     * @throws TableActionException If piece ID is invalid or piece not found
     */
    private function handleUpdate(string $acceptKey, ModeratorPieceUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid moderator prompt piece ID');
        }

        if (!isset(Hilos::$db->moderatorPromptPieces[$dto->id])) {
            throw new TableActionException("Moderator prompt piece #{$dto->id} not found");
        }

        $mutation = Hilos::$table->moderatorPromptPieces[$dto->id]->actions->update($dto);
        $signal = new TableMutationSignalData(TableChatContext::moderatorPromptPieces, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }

    /**
     * Deletes a moderator prompt piece and broadcasts the mutation to all clients.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param ModeratorPieceDeleteActionDTO $dto Delete action payload
     *
     * @throws TableActionException If piece ID is invalid or piece not found
     */
    private function handleDelete(string $acceptKey, ModeratorPieceDeleteActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid moderator prompt piece ID');
        }

        if (!isset(Hilos::$db->moderatorPromptPieces[$dto->id])) {
            throw new TableActionException("Moderator prompt piece #{$dto->id} not found");
        }

        $mutation = Hilos::$table->moderatorPromptPieces[$dto->id]->actions->delete();
        $signal = new TableMutationSignalData(TableChatContext::moderatorPromptPieces, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }
}
