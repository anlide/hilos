<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\HilosUser\DTO\HilosUserMergeActionDTO;
use Demo\Chat\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Users\DTO\AdminRenameDoneSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Throwable;

/**
 * Handles the chat demo implementation of the Hilos user-detail page.
 *
 * Subscription snapshots are browser-config driven. The update action renames the
 * selected user through table actions and sends modal success/fail acks.
 */
final class UserPage extends AbstractHilosUserPage
{
    /** @var list<string> The person this page is about */
    public const array READS_DB = [ChatDbContext::users];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_USER_UPDATE => HilosUserUpdateActionDTO::class,
        ChatSignalConstants::ACCOUNT_MERGE => HilosUserMergeActionDTO::class,
    ];

    /**
     * The library's answer to the rename this page forwarded (HIL-771).
     *
     * The merge next door is answered by the chat agent instead, and the difference is what
     * each ack has to reach: a merge ends in the loser's sockets being signed out, which is the
     * project's business, while a rename ends where it started - on this page, in the modal
     * still waiting.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE => AdminRenameDoneSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserGuardKey::KEY => ChatBrowserRef::HILOS_USER_ID,
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];

    /**
     * Routes Hilos user-detail actions to page handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException When a rename or a merge cannot be handed to its library
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_USER_UPDATE:
                if (!$dto instanceof HilosUserUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserUpdateActionDTO::class, $dto);
                }
                $this->handleHilosUserUpdate($acceptKey, $dto);

                break;

            case ChatSignalConstants::ACCOUNT_MERGE:
                if (!$dto instanceof HilosUserMergeActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserMergeActionDTO::class, $dto);
                }
                $this->handleAccountMerge($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
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
        if ($action === HilosSignalConstants::HILOS_USER_UPDATE) {
            $this->sendToUser(
                HilosSignalConstants::HILOS_USER_UPDATE_FAIL,
                $acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );

            return;
        }

        parent::onActionException($acceptKey, $action, $dto, $e);
    }

    /**
     * Answers the admin whose rename the library has finished (HIL-771).
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the ack cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof AdminRenameDoneSignalData) {
            throw new LogicException($name . ' payload must be ' . AdminRenameDoneSignalData::class);
        }

        $this->answerRename($data->data);
    }

    /**
     * Hands one rename to the library that owns the account (HIL-771).
     *
     * The page keeps the submit, because the admin guard closing this surface is the page's and
     * an agent action has no level to inherit; the account row and the room's log line for the
     * rename belong to {@see UsersLibraryAgent}, which is where the writing happens now.
     *
     * Nothing is judged on the way out, not even that the person exists: the answer would be
     * read in this worker and acted on in another. Who is asking IS resolved here, because this
     * worker is the one holding the admin's socket.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param HilosUserUpdateActionDTO $dto Update action payload
     * @throws InvalidArgumentException When the rename frame cannot be named or queued
     */
    private function handleHilosUserUpdate(string $acceptKey, HilosUserUpdateActionDTO $dto): void
    {
        $requestId = $this->currentActionRequestId();
        $this->agent->sendToAgent(
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME,
            new AdminRenameSignalData(
                userId: $dto->id,
                name: $dto->name,
                acceptKey: $acceptKey,
                requestId: $requestId,
                adminUserId: Hilos::$rt->selfConnection?->userId,
            ),
        );

        if ($requestId !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Turns the library's outcome into the ack this page has always sent.
     *
     * The submit is untracked - the admin surface listens for the two named acks rather than
     * for a correlated reply - so both shapes are kept: the tracked branch answers a request id
     * if one ever arrives, and the plain one sends the very frames the handler and its
     * exception hook sent before the move.
     *
     * @param AdminRenameDoneSignalData $done Whom to answer, and why the rename was refused
     * @throws InvalidArgumentException When the ack cannot be named
     */
    private function answerRename(AdminRenameDoneSignalData $done): void
    {
        if ($done->requestId !== null) {
            if ($done->error === null) {
                $this->sendActionSuccess(
                    $done->acceptKey,
                    HilosSignalConstants::HILOS_USER_UPDATE,
                    $done->requestId,
                );

                return;
            }

            $this->sendActionFail(
                $done->acceptKey,
                HilosSignalConstants::HILOS_USER_UPDATE,
                $done->requestId,
                $done->error,
            );

            return;
        }

        if ($done->error !== null) {
            $this->sendToUser(
                HilosSignalConstants::HILOS_USER_UPDATE_FAIL,
                $done->acceptKey,
                new ActionFailSignalData($done->error),
            );

            return;
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_USER_UPDATE_SUCCESS,
            $done->acceptKey,
            new ActionSuccessSignalData(),
        );
    }

    /**
     * Forwards an admin account-merge request to the sessions library (HIL-378, HIL-729).
     *
     * The tracked client action fires-and-forwards: the transaction, the force-logout of the
     * loser and the outcome all belong to the framework's sessions library, because the merge
     * ends in that loser's live sessions being signed out. What this project still answers is
     * a pair of seams - who may be merged, and what a chat keeps for a person.
     *
     * The library hands the outcome back to the chat agent, which acks the initiator with
     * ACCOUNT_MERGE_SUCCESS or ACCOUNT_MERGE_FAIL; this page sends neither. The accept key
     * travels the whole way and comes back untouched, so the ack reaches the one admin who
     * asked.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param HilosUserMergeActionDTO $dto Merge action payload (survivor row + picked loser)
     */
    private function handleAccountMerge(string $acceptKey, HilosUserMergeActionDTO $dto): void
    {
        $this->agent->sendToAgent(
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
            new AccountMergeSignalData($dto->survivorUserId, $dto->loserUserId, $acceptKey),
        );
    }
}
