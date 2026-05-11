<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Throwable;

/**
 * Handles the chat demo implementation of the Hilos user-detail page.
 *
 * Subscription snapshots are projection-driven. The update action renames the
 * selected user through table actions and sends modal success/fail acks.
 */
final class UserPage extends AbstractHilosUserPage
{
    /**
     * Routes Hilos user-detail actions to page handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ItemNotFoundForUpdateException When the target user is missing
     * @throws HilosException When user update, audit event persistence, or success ack fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::HILOS_USER_UPDATE:
                if (!$dto instanceof HilosUserUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserUpdateActionDTO::class, $dto);
                }
                $this->handleHilosUserUpdate($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Sends Hilos user update failures through the user-detail modal ack contract.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        if ($action === ChatSignalConstants::HILOS_USER_UPDATE) {
            $this->sendToUser(
                ChatSignalConstants::HILOS_USER_UPDATE_FAIL,
                $acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );

            return;
        }

        parent::onActionException($acceptKey, $action, $dto, $e);
    }

    /**
     * Renames the selected user through the Hilos users table action and writes an audit event.
     *
     * Thrown failures become a dedicated fail ack through onActionException().
     * On success, the initiator receives the HILOS_USER_UPDATE_SUCCESS ack.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param HilosUserUpdateActionDTO $dto Update action payload
     * @throws ItemNotFoundForUpdateException When the target user is missing
     * @throws HilosException When rename, audit event persistence, or success ack fails
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        $dbUser = Hilos::$db->users[$dto->id]
            ?? throw new ItemNotFoundForUpdateException("User #{$dto->id} not found");

        $oldName = $dbUser->name;
        Hilos::$table->hilosUsers[$dto->id]->actions->update($dto);
        $newName = $dbUser->name;

        Hilos::$db->events->actions->addUserRenamedByAdmin(
            userId: $dto->id,
            oldName: $oldName,
            newName: $newName,
            adminUserId: Hilos::$rt->selfConnection?->userId,
        );

        $this->sendToUser(
            ChatSignalConstants::HILOS_USER_UPDATE_SUCCESS,
            $acceptKey,
            new ActionSuccessSignalData(),
        );
    }
}
