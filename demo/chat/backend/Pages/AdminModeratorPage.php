<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceDeleteActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\HilosException;
use Throwable;

/**
 * AdminModeratorPage - Admin moderator prompt pieces page handler.
 *
 * Handles initial data load on subscribe and piece create/update/delete actions.
 */
final class AdminModeratorPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::ADMIN_MODERATOR;

    /**
     * Narrows the page agent to the chat worker used for moderator prompt table actions.
     *
     * @return ChatAgent Chat worker bound to this admin moderator page
     */
    protected function getChatAgent(): ChatAgent
    {
        assert($this->agent instanceof ChatAgent);

        return $this->agent;
    }

    /**
     * Sends the initial moderator prompt pieces table full snapshot to the user on page subscription.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param PageRouteParams $params Route params from page subscription (unused for moderator page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::moderatorPromptPieces => Hilos::$table->moderatorPromptPieces->getFullSnapshot()],
            ),
        );
    }

    /**
     * Routes incoming moderator piece actions (create/update/delete) to the appropriate handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name (for error reporting)
     * @param ActionPayloadDTO $dto Action payload (ModeratorPieceCreateActionDTO|ModeratorPieceUpdateActionDTO|ModeratorPieceDeleteActionDTO)
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException On table mutation or signal failure
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::MODERATOR_PIECE_CREATE:
                if (!$dto instanceof ModeratorPieceCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, ModeratorPieceCreateActionDTO::class, $dto);
                }
                $this->handleCreate($acceptKey, $dto);

                break;

            case ChatSignalConstants::MODERATOR_PIECE_UPDATE:
                if (!$dto instanceof ModeratorPieceUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, ModeratorPieceUpdateActionDTO::class, $dto);
                }
                $this->handleUpdate($acceptKey, $dto);

                break;

            case ChatSignalConstants::MODERATOR_PIECE_DELETE:
                if (!$dto instanceof ModeratorPieceDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, ModeratorPieceDeleteActionDTO::class, $dto);
                }
                $this->handleDelete($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Sends moderator prompt table action failures to the initiating client.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $acceptKey,
            new TableActionErrorSignalData(TableChatContext::moderatorPromptPieces, $action, $e->getMessage()),
        );
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
        Hilos::$table->moderatorPromptPieces->actions->create($dto);
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

        Hilos::$table->moderatorPromptPieces[$dto->id]->actions->update($dto);
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

        Hilos::$table->moderatorPromptPieces[$dto->id]->actions->delete();
    }
}
