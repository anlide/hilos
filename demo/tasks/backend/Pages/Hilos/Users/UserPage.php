<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Users;

use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Browser\TasksBrowserRef;
use Demo\Tasks\Browser\TasksBrowserSource;
use Demo\Tasks\Agents\Hilos\UsersLibraryAgent;
use Demo\Tasks\Constants\AgentType;
use Demo\Tasks\Core\Router\DTO\ActionFailSignalData;
use Demo\Tasks\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Tasks\Hilos;
use Demo\Tasks\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Users\DTO\AdminRenameDoneSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Throwable;

/**
 * Tasks implementation of the Hilos user-detail page.
 *
 * Subscription snapshots are browser-config driven. The update action forwards the rename to
 * {@see UsersLibraryAgent}, which owns the account row and writes the audit row and the
 * notice beside it (HIL-771); this page keeps the submit, because the admin guard closing the
 * surface is a page's, and turns the library's answer into the modal success/fail acks.
 */
final class UserPage extends AbstractHilosUserPage
{
    /** @var list<string> The person this page is about */
    public const array READS_DB = [TasksDbContext::users];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_USER_UPDATE => HilosUserUpdateActionDTO::class,
    ];

    /**
     * The library's answer to the rename this page forwarded (HIL-771).
     *
     * Declaring it here is what brings the answer back to the surface that asked: a page-owned
     * signal is routed to the agent serving this page, which hands it to this handler.
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
                BrowserGuardKey::SOURCE => TasksBrowserSource::DB_USERS,
                BrowserGuardKey::KEY => TasksBrowserRef::HILOS_USER_ID,
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
     * @throws HilosException When the rename cannot be handed to the library
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
     * The page keeps the submit, because the admin guard closing this surface is a page's and an
     * agent action has no level to inherit; the account row, the audit row beside it and the
     * notice to the renamed person all belong to {@see UsersLibraryAgent}, which is where they
     * are written now.
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
                adminUserId: Hilos::$browser?->resolveActionUserId($acceptKey),
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
}
