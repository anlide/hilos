<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\HilosException;
use Throwable;

/**
 * Handles profile browser subscription and user-initiated rename actions.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractPage
{
    public const string PAGE = PageConstants::PROFILE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::RENAME => RenameActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            ChatSignalConstants::RENAME_MODERATION_RESULT => RenameModerationResultSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_PROFILE,
    ];

    /**
     * Routes profile actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException When rename moderation setup fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::RENAME:
                if (!$dto instanceof RenameActionDTO) {
                    throw new InvalidActionPayloadException($action, RenameActionDTO::class, $dto);
                }
                $this->handleRename($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Routes profile-page agent signals to rename moderation handling.
     *
     * @param AgentSignalData $data Wrapped rename moderation result payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this page
     * @throws ValidationException When moderation rejects the requested display name
     * @throws AgentException When moderation result does not match an active rename request
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::RENAME_MODERATION_RESULT:
                $this->handleRenameModerationResult($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Sends rename failures through the profile modal ack contract.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        if ($action === ChatSignalConstants::RENAME) {
            $this->sendToUser(
                ChatSignalConstants::RENAME_FAIL,
                $acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );

            return;
        }

        parent::onActionException($acceptKey, $action, $dto, $e);
    }

    /**
     * Starts moderation for a user-initiated rename action.
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     * @throws EmptyValueException When name is empty
     * @throws ItemNotFoundForUpdateException When user session is missing
     * @throws ValidationException When another rename is already being moderated
     * @throws HilosException On runtime update failure
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            $this->logAgentError("Empty new name (acceptKey={$acceptKey})");
            throw new EmptyValueException('User name cannot be empty');
        }

        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        if (
            Hilos::$rt->selfConnection->renameModerationPhase
            === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Another rename is already being moderated');
        }

        Hilos::$rt->selfConnection->actions->startRenameModeration($dto->newName);
    }

    /**
     * Applies an approved rename moderation result or exposes a retryable failure.
     *
     * Stale connection results fail the agent-signal contract and never rename a user.
     *
     * @param RenameModerationResultSignalData $result Moderation result for a requested display name
     * @throws ValidationException When moderation rejects the display name or is unavailable
     * @throws AgentException When result does not match an active connection rename request
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    private function handleRenameModerationResult(RenameModerationResultSignalData $result): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new AgentException('Rename moderation result connection is stale');
        }

        if (
            Hilos::$rt->selfConnection->acceptKey !== $result->acceptKey
            || Hilos::$rt->selfConnection->userId !== $result->userId
            || Hilos::$rt->selfConnection->renameModerationPhase
            !== ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
            || Hilos::$rt->selfConnection->renameModerationName !== $result->newName
        ) {
            throw new AgentException('Rename moderation result does not match active request');
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE
                : ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED;
            Hilos::$rt->selfConnection->actions->failRenameModeration(
                $phase,
                $reason,
            );

            throw new ValidationException($reason);
        }

        $user = Hilos::$db->users[$result->userId] ?? null;
        if ($user === null) {
            Hilos::$rt->selfConnection->actions->failRenameModeration(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE,
                'user_not_found',
            );
            throw new ItemNotFoundForUpdateException('User not found for rename');
        }

        $oldName = $user->name;
        Hilos::$rt->selfConnection->actions->clearRenameModeration();
        $user->actions->rename($result->newName);

        Hilos::$db->events->actions->addUserRenamed(
            userId: $result->userId,
            oldName: $oldName,
            newName: $result->newName,
        );

        // Dedicated ack to the initiator: closes the modal / clears UI loading state.
        $this->sendToUser(
            ChatSignalConstants::RENAME_SUCCESS,
            $result->acceptKey,
            new ActionSuccessSignalData(),
        );
    }
}
