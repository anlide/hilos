<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Auth\ChatStepUpOperationKey;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\View\Item\UserRename;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\DTO\UserRenameSignalData;
use Random\RandomException;

/**
 * The chat demo's users library - the project half of the framework sign-in feature (HIL-622).
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent}, and so does the person -
 * created, named and guarded against deletion on the framework's table. What stayed behind is
 * the handful of answers only this project can give: what else happens when an account is born,
 * which methods an identifier may be offered, and the provider wiring a social login runs on.
 *
 * Beside them it holds the one profile submit that is the chat's own - the moderation round trip
 * of a person's rename of themselves (HIL-771); the rename itself, an administrator's included,
 * is the framework's, and chat only writes it into the room's feed (HIL-1196). It was an action of
 * {@see ProfilePage} until a page turned out to carry no claim: a page runs in whatever worker
 * serves the connection, and the account tables are owned here. The profile's ways in and the email change came here the same way and went on
 * to the framework's library (HIL-1137), which every project declaring the sign-in feature
 * inherits; what is left on the profile page is what does not write: reading the person, and
 * starting a provider link, which the framework's profile page hosts.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by the chat's own topology, and
 * reached because the chat declares {@see HilosFeature::AUTH}: the feature is what turns the
 * library's command names into this project's door.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
    /**
     * The chat tables this library writes from its OWN process.
     *
     * The account row itself is not among them: the framework's library claims it (HIL-1194).
     *
     * The registry is per process, and the account event is written HERE rather than in the agent
     * that owns the room: a claim registered by the chat agent covers the chat agent's worker and
     * nothing else, so without this the "registered in chat" line would be refused as a write with
     * no truth source behind it. The same second claim the admin index agent makes on these
     * tables, and for the same reason. The rename's feed line lands in the same table, written
     * here by {@see afterUserRenamed()}; the journal row it links is the framework library's
     * claim (HIL-1196).
     *
     * The two claims never reach the cluster's arbiter as a clash, because both agents are placed
     * the same way - neither names a placement, so both are the leader's, and a second claim from
     * the node that already holds one is not a second node.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        ChatDbContext::events => TruthSourceOperation::BY_KIND,
        ChatDbContext::eventUserRegistrations => TruthSourceOperation::BY_KIND,
    ];

    /**
     * The connection rows, claimed update-only and deliberately so.
     *
     * They belong to the chat agent, which registers, moves and strikes them out; what this
     * library touches on one is the moderation phase of a rename it is running, three fields of a
     * row somebody else brought into being. The same shape of co-ownership the delivery journal
     * has.
     *
     * It reads those rows for the same work: a profile submit names its person by the connection
     * that sent it, and the rename parks its moderation phase on that row.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT = [ChatRtContext::connections => [TruthSourceOperation::Update]];

    /**
     * The chat's own profile submit, on top of every sign-in command the framework declares.
     *
     * The rename writes a person, which is what moved it off {@see ProfilePage} (HIL-771). Its
     * name is unchanged: an action's name IS its address, so declaring it here is the whole of
     * the move. The profile's ways in and the email change are the framework library's names
     * now (HIL-1137), inherited with the rest of its commands.
     */
    public const array AGENT_ACTIONS = [
        ...parent::AGENT_ACTIONS,
        ChatSignalConstants::RENAME => RenameActionDTO::class,
    ];

    /**
     * The rename, because it acts on the submitter's own account.
     *
     * The page it came off was closed by {@see PageAccessLevel::AUTHENTICATED}, which gated
     * its actions along with the subscription. An agent action carries no page level to inherit,
     * so without this list a guest could submit it - and it reads its person from the acting
     * connection, which an anonymous one has none of.
     */
    public const array AUTH_ACTIONS = [
        ...parent::AUTH_ACTIONS,
        ChatSignalConstants::RENAME,
    ];

    /**
     * The moderator's verdict on a requested display name.
     *
     * The verdict is the far end of a person's own rename: this library asks, the moderator
     * answers here, and this library applies the name. The round trip is one agent's business
     * end to end (HIL-771) - splitting it left the ask on an agent and the answer on a page, and
     * the page could not write the row the answer decides.
     *
     * The admin rename the two pages forward is the framework library's frame, and the framework
     * handles it (HIL-1195); chat adds nothing to it but the feed line its hook writes (HIL-1196).
     */
    public const array AGENT_SIGNALS = [
        ...parent::AGENT_SIGNALS,
        ChatSignalConstants::RENAME_MODERATION_RESULT => RenameModerationResultSignalData::class,
    ];

    /**
     * Runs the chat's own profile submit, or hands the name back to the framework.
     *
     * The rename answers with no reply: the browser learns of it from the projection that
     * re-emits once the moderator's verdict is applied, exactly as it did while it was a page
     * action.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply of a framework command, or null for the rename
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws ValidationException When a submit is refused
     * @throws RandomException When a framework command cannot draw from the CSPRNG
     * @throws HilosException When a routed read or write fails
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::RENAME:
                if (!$dto instanceof RenameActionDTO) {
                    throw new InvalidActionPayloadException($action, RenameActionDTO::class, $dto);
                }
                $this->startRename($acceptKey, $dto);

                return null;

            default:
                return parent::onAgentAction($acceptKey, $action, $dto);
        }
    }

    /**
     * Takes the moderator's verdict on a rename, or hands the name back to the framework.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declared
     * @throws LogicException When the verdict payload is not the one its name promises
     * @throws AgentException When the verdict does not match a rename this connection is running
     * @throws ValidationException When a framework frame carries the wrong payload
     * @throws InvalidArgumentException When a frame the handler sends cannot be named or queued
     * @throws HilosException When the account read or the rename ask fails
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        if ($name !== ChatSignalConstants::RENAME_MODERATION_RESULT) {
            parent::onSignalAgent($data, $sender, $name);

            return;
        }

        if (!$data->data instanceof RenameModerationResultSignalData) {
            throw new LogicException(
                ChatSignalConstants::RENAME_MODERATION_RESULT
                . ' payload must be ' . RenameModerationResultSignalData::class,
            );
        }

        $this->applyRenameModerationResult($data->data);
    }

    /**
     * Announces a new member in the room the moment the account exists.
     *
     * @param int $userId User that was just created
     * @param string $identifier Normalized identifier the account was created for (unused)
     * @throws HilosException When the event write fails
     * @throws LogicException If event id is null after sync
     */
    public function afterUserCreated(int $userId, string $identifier): void
    {
        Hilos::$db->events->actions->addUserRegistered($userId);
    }

    /**
     * Writes a rename into the room's feed: the event, and the journal row linked to it (HIL-1196).
     *
     * One transaction, so the feed line is whole or absent. The rename committed before this
     * runs, so the transaction is a top-level one; a failure rolls it back and goes out to the
     * framework, which logs it - the rename stands.
     *
     * @param UserRename $rename Journal row of the rename just committed
     * @throws HilosException When the event, the link or the transaction cannot be written
     */
    public function afterUserRenamed(UserRename $rename): void
    {
        Database::transactionStart();
        try {
            Hilos::$db->events->actions->addUserRenamed($rename);
            Database::transactionCommit();
        } catch (HilosException $e) {
            Database::transactionRollback();
            throw $e;
        }
    }

    /**
     * Builds the OAuth service the chat's providers are configured on.
     *
     * @return OAuthService Service over the demo's provider credentials
     * @throws HilosException Whatever reading the OAuth providers' configuration raises
     */
    protected function buildOAuthService(): ?OAuthService
    {
        return ChatOAuthConfig::buildService();
    }

    /**
     * Starts moderation for a user-initiated rename action.
     *
     * The requested name is parked on the acting connection's own row, which is where the
     * moderator picks it up ({@see ModeratorAgent}); nothing is written to the person until the
     * verdict comes back to {@see applyRenameModerationResult()}.
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     * @throws EmptyValueException When name is empty
     * @throws ItemNotFoundForUpdateException When user session is missing
     * @throws ValidationException When another rename is already being moderated
     * @throws HilosException On runtime update failure
     */
    private function startRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            $this->logAgentError("Empty new name (acceptKey={$acceptKey})");
            throw new EmptyValueException('User name cannot be empty');
        }

        $connection = $this->actingConnection($acceptKey);
        $this->requireStepUp($acceptKey, ChatStepUpOperationKey::CHANGE_NAME);

        if ($connection->renameModerationPhase === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING) {
            throw new ValidationException('Another rename is already being moderated');
        }

        $connection->actions->startRenameModeration($dto->newName);
    }

    /**
     * Applies an approved rename moderation result or tells the asker it was refused.
     *
     * Stale connection results fail the agent-signal contract and never rename a user. An
     * approved name is given by the framework, as the person's own rename: the person's agent
     * writes the name and its journal row, then the feed line {@see afterUserRenamed()} writes
     * (HIL-1196, HIL-1404). The name the person already carries writes nothing. A write the agent
     * refuses - the account was merged away meanwhile - reaches the person's window in the same
     * frame a moderation refusal does.
     *
     * The refusal is addressed to the connection that asked, as the action_error of the
     * `rename` action it submitted - the same frame, for the same action name, that the page
     * used to send from its exception hook. It is sent here rather than thrown because the
     * hook that turned a thrown failure into that frame belongs to a page, and this handler
     * runs on an agent: throwing would leave the person's modal waiting on nothing.
     *
     * @param RenameModerationResultSignalData $result Moderation result for a requested display name
     * @throws AgentException When result does not match an active connection rename request
     * @throws InvalidArgumentException When the refusal frame or the rename ask cannot be named or queued
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    private function applyRenameModerationResult(RenameModerationResultSignalData $result): void
    {
        $connection = Hilos::$rt->connections[$result->acceptKey] ?? null;
        if ($connection === null) {
            throw new AgentException('Rename moderation result connection is stale');
        }

        if (
            $connection->userId !== $result->userId
            || $connection->renameModerationPhase !== ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
            || $connection->renameModerationName !== $result->newName
        ) {
            throw new AgentException('Rename moderation result does not match active request');
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE
                : ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED;
            $connection->actions->failRenameModeration($phase, $reason);
            $publicReason = ModerationDecision::publicNameReason($reason, $phase);
            if ($phase === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED) {
                $this->notifyRenameRejected($result->userId, $result->newName, $publicReason);
            }

            $this->refuseRename($result->acceptKey, $publicReason);

            return;
        }

        if (!isset(Hilos::$db->users[$result->userId])) {
            $connection->actions->failRenameModeration(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE,
                'user_not_found',
            );
            $this->refuseRename($result->acceptKey, 'User not found for rename');

            return;
        }

        $connection->actions->clearRenameModeration();
        $this->askRename(new UserRenameSignalData(
            userId: $result->userId,
            name: $result->newName,
            renamedByUserId: $result->userId,
            replySignal: HilosSignalConstants::HILOS_USER_RENAME_DONE,
            acceptKey: $result->acceptKey,
            requestId: null,
            action: ChatSignalConstants::RENAME,
            successMessage: null,
            answerSignal: null,
        ));
    }

    /**
     * Tells one connection its rename was refused, in the frame it is already listening for.
     *
     * @param string $acceptKey Connection that asked for the rename
     * @param string $reason Public moderation reason shown to the user
     * @throws InvalidArgumentException When the action-error signal cannot be named or queued
     */
    private function refuseRename(string $acceptKey, string $reason): void
    {
        $this->sendToUser(
            SignalConstants::ACTION_ERROR,
            $acceptKey,
            new PageActionErrorSignalData(ChatSignalConstants::RENAME, $reason),
        );
    }

    /**
     * Notifies the user that moderation refused the display name they asked for.
     *
     * Only a verdict about the name notifies: an unavailable moderator is an
     * infrastructure failure, and there is nothing to tell the user about it. The
     * emit is best-effort with respect to the rejection - the refusal sent next
     * reaches the user whatever happens to the notification.
     *
     * @param int $userId User who asked to be renamed
     * @param string $newName Rejected display name
     * @param string $reason Public moderation reason
     */
    private function notifyRenameRejected(int $userId, string $newName, string $reason): void
    {
        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: ChatNotificationType::RENAME_REJECTED,
                title: 'Your new name was not accepted',
                severity: NotificationSeverity::WARNING,
                body: $reason,
                data: [
                    'reason' => $reason,
                    'newName' => $newName,
                ],
            ));
        } catch (HilosException $e) {
            $this->logAgentError(
                "Rename rejection notification failed for userId={$userId}: {$e->getMessage()}",
            );
        }
    }

    /**
     * Resolves the connection that submitted, or refuses the action.
     *
     * Read off the chat's own connection rows rather than off `selfConnection`, which is what
     * the page handlers used: an agent is not the connection's page host and has no "self" - it
     * is handed an accept key and looks the row up.
     *
     * @param string $acceptKey Acting connection accept key
     * @return Connection Row of the connection that submitted
     * @throws ItemNotFoundForUpdateException When no live connection carries the key
     */
    private function actingConnection(string $acceptKey): Connection
    {
        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        return $connection;
    }
}
