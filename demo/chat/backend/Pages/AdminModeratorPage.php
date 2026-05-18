<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceDeleteActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\HilosException;
use Throwable;

/**
 * Handles admin moderator prompt piece table actions.
 *
 * @property LibraryAgent $agent
 */
final class AdminModeratorPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_MODERATOR;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::LIBRARY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
    ];

    /**
     * Routes moderator prompt piece actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException When a routed table mutation fails
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
        $this->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $acceptKey,
            new TableActionErrorSignalData(ChatTableContext::moderatorPromptPieces, $action, $e->getMessage()),
        );
    }

    /**
     * Creates a moderator prompt piece through the table action.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param ModeratorPieceCreateActionDTO $dto Create action payload
     * @throws HilosException When prompt validation or persistence fails
     */
    private function handleCreate(string $acceptKey, ModeratorPieceCreateActionDTO $dto): void
    {
        Hilos::$table->moderatorPromptPieces->actions->create($dto);
    }

    /**
     * Updates a moderator prompt piece through the table action.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param ModeratorPieceUpdateActionDTO $dto Update action payload
     * @throws TableActionException When piece id is invalid or the piece is missing
     * @throws HilosException When prompt persistence fails
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
     * Deletes a moderator prompt piece through the table action.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param ModeratorPieceDeleteActionDTO $dto Delete action payload
     * @throws TableActionException When piece id is invalid or the piece is missing
     * @throws HilosException When prompt persistence fails
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
