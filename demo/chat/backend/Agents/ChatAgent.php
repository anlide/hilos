<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Auth\ChatErasureRenameFeed;
use Demo\Chat\CLI\DTO\SendPauseEndCommandData;
use Demo\Chat\CLI\DTO\SubmissionObserveCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Browser\OutboundModerationBrowserPayload;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Moderation\ChatModerationRefusal;
use Demo\Chat\Moderation\MessageAuthorChangedException;
use Demo\Chat\Moderation\MessageModerationDeadline;
use Demo\Chat\Runtime\View\Item\Connection;
use Generator;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Environment\NonProductionGate;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\UploadHoldOutcome;
use Hilos\Files\Upload\UploadMime;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationReason;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\View\Item\HilosModerationCheck as ModerationCheck;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\WiringRefusal;
use Random\RandomException;
use SplPriorityQueue;
use Throwable;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkedSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasurePersonalCaptureResult;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountMergePart;
use Hilos\Auth\AccountOperation\AccountMergeProjectOwner;
use Hilos\Auth\AccountOperation\AccountMergeProjectStep;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use LogicException as NativeLogicException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\RelayedActionReplyDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Users\AccountErasure;
use JsonException;

/**
 * Monopolistic chat worker for chat events, users, runtime connections, WebSocket lifecycle, and bot messages.
 *
 * On start, registers chat database tables and runtime collections as truth sources.
 *
 * It no longer holds this project's sessions: they went into {@see SessionsLibraryAgent}
 * whole (HIL-710). What stayed is the half a project cannot give away - who is on the wire and
 * the tab that has to be told; who that person is, the framework builds from the frame
 * ({@see self::handshakeIdentity()}) - so the two speak in frames.
 * {@see HilosSignalConstants::HILOS_SESSION_STATE} arrives saying what a session has become
 * and is answered by {@see self::applySessionState()}.
 *
 * Since HIL-729 it asks the library for nothing at all: impersonation and account merge both
 * moved there whole, because the guards and the write they need are the same process again,
 * and what this project answers is a seam apiece.
 *
 * Its erasure part removes a person's feed rows, and its prepared merge part transfers message
 * authorship. It refuses to write a message after the author's erasure begins or merge commits.
 */
final class ChatAgent extends AbstractAgent implements AccountErasureProjectOwner, AccountMergeProjectOwner
{
    /**
     * The room and everything written into it: the events, their messages, who joined, and what
     * was attached.
     *
     * The rename journal belongs to each person's agent. History cleanup asks those owners to
     * take their rows off the feed and waits for their answers (HIL-1897).
     *
     * The session set is not among them, nor are the two runtime lists of the browsers parked on
     * a confirmation code: they belong to {@see SessionsLibraryAgent}, which claims them in its
     * own process (HIL-710). Nor are the accounts: every process reads them, and an edit of one is
     * that person's agent's (HIL-1404).
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        ChatDbContext::events => TruthSourceOperation::BY_KIND,
        ChatDbContext::eventMessages => TruthSourceOperation::BY_KIND,
        ChatDbContext::eventUserRegistrations => TruthSourceOperation::BY_KIND,
        ChatDbContext::eventAttachments => TruthSourceOperation::BY_KIND,
    ];

    /**
     * Results of this agent's erasure and merge steps are added and never changed. Their set
     * is the agent's full id, the owner named by the assignment. See
     * docs/agents/architecture/account-operations.md, "The participant result claim".
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB_SET = [
        HilosDbContext::accountOperationResults => [TruthSourceOperation::Add],
    ];

    /**
     * Who is on the wire and what each of them is doing; what they upload is the framework
     * uploads agent's (HIL-144).
     *
     * The connections stay this agent's, because who is on the wire is this node's truth and the
     * row carries chat's own fields.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT = [
        ChatRtContext::connections => TruthSourceOperation::BY_KIND,
        ChatRtContext::userStates => TruthSourceOperation::BY_KIND,
    ];

    /** @var array<string, list<TruthSourceOperation>> Only checks whose verdicts come back to chat */
    public const array OWNS_RT_SET = [HilosModerationCheck::RT_COLLECTION => TruthSourceOperation::BY_KIND];

    /** @var list<string> Readiness and provenance of the accepted attachments */
    public const array READS_RT = [HilosUpload::RT_COLLECTION];

    /**
     * @var list<string> Settings, files, journals a history clear asks to unlink, and erasure/merge assignments and operations
     */
    public const array READS_DB = [
        HilosDbContext::settings,
        HilosDbContext::files,
        ChatDbContext::userRenames,
        HilosDbContext::accountOperationAssignments,
        HilosDbContext::accountOperations,
    ];

    public const string AGENT_TYPE = AgentType::CHAT;

    public const string ACCOUNT_OPERATION_SIGNAL = ChatSignalConstants::CHAT_ACCOUNT_OPERATION_STEP;

    public const array AGENT_COMMANDS = [
        ChatCommandConstants::SUBMISSION_TEST_OBSERVE => SubmissionObserveCommandData::class,
        ChatCommandConstants::SEND_PAUSE_TEST_END => SendPauseEndCommandData::class,
    ];

    // The library frame is declared HERE and not in the library, which is what routes it to
    // this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself (HIL-710).
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::CHAT_ACCOUNT_OPERATION_STEP => AccountOperationAskSignalData::class,
        ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
        HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
        ChatSignalConstants::ATTACHMENTS_PUBLISHED => FilesPublishedSignalData::class,
        ChatSignalConstants::USER_RENAME_FEED_UNLINKED => UserRenameFeedUnlinkedSignalData::class,
    ];

    private const string IMAGE_MIME_PREFIX = 'image/';
    private const int CLEAR_ID_BYTES = 8;
    private const int MODERATION_RECOVERY_ROWS_PER_TICK = 16;
    private const int MODERATION_CANDIDATES_PER_TICK = 16;
    private const int MODERATION_RETRY_MS = TimeConstants::MS_PER_SECOND;
    private const array MESSAGE_CHECK_KINDS = [ChatModerationKindKey::MESSAGE, ChatModerationKindKey::MESSAGE_PICTURE];
    private const int SESSION_STATE_VISITS_PER_TICK = 16;

    /** @var ?SplPriorityQueue<array{int, int}, array{MessageModerationDeadline, int}> Due work in deadline and insertion order */
    private ?SplPriorityQueue $moderationDue = null;

    /** @var array<string, array{MessageModerationDeadline, int}> Current scheduled candidate per RT address */
    private array $moderationScheduled = [];

    private int $moderationSequence = 0;

    /** @var ?Generator<int, array{string, string}|null, void, void> Addresses from committed membership cuts */
    private ?Generator $moderationRecovery = null;

    private bool $moderationRecovered = false;

    /** @var array<string, SessionStateTarget> One unfinished transition per address, in visit order */
    private array $sessionStateTargets = [];

    /** @var array<int, SessionStateApplication> Frames whose socket visits ended before their final effects */
    private array $sessionStateReplies = [];

    /** @var array<string, array<int|string, SessionStateApplication>> Pending replies per initiating address */
    private array $sessionStateInitiators = [];

    /**
     * History clear waiting for unlink answers; lost on restart on purpose, the next cron starts
     * from the data. Correlation only has to differ between attempts, so tolerant randomness suffices.
     */
    private ?string $clearId = null;

    /** @var array<int, true> People whose unlink answer is still awaited */
    private array $clearAwaits = [];

    /**
     * Answers behind publication work already queued by this agent, preserving its causal order.
     *
     * @param CommandRequestDTO $data Topology-hydrated command
     * @param string $source Command source, unused
     * @param string $name Signal name, unused
     * @throws HilosException When runtime state cannot be read or the reply cannot be queued
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case ChatCommandConstants::SUBMISSION_TEST_OBSERVE:
                if (!NonProductionGate::admitted()) {
                    $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Submission observation is test-only'));

                    return;
                }
                $this->observeSubmission($data, $data->parsedPayload);

                return;

            case ChatCommandConstants::SEND_PAUSE_TEST_END:
                if (!NonProductionGate::admitted()) {
                    $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Send pause end is test-only'));

                    return;
                }
                $this->endSendPause($data, $data->parsedPayload);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
        }
    }

    /**
     * Opens the first check for the submission already admitted by MainPage.
     *
     * @param string $acceptKey Connection holding the accepted draft
     * @throws HilosException When runtime state cannot be read or the failure state cannot be written
     */
    public function startMessageModeration(string $acceptKey): void
    {
        if (
            Hilos::$rt->connections[$acceptKey] === null
            || Hilos::$rt->connections[$acceptKey]->staleSince() !== null
            || Hilos::$rt->connections[$acceptKey]->isUnregisterOwed()
            || Hilos::$rt->connections[$acceptKey]->userId === null
            || Hilos::$rt->connections[$acceptKey]->outboundPublicationHoldId !== null
            || Hilos::$rt->connections[$acceptKey]->outboundModerationPhase
                !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            return;
        }

        try {
            $this->assertMessageUploadsReady($acceptKey);
            $previous = Hilos::$rt->hilosModerationChecks->find($acceptKey, ChatModerationKindKey::MESSAGE);
            if ($previous !== null && $previous->verdictTo === self::AGENT_TYPE) {
                $this->watchMessageCheck($previous, TimeHelper::nowMs());
                $this->forgetExactMessageCheck($previous);
            }
            $check = Hilos::$rt->hilosModerationChecks->actions->open(
                $acceptKey,
                Hilos::$rt->connections[$acceptKey]->userId,
                ChatModerationKindKey::MESSAGE,
                text: Hilos::$rt->connections[$acceptKey]->outboundModerationMessage,
                clientUploadIds: Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments,
                uploadSnapshots: Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments === [] ? null
                    : Hilos::$rt->hilosUploads->snapshotForConnection(
                        $acceptKey,
                        Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments,
                    ),
            );
            Database::afterCommit(fn () => $this->watchMessageCheck($check, TimeHelper::nowMs()));
        } catch (WiringRefusal $failure) {
            $this->failMessageModeration($acceptKey);
            throw $failure;
        } catch (Throwable $failure) {
            $this->failMessageModeration($acceptKey);
            $this->logAgentError('Cannot start message moderation: ' . $failure->getMessage());
            $this->forgetMessageChecks($acceptKey, Hilos::$rt->connections[$acceptKey]->userId);
        }
    }

    /**
     * Checks and accepted publications outlive the worker. Recovery and due work each
     * advance in short portions, even when an ordinary RT write has to wait for room.
     *
     * @throws HilosException When an accepted session state cannot be read or announced
     */
    public function onTick(): void
    {
        $this->sweepMessageModeration(TimeHelper::nowMs());
        $this->continueSessionStates();
    }

    /**
     * Reconciles owner expiry against the current attempt; other shared updates are ignored.
     *
     * @param RtSyncUpdatedSignalData $data Changed runtime row
     * @param string $source Framework signal source (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When runtime reads, cleanup or settlement fail
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === HilosUpload::RT_COLLECTION && Hilos::$rt->hilosUploads[$data->stateId] !== null) {
            $this->reconcileMessageUploads(Hilos::$rt->hilosUploads[$data->stateId]->acceptKey);
        }
    }

    /**
     * A deleted source ends an active check. Deletion after approval is ordinary handover.
     * Other shared deletions are ignored.
     *
     * @param RtSyncDeletedSignalData $data Deleted runtime row
     * @param string $source Framework signal source (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When runtime reads, cleanup or settlement fail
     */
    public function onSignalRtSyncDeleted(RtSyncDeletedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === HilosUpload::RT_COLLECTION
            && SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)
        ) {
            foreach (Hilos::$rt->hilosModerationChecks as $check) {
                if ($check->verdictTo !== self::AGENT_TYPE) {
                    continue;
                }
                foreach ($check->uploadSnapshots as $snapshot) {
                    if (HilosUpload::keyFor($check->acceptKey, $snapshot->clientUploadId) === $data->stateId) {
                        $this->reconcileMessageUploads($check->acceptKey);
                        return;
                    }
                }
            }
        }
    }

    /**
     * @param string $collection Claimed runtime collection
     * @return string Verdict-recipient set this agent holds
     */
    public function ownedRtSetKey(string $collection): string
    {
        return $collection === HilosModerationCheck::RT_COLLECTION ? self::AGENT_TYPE : parent::ownedRtSetKey($collection);
    }

    /**
     * The set key of the agent's claim on a collection: its full id for the operation results it records.
     *
     * @param string $collection Collection whose set claim is being resolved
     * @return string The agent's full id for its results, the base's answer otherwise
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $collection === HilosDbContext::accountOperationResults ? $this->getId() : parent::ownedDbSetKey($collection);
    }

    /**
     * Deletes the person's messages, attachments, registrations and captured rename feed events
     * inside the common step's transaction. Other people's mentions remain under SET NULL;
     * registry files leave after commit through {@see AccountErasureProjectStep}.
     *
     * @param AccountErasurePart $part Operation and account whose chat rows go
     * @return AccountErasure Deleted row counts and attachment registry ids
     * @throws ValidationException When the person's capture has not been recorded
     * @throws JsonException When the saved capture cannot be decoded
     * @throws InvalidFormatException When the capture or its rename feed list is malformed
     * @throws HilosException When storage or a truth-source check refuses a read or a delete
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $capture = AccountErasurePersonalCaptureResult::of($part->operationId, $part->userId)
            ?? throw new ValidationException('A chat erasure part waits for the person\'s capture.');
        $renameEventIds = ChatErasureRenameFeed::eventIdsOf($capture);
        $messageIds = Hilos::$db->eventMessages->eventIdsByAuthor($part->userId);
        $fileIds = Hilos::$db->eventAttachments->actions->deleteForMessages($messageIds);
        $messages = Hilos::$db->eventMessages->actions->deleteByAuthor($part->userId);
        $registrationIds = Hilos::$db->eventUserRegistrations->actions->deleteByTarget($part->userId);
        $events = Hilos::$db->events->actions->deleteByIds([...$messageIds, ...$registrationIds, ...$renameEventIds]);

        return new AccountErasure(
            [
                ChatCommandConstants::ROWS_ERASED_MESSAGES => $messages,
                ChatCommandConstants::ROWS_ERASED_ATTACHMENTS => count($fileIds),
                ChatCommandConstants::ROWS_ERASED_EVENTS => $events,
            ],
            $fileIds,
        );
    }

    /**
     * Transfers the loser's message authorship inside the common merge step's transaction.
     *
     * @param AccountMergePart $part Operation, survivor and loser whose messages move
     * @return array<string, int> Number of messages moved to the survivor
     * @throws HilosException When storage or a truth-source check refuses the move
     */
    public function mergeAccountPart(AccountMergePart $part): array
    {
        return [
            ChatCommandConstants::ROWS_MOVED_MESSAGES => Hilos::$db->eventMessages->actions->rePointAuthor(
                $part->loserUserId,
                $part->survivorUserId,
            ),
        ];
    }

    /**
     * Records chat startup and restores per-user state for connections kept through a stop.
     *
     * @throws HilosException On database or runtime startup failure
     * @throws LogicException If the startup event has no id
     * @throws NativeLogicException When the user state factory rejects a state row
     */
    public function onStart(): void
    {
        Hilos::$db->events->actions->addChatStarted();

        foreach (Hilos::$rt->connections as $connection) {
            if ($connection->userId !== null) {
                Hilos::$rt->userStates->actions->ensure($connection->userId);
            }
        }
    }

    /**
     * Unregister the WebSocket connection; its uploads are removed by the uploads agent.
     *
     * The summary is emitted after every close so online session counters update
     * when a user still has other active tabs.
     *
     * @param WebSocketCloseSignalDTO $data Closed WebSocket connection
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On runtime cleanup failure
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        $this->invalidateSessionStateInitiator($data->acceptKey);
        $this->cancelSessionStateTarget($data->acceptKey);
        $connection = Hilos::$rt->connections[$data->acceptKey];
        try {
            $holdId = $connection?->outboundPublicationHoldId;
            if (!$connection?->isPublicationClearOwed()) {
                $this->settleMessageUploadHold($data->acceptKey, $holdId, UploadHoldOutcome::RELEASED);
            }
            if ($connection !== null) {
                $this->forgetScheduledModeration($this->deadlineOfPublication($connection));
            }
            $this->forgetMessageChecks($data->acceptKey, publicationHoldId: $holdId);
        } catch (Throwable $failure) {
            $this->logAgentError('Cannot clean up moderation for a closed connection: ' . $failure->getMessage());
            throw $failure;
        } finally {
            $connection?->actions->unregister();
        }
    }

    /**
     * Records chat shutdown and clears the chat runtime state that is the agent's own.
     *
     * The connections are not among it (HIL-664). Who is on the wire is the truth of the node
     * holding the sockets, and a stop does not close them: a freeze stops this agent and leaves
     * every tab connected, so emptying the collection here told the rest of the node the hall
     * was empty while it was full - and the restore that followed photographed nobody. The rows
     * outlive the agent now, and the sockets that died meanwhile are struck when it comes back.
     *
     * @throws HilosException On database or runtime cleanup failure
     */
    public function onStop(): void
    {
        $this->moderationRecovery = null;
        $this->sessionStateTargets = [];
        $this->sessionStateReplies = [];
        $this->sessionStateInitiators = [];
        Hilos::$db->events->actions->addChatStopped();
        Hilos::$rt->userStates->actions->clear();
    }

    /**
     * History cleanup asks journal owners to unlink, then deletes history after their answers.
     *
     * The files are collected before their events go and handed to the files library after: it
     * removes each one at once, rather than leaving it to hold its place in the storage limit
     * until the janitor's day runs out (HIL-144).
     *
     * The expired-registration-hold sweep belongs to the users library (HIL-1411), scheduled
     * by its own rule. It tells the sessions holder which browser's hold expired so that
     * the holder can roll back its wait.
     *
     * @param SignalDataInterface $data Cron payload (unused)
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Task name
     * @throws AgentUnknownSignalException When cron name is not supported
     * @throws HilosException On history cleanup failure, or when the removal of its files cannot be asked
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatCronConstants::CLEANUP_HISTORY:
                $this->handleHistoryCleanup();

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Dispatches chat-owned inter-agent signals.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload to dispatch
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws InvalidArgumentException When a signal of the session-state answer cannot be named
     * @throws HilosException On bot message publish failure, or on database or runtime failure
     * @throws LogicException If event id is null after sync
     * @throws NativeLogicException When the runtime item factory rejects a state row
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::CHAT_ACCOUNT_OPERATION_STEP:
                $ask = $data->data;
                if (!$ask instanceof AccountOperationAskSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AccountOperationAskSignalData::class, $ask);
                }

                $this->sendToAgent(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, AccountOperationAsks::answer(
                    $ask,
                    fn (AccountOperationAssignment $assignment): AccountOperationResult =>
                        match ($assignment->operation->kind) {
                            AccountOperationKind::Merge => AccountMergeProjectStep::run($this, $assignment->id),
                            AccountOperationKind::Erasure => AccountErasureProjectStep::run($this, $assignment->id),
                        },
                    $this->getId(),
                ));

                return;

            case HilosSignalConstants::HILOS_MODERATION_VERDICT:
                $verdict = $data->data;
                if (!$verdict instanceof ModerationVerdictSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, ModerationVerdictSignalData::class, $verdict);
                }

                $this->handleMessageModerationVerdict($verdict);

                return;

            case ChatSignalConstants::ATTACHMENTS_PUBLISHED:
                $published = $data->data;
                if (!$published instanceof FilesPublishedSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, FilesPublishedSignalData::class, $published);
                }

                $this->handleAttachmentsPublished($published);

                return;

            case HilosSignalConstants::HILOS_SESSION_STATE:
                $state = $data->data;
                if (!$state instanceof SessionStateSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionStateSignalData::class, $state);
                }

                $this->applySessionState($state);

                return;

            case ChatSignalConstants::USER_RENAME_FEED_UNLINKED:
                $answer = $data->data;
                if (!$answer instanceof UserRenameFeedUnlinkedSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserRenameFeedUnlinkedSignalData::class, $answer);
                }
                $this->settleHistoryClear($answer);

                return;

            case ChatSignalConstants::BOT_MESSAGE:
                $message = $data->data;
                if (!$message instanceof BotMessageSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, BotMessageSignalData::class, $message);
                }

                $this->handleBotMessage($message);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** @param int $now Current sweep moment in epoch milliseconds */
    private function sweepMessageModeration(int $now): void
    {
        if (!Hilos::hasFeature(HilosFeature::MODERATION)
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::connections)
        ) {
            return;
        }
        $this->recoverModerationWork($now);
        for ($visit = 0; $visit < self::MODERATION_CANDIDATES_PER_TICK && !($this->moderationDue?->isEmpty() ?? true); $visit++) {
            [$candidate, $dueAt] = $this->moderationDue->top();
            if ($dueAt > $now) {
                break;
            }
            $this->moderationDue->extract();
            $address = $candidate->address();
            if (($this->moderationScheduled[$address] ?? null) !== [$candidate, $dueAt]) {
                continue;
            }

            unset($this->moderationScheduled[$address]);
            try {
                $retryAt = $candidate->kind === null
                    ? $this->visitPublication($candidate, $now)
                    : $this->visitMessageCheck($candidate, $now);
            } catch (Throwable $failure) {
                $this->logAgentError('Cannot settle message moderation: ' . $failure->getMessage());
                $retryAt = $now + self::MODERATION_RETRY_MS;
            }
            if ($retryAt !== null) {
                $this->scheduleModeration($candidate, $retryAt);
            }
        }
    }

    /** @param int $now Moment the recovered work first becomes eligible */
    private function recoverModerationWork(int $now): void
    {
        if ($this->moderationRecovered) {
            return;
        }

        // The worker retains a Chat agent even if its optional start hook throws.
        try {
            $this->moderationRecovery ??= $this->restoredModerationWork();
            for ($visited = 0; $visited < self::MODERATION_RECOVERY_ROWS_PER_TICK && $this->moderationRecovery->valid(); $visited++) {
                $address = $this->moderationRecovery->current();
                if ($address === null) {
                    $this->moderationRecovery->next();
                    return;
                }
                [$collection, $key] = $address;
                if ($collection === HilosModerationCheck::RT_COLLECTION) {
                    $check = Hilos::$rt->hilosModerationChecks[$key];
                    $candidate = $check?->verdictTo === self::AGENT_TYPE ? $this->deadlineOfCheck($check) : null;
                } else {
                    $connection = Hilos::$rt->connections[$key];
                    $candidate = $connection === null ? null : $this->deadlineOfPublication($connection);
                }
                if ($candidate !== null && !isset($this->moderationScheduled[$candidate->address()])) {
                    $this->scheduleModeration($candidate, $now);
                }
                $this->moderationRecovery->next();
            }
        } catch (WiringRefusal $refusal) {
            $this->logAgentError('Cannot read the runtime source during message moderation recovery: ' . $refusal->getMessage());
            return;
        } catch (Throwable $failure) {
            // A point read leaves its address pending. A failed cut can be opened again.
            if (!$this->moderationRecovery?->valid()) {
                $this->moderationRecovery = null;
            }
            $this->logAgentError('Cannot recover message moderation: ' . $failure->getMessage());
            return;
        }
        if (!$this->moderationRecovery->valid()) {
            $this->moderationRecovery = null;
            $this->moderationRecovered = true;
        }
    }

    /**
     * @return Generator<int, array{string, string}|null, void, void> Source and address per row, with a pause between portions
     * @throws InvalidFormatException When a snapshot row identity is malformed
     * @throws InvalidArgumentException When the snapshot work allowance is invalid
     * @throws NativeLogicException When the snapshot cannot advance
     */
    private function restoredModerationWork(): Generator
    {
        foreach ([HilosModerationCheck::RT_COLLECTION, ChatRtContext::connections] as $collection) {
            $snapshot = RtSnapshot::capture($collection, RtSnapshotScope::whole());
            while (!$snapshot->isFinished()) {
                $portion = $snapshot->advance(new RtWorkBudget());
                foreach ($portion?->ids ?? [] as $key) {
                    yield [$collection, $key];
                }
                if (!$snapshot->isFinished()) {
                    yield null;
                }
            }
        }
    }

    /**
     * @param MessageModerationDeadline $candidate Scalar coordinates to reread when the work is due
     * @param int $dueAt Next eligible visit, in epoch milliseconds
     */
    private function scheduleModeration(MessageModerationDeadline $candidate, int $dueAt): void
    {
        $address = $candidate->address();
        $scheduled = $this->moderationScheduled[$address] ?? null;
        if ($scheduled !== null && $scheduled[0] == $candidate && $scheduled[1] <= $dueAt) {
            return;
        }

        $this->moderationScheduled[$address] = [$candidate, $dueAt];
        $this->moderationDue ??= new SplPriorityQueue();
        $this->moderationDue->insert([$candidate, $dueAt], [-$dueAt, -++$this->moderationSequence]);
    }

    /** @param ?MessageModerationDeadline $candidate Exact candidate whose work ended */
    private function forgetScheduledModeration(?MessageModerationDeadline $candidate): void
    {
        if ($candidate !== null && ($this->moderationScheduled[$candidate->address()][0] ?? null) == $candidate) {
            unset($this->moderationScheduled[$candidate->address()]);
        }
    }

    /**
     * @param ModerationCheck $check Current check whose source may need work
     * @param int $dueAt Moment the check should be visited
     * @throws HilosException When the check cannot be read
     */
    private function watchMessageCheck(ModerationCheck $check, int $dueAt): void
    {
        $this->scheduleModeration($this->deadlineOfCheck($check), $dueAt);
    }

    /**
     * @param ModerationCheck $check Runtime check being scheduled
     * @return MessageModerationDeadline Its exact correlation and original deadline
     */
    private function deadlineOfCheck(ModerationCheck $check): MessageModerationDeadline
    {
        return new MessageModerationDeadline($check->acceptKey, $check->kind, $check->requestId, $check->userId, $check->deadlineAt);
    }

    /**
     * @param Connection $connection Connection whose publication may still be pending
     * @param int $dueAt Next eligible visit
     * @throws HilosException When the connection cannot be read
     */
    private function watchPublication(Connection $connection, int $dueAt): void
    {
        $candidate = $this->deadlineOfPublication($connection);
        if ($candidate !== null) {
            $this->scheduleModeration($candidate, $dueAt);
        }
    }

    /**
     * @param Connection $connection Connection holding the accepted publication
     * @return ?MessageModerationDeadline Exact publication wait, if present
     */
    private function deadlineOfPublication(Connection $connection): ?MessageModerationDeadline
    {
        $holdId = $connection->outboundPublicationHoldId;
        $deadlineAt = $connection->outboundPublicationDeadlineAt;
        $author = $connection->outboundPublicationUserId;
        if ($holdId === null || $deadlineAt === null || $author === null
            || $connection->outboundModerationPhase !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
            || $connection->outboundModerationAttachments === []) {
            return null;
        }

        return new MessageModerationDeadline($connection->acceptKey, null, $holdId, $author, $deadlineAt);
    }

    /**
     * @param MessageModerationDeadline $candidate Accepted publication marked for a visit
     * @param int $now Current sweep moment
     * @return ?int Next visit, or null when the obligation is gone
     * @throws HilosException When the exact publication cannot be read or settled
     */
    private function visitPublication(MessageModerationDeadline $candidate, int $now): ?int
    {
        $connection = Hilos::$rt->connections[$candidate->acceptKey];
        if ($this->composerUnknown($connection)) {
            return $now + self::MODERATION_RETRY_MS;
        }
        if ($connection === null) {
            $this->settleMessageUploadHold($candidate->acceptKey, $candidate->token, UploadHoldOutcome::RELEASED);
            return null;
        }
        $current = $this->deadlineOfPublication($connection);
        if ($current != $candidate) {
            if ($current !== null) {
                $this->scheduleModeration($current, $now);
            }
            return null;
        }
        if ($connection->isUnregisterOwed()) {
            $this->settleMessageUploadHold($candidate->acceptKey, $candidate->token, UploadHoldOutcome::RELEASED);
            return null;
        }
        if ($connection->isPublicationClearOwed()) {
            return $now + self::MODERATION_RETRY_MS;
        }
        if ($connection->userId !== $candidate->userId) {
            $connection->actions->clearOutboundModeration();
            $this->settleMessageUploadHold($candidate->acceptKey, $candidate->token, UploadHoldOutcome::RELEASED);
            return null;
        }
        if ($candidate->deadlineAt > $now) {
            return $candidate->deadlineAt;
        }

        $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
        return null;
    }

    /**
     * @param MessageModerationDeadline $candidate Check marked for a visit
     * @param int $now Current sweep moment
     * @return ?int Next visit, or null when the obligation is gone
     * @throws HilosException When the exact check cannot be read or settled
     */
    private function visitMessageCheck(MessageModerationDeadline $candidate, int $now): ?int
    {
        $check = Hilos::$rt->hilosModerationChecks->find($candidate->acceptKey, $candidate->kind);
        if ($check === null || $check->verdictTo !== self::AGENT_TYPE) {
            return null;
        }
        $current = $this->deadlineOfCheck($check);
        if ($current != $candidate) {
            $this->scheduleModeration($current, $now);
            return null;
        }

        $connection = Hilos::$rt->connections[$candidate->acceptKey];
        if ($check->staleSince() !== null || $this->composerUnknown($connection)) {
            return $now + self::MODERATION_RETRY_MS;
        }
        if ($connection === null || $connection->isUnregisterOwed()) {
            $this->forgetExactMessageCheck($check, $connection?->outboundPublicationHoldId);
            return null;
        }
        if (!$this->isCurrentMessageCheck($check, $connection)) {
            if ($connection->userId === null && $this->checkMatchesDraft($check, $connection)) {
                $connection->actions->clearOutboundModeration();
            }
            $this->forgetExactMessageCheck($check, $connection->outboundPublicationHoldId);
            return null;
        }
        if ($candidate->deadlineAt <= $now || $this->checkLostAnUpload($check, $now)) {
            $this->failMessageModeration($candidate->acceptKey);
            $this->forgetExactMessageCheck($check);
            return null;
        }

        return RtStaleness::staleRows(HilosUpload::RT_COLLECTION) === []
            ? $candidate->deadlineAt
            : min($candidate->deadlineAt, $now + self::MODERATION_RETRY_MS);
    }

    /** Starts over from the remaining links; no answer to the old attempt can advance this one. */
    private function handleHistoryCleanup(): void
    {
        if ($this->clearId !== null) {
            $this->logAgentError(
                "History clear {$this->clearId} replaced: no unlink answer from users #"
                . implode(', #', array_keys($this->clearAwaits)),
            );
        }

        $userIds = Hilos::$db->userRenames->linkedUserIds();
        if ($userIds === []) {
            $this->clearId = null;
            $this->clearAwaits = [];
            $this->finishHistoryClear();

            return;
        }

        $this->clearId = RandomHelper::hex(self::CLEAR_ID_BYTES);
        $this->clearAwaits = array_fill_keys($userIds, true);
        foreach ($userIds as $userId) {
            $this->sendToAgent(
                ChatSignalConstants::USER_RENAME_FEED_UNLINK,
                new UserRenameFeedUnlinkSignalData($userId, $this->clearId),
            );
        }
    }

    /**
     * @param UserRenameFeedUnlinkedSignalData $answer Owner's outcome for a history-clear attempt
     */
    private function settleHistoryClear(UserRenameFeedUnlinkedSignalData $answer): void
    {
        if ($answer->clearId !== $this->clearId || !isset($this->clearAwaits[$answer->userId])) {
            return;
        }
        if (!$answer->unlinked) {
            $this->logAgentError(
                "History clear {$this->clearId} abandoned: user #{$answer->userId} did not take the journal off the feed",
            );
            $this->clearId = null;
            $this->clearAwaits = [];

            return;
        }

        unset($this->clearAwaits[$answer->userId]);
        if ($this->clearAwaits === []) {
            $this->clearId = null;
            $this->finishHistoryClear();
        }
    }

    /** Removes history and its files only after every requested unlink succeeded. */
    private function finishHistoryClear(): void
    {
        $fileIds = Hilos::$db->eventAttachments->allFileIds();
        Hilos::$db->events->actions->deleteAll();
        Hilos::$db->events->actions->addChatCleared();
        (Hilos::$files ?? throw new LogicException('The files door is not created'))->remove($fileIds);
    }

    /**
     * Keeps an admitted identity change ahead of a new submission on the same live composer.
     *
     * @param Connection $connection Current connection of the incoming page action
     * @return bool Whether Chat is still applying a different session or author to this row
     */
    public function isComposerIdentityChanging(Connection $connection): bool
    {
        $target = $this->sessionStateTargets[$connection->acceptKey] ?? null;

        return $target !== null && $target->changesComposer() && $target->stillAddresses($connection);
    }

    /**
     * Accepts a committed frame and invalidates all of its old composers before any ordinary write.
     *
     * @param SessionStateSignalData $frame Current identity and the original reply coordinates
     * @throws HilosException When an owner read, cleanup or signal is refused
     */
    private function applySessionState(SessionStateSignalData $frame): void
    {
        $connections = [];
        $initiator = $frame->initiatorAcceptKey();
        // The library's first handshake names one socket and never answers an action.
        $couldBeFirstHandshake = count($frame->acceptKeys) === 1
            && $frame->requestId === null && $frame->action === null && $frame->outcome === null;
        $acceptKeys = Hilos::$rt->connections->acceptKeysForSessionFrame($frame->acceptKeys, $frame->sessionToken);
        foreach ($acceptKeys as $acceptKey) {
            $connection = Hilos::$rt->connections[$acceptKey];
            if (($connection === null && (!$couldBeFirstHandshake || $acceptKey !== $initiator))
                || $this->composerUnknown($connection) || $connection?->isUnregisterOwed()
                || ($connection?->sessionId !== null
                    && ($frame->sessionId === null || $frame->sessionId < $connection->sessionId))) {
                continue;
            }
            $connections[$acceptKey] = $connection;
        }
        if ($connections === [] || !$this->sessionFrameIsCurrent($frame)) {
            return;
        }

        $application = new SessionStateApplication($frame, count($connections));
        $targets = [];
        $checks = [];
        foreach ($connections as $acceptKey => $connection) {
            $pending = $this->sessionStateTargets[$acceptKey] ?? null;
            $target = $pending?->canReplaceWith($frame, $connection)
                ? new SessionStateTarget($application, $connection, $pending->visit)
                : new SessionStateTarget($application, $connection);
            $targets[$acceptKey] = $target;
            if ($connection === null || !$target->changesComposer()) {
                continue;
            }
            $holdId = $connection->outboundPublicationHoldId;
            if ($connection->userId !== null && Hilos::hasFeature(HilosFeature::MODERATION)
                && SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)) {
                foreach (self::MESSAGE_CHECK_KINDS as $kind) {
                    $check = Hilos::$rt->hilosModerationChecks->find($acceptKey, $kind);
                    if ($check !== null && $check->verdictTo === self::AGENT_TYPE && $check->userId === $connection->userId) {
                        $checks[] = [$check, $holdId];
                    }
                }
            }
        }
        if ($frame->requestId !== null && $initiator !== null && isset($targets[$initiator])) {
            $previous = $this->sessionStateInitiators[$initiator][$application->replyKey()] ?? null;
            if ($previous !== null && $targets[$initiator]->servesReplyAt($previous->initiator)
                && $previous->frame->toArray() === $frame->toArray()) {
                return;
            }
        }

        $releasedHolds = [];
        foreach ($targets as $acceptKey => $target) {
            $connection = $connections[$acceptKey];
            if ($connection !== null && $target->changesComposer()) {
                $releasedHolds[$acceptKey] = $this->invalidateMessageComposer($connection);
            }
        }
        $firstVisit = null;
        foreach ($targets as $acceptKey => $target) {
            $connection = $connections[$acceptKey];
            if ($connection === null) {
                $firstVisit = $target;
                continue;
            }
            $pending = $this->sessionStateTargets[$acceptKey] ?? null;
            if ($pending !== null && $pending->visit === $target->visit) {
                unset($this->sessionStateTargets[$acceptKey]);
                $this->sessionStateTargetEnded($pending->application);
            } elseif ($pending !== null || $target->changesIdentity()) {
                $this->invalidateSessionStateInitiator((string)$acceptKey);
                $this->cancelSessionStateTarget((string)$acceptKey);
            }
            if ((string)$acceptKey === $initiator) {
                $this->rememberSessionStateInitiator($initiator, $target);
            }
            $this->sessionStateTargets[$acceptKey] = $target;
        }
        foreach ($releasedHolds as $acceptKey => $holdId) {
            $this->settleMessageUploadHold((string)$acceptKey, $holdId, UploadHoldOutcome::RELEASED);
        }
        foreach ($checks as [$check, $holdId]) {
            $this->watchMessageCheck($check, TimeHelper::nowMs());
            try {
                $this->forgetExactMessageCheck($check, $holdId);
            } catch (RtWriteBackpressureException) {
                // The bounded moderation sweep can remove this exact old request later.
            }
        }
        $targetBudget = self::SESSION_STATE_VISITS_PER_TICK;
        if ($firstVisit !== null && $initiator !== null) {
            $this->rememberSessionStateInitiator($initiator, $firstVisit);
            $this->trySessionStateVisit($initiator, $firstVisit, retry: false);
            $targetBudget--;
        }
        $this->continueSessionStates($targetBudget);
    }

    /**
     * @param SessionStateSignalData $frame Session reported by the library
     * @return bool Whether its persisted author and token still match
     * @throws HilosException When the session row cannot be read
     */
    private function sessionFrameIsCurrent(SessionStateSignalData $frame): bool
    {
        return $frame->sessionId === null ? $frame->userId === null
            : Hilos::$db->sessions->matchesStoredIdentity($frame->sessionId, $frame->userId, $frame->sessionToken);
    }

    /** @param string $acceptKey Closed or superseded address */
    private function cancelSessionStateTarget(string $acceptKey): void
    {
        $target = $this->sessionStateTargets[$acceptKey] ?? null;
        if ($target !== null) {
            unset($this->sessionStateTargets[$acceptKey]);
            $target->visit->ended = true;
            $this->sessionStateTargetEnded($target->application);
        }
    }

    /** @param SessionStateApplication $application Frame whose socket visit ended */
    private function sessionStateTargetEnded(SessionStateApplication $application): void
    {
        if (--$application->remaining !== 0) {
            return;
        }
        if ($application->refused || ($application->applied === [] && $application->initiator === null)) {
            $this->forgetSessionStateInitiator($application);
            return;
        }
        $this->sessionStateReplies[spl_object_id($application)] = $application;
    }

    /**
     * @param string $acceptKey Named initiator of the frame
     * @param SessionStateTarget $target Exact handshake awaited by this reply
     */
    private function rememberSessionStateInitiator(string $acceptKey, SessionStateTarget $target): void
    {
        $application = $target->application;
        $frame = $application->frame;
        if ($frame->rotationTicket === null && $frame->requestId === null) {
            return;
        }
        $key = $application->replyKey();
        $waiting = ($this->sessionStateInitiators[$acceptKey][$key] ?? null)?->initiator;
        if ($target->servesReplyAt($waiting)) {
            return;
        }
        $application->initiator = $target->visit;
        $this->sessionStateInitiators[$acceptKey][$key] = $application;
    }

    /** @param string $acceptKey Closed socket or socket that accepted a newer identity */
    private function invalidateSessionStateInitiator(string $acceptKey): void
    {
        foreach ($this->sessionStateInitiators[$acceptKey] ?? [] as $application) {
            $application->initiator = null;
        }
        unset($this->sessionStateInitiators[$acceptKey]);
    }

    /** @param SessionStateApplication $application Frame that no longer has pending reply work */
    private function forgetSessionStateInitiator(SessionStateApplication $application): void
    {
        $acceptKey = $application->frame->initiatorAcceptKey();
        if ($acceptKey !== null) {
            $key = $application->replyKey();
            if (($this->sessionStateInitiators[$acceptKey][$key] ?? null) !== $application) {
                return;
            }
            unset($this->sessionStateInitiators[$acceptKey][$key]);
            if (($this->sessionStateInitiators[$acceptKey] ?? []) === []) {
                unset($this->sessionStateInitiators[$acceptKey]);
            }
        }
    }

    /**
     * Visits only a portion of the owner's pending work. A visit that still needs space goes
     * behind the other addresses, so a full FIFO cannot pin the tick on one socket.
     *
     * @param int $targetBudget Socket visits left after any first registration attempt
     * @throws HilosException When a non-retryable owner read, write or reply fails
     */
    private function continueSessionStates(int $targetBudget = self::SESSION_STATE_VISITS_PER_TICK): void
    {
        $visits = min(count($this->sessionStateTargets), $targetBudget);
        for ($visit = 0; $visit < $visits; $visit++) {
            $acceptKey = array_key_first($this->sessionStateTargets);
            $target = $this->sessionStateTargets[$acceptKey];
            unset($this->sessionStateTargets[$acceptKey]);
            $this->trySessionStateVisit((string)$acceptKey, $target, retry: true);
        }

        $replies = min(count($this->sessionStateReplies), self::SESSION_STATE_VISITS_PER_TICK);
        for ($visit = 0; $visit < $replies; $visit++) {
            $id = array_key_first($this->sessionStateReplies);
            $application = $this->sessionStateReplies[$id];
            unset($this->sessionStateReplies[$id]);
            if ($application->initiator?->ended === false) {
                $this->sessionStateReplies[$id] = $application;
                continue;
            }
            $this->finishSessionState($application);
        }
    }

    /**
     * @param string $acceptKey Address being visited
     * @param SessionStateTarget $target Original socket and pending frame
     * @param bool $retry Whether this exact socket was already registered when the frame arrived
     * @throws HilosException When a non-retryable read, write or handshake fails
     */
    private function trySessionStateVisit(string $acceptKey, SessionStateTarget $target, bool $retry): void
    {
        try {
            $waiting = $this->visitSessionState($acceptKey, $target);
        } catch (RtWriteBackpressureException) {
            $waiting = $target;
        } catch (Throwable $failure) {
            $target->application->refused = true;
            $target->visit->ended = true;
            $this->sessionStateTargetEnded($target->application);
            throw $failure;
        }
        // A singleton frame alone cannot prove an unregistered socket still exists after this handler.
        if ($waiting !== null && $retry) {
            $this->sessionStateTargets[$acceptKey] = $waiting;
            return;
        }
        $target->visit->ended = true;
        $this->sessionStateTargetEnded($target->application);
    }

    /**
     * @param string $acceptKey Address being visited
     * @param SessionStateTarget $target Original socket and pending frame
     * @return ?SessionStateTarget Work still waiting for an eligible visit, if any
     * @throws HilosException When an owner read, write or signal fails
     */
    private function visitSessionState(string $acceptKey, SessionStateTarget $target): ?SessionStateTarget
    {
        $application = $target->application;
        $frame = $application->frame;
        if ($application->refused || !$this->sessionFrameIsCurrent($frame)) {
            $application->refused = true;
            return null;
        }
        $connection = Hilos::$rt->connections[$acceptKey];
        if (!$target->stillAddresses($connection) || $connection?->isUnregisterOwed()) {
            return null;
        }
        if ($this->composerUnknown($connection) || $connection?->isPublicationClearOwed()) {
            return $target;
        }
        if ($application->identity === null) {
            if ($frame->userId !== null) {
                Hilos::$rt->userStates->actions->ensure($frame->userId);
            }
            $identity = $this->handshakeIdentity($frame);
            if ($identity->selfId !== $frame->userId) {
                // The identity projection still uses a session mirror that can lag the committed row.
                return $target;
            }
            $application->identity = $identity;
        }

        if ($connection === null) {
            Hilos::$rt->connections->actions->register($acceptKey, $frame->userId, $frame->sessionToken, $frame->sessionId);
            $connection = Hilos::$rt->connections[$acceptKey];
            if ($frame->userId !== null) {
                Hilos::$ac?->identifyBrowserSessionUser($frame->sessionToken, $frame->userId);
            }
        } else {
            $connection->actions->applySessionIdentity($frame->sessionId, $frame->userId, $frame->sessionToken);
        }
        $this->sendHandshakeResponse(ChatSignalConstants::HANDSHAKE_RESPONSE, $acceptKey, $application->identity, $frame);
        $application->applied[$acceptKey] = true;
        $target->visit->connection = $connection;

        return null;
    }

    /**
     * Sends the ordered effects of the frame after its applicable handshakes.
     *
     * @param SessionStateApplication $application Frame whose visits have finished
     * @throws HilosException When a reply, reassessment or its current basis cannot be read or queued
     */
    private function finishSessionState(SessionStateApplication $application): void
    {
        $this->forgetSessionStateInitiator($application);
        $frame = $application->frame;
        if (($application->applied === [] && $application->initiator?->connection === null) || !$this->sessionFrameIsCurrent($frame)) {
            return;
        }

        $initiator = $frame->initiatorAcceptKey();
        $connection = Hilos::$rt->connections[$initiator];
        $canAnswer = $connection !== null && $application->initiator?->connection?->sameIncarnation($connection)
            && !$this->composerUnknown($connection) && !$connection->isUnregisterOwed()
            && $connection->sessionId === $frame->sessionId && $connection->sessionToken === $frame->sessionToken
            && $connection->userId === $frame->userId;
        $applied = $application->applied;
        if ($canAnswer) {
            $applied[$initiator] = true;
            $this->handOverRotationTicket($frame);
        }
        if ($applied === []) {
            return;
        }

        // The guest no longer has the identity under which its page was last assessed.
        if ($frame->userId !== null) {
            PageAccessReassessment::forUser($frame->userId);
        } else {
            PageAccessReassessment::forConnections(array_values(array_intersect($frame->acceptKeys, array_keys($applied))));
        }

        if ($canAnswer) {
            $this->answerLibraryAction($frame);
        }
    }

    /**
     * Hands a browser its rotated-cookie ticket after sign-in, sign-out, remote session
     * termination or replacement of a dead cookie (HIL-582, HIL-1126).
     *
     * Sent from this project rather than from the library that minted it, so that it leaves
     * behind the identity above: the browser reconnects the moment it holds the ticket, and a
     * ticket overtaking the response would drop the socket before it learned who it had
     * become. A frame carrying a ticket names exactly one socket, its rightful holder.
     *
     * @param SessionStateSignalData $frame Session state that may carry a rotation
     * @throws InvalidArgumentException When the rotation signal cannot be named
     */
    private function handOverRotationTicket(SessionStateSignalData $frame): void
    {
        $ticket = $frame->rotationTicket;
        $acceptKey = $frame->initiatorAcceptKey();
        if ($ticket === null || $acceptKey === null) {
            return;
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_SESSION_ROTATE,
            $acceptKey,
            new SessionRotateSignalData($ticket),
        );
    }

    /**
     * Answers the sign-in action the frame finished, when somebody is waiting on it.
     *
     * The library deferred its own ack so that the answer would leave from behind the
     * identity it announces (HIL-622), and since the split that identity is sent from here -
     * so the answer is sent from here too. Nothing is sent for a frame carrying no request
     * id: an OAuth login was acked as "accepted, working on it" the moment the browser was
     * sent off to the provider.
     *
     * The reply goes on exactly as the library serialized it ({@see RelayedActionReplyDTO}): a
     * sign-in outcome, or the code-send reply of a profile window (HIL-1186), which is not one.
     *
     * @param SessionStateSignalData $frame Session state that may end a tracked action
     * @throws InvalidArgumentException When the reply signal cannot be named
     */
    private function answerLibraryAction(SessionStateSignalData $frame): void
    {
        $action = $frame->action;
        $requestId = $frame->requestId;
        $acceptKey = $frame->initiatorAcceptKey();
        if ($action === null || $requestId === null || $acceptKey === null) {
            return;
        }

        $outcome = $frame->outcome;
        $this->sendActionSuccess(
            $acceptKey,
            $action,
            $requestId,
            $outcome === null ? null : new RelayedActionReplyDTO($outcome),
        );
    }

    /**
     * @param CommandRequestDTO $request Command to answer
     * @param SubmissionObserveCommandData $query Exact connection coordinate
     * @throws HilosException When the connection or checks cannot be read or the reply cannot be queued
     */
    private function observeSubmission(CommandRequestDTO $request, SubmissionObserveCommandData $query): void
    {
        if (Hilos::$rt->connections[$query->acceptKey] === null) {
            $this->replyToCommand(CommandReplyDTO::error($request->correlationId, 'No such Chat connection'));

            return;
        }
        if (!SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)) {
            $this->replyToCommand(CommandReplyDTO::error($request->correlationId, 'Moderation state is not ready'));

            return;
        }
        $requestIds = [];
        foreach ([ChatModerationKindKey::MESSAGE, ChatModerationKindKey::MESSAGE_PICTURE] as $kind) {
            $check = Hilos::$rt->hilosModerationChecks->find($query->acceptKey, $kind);
            if ($check !== null) {
                $requestIds[] = $check->requestId;
            }
        }
        $this->replyToCommand(CommandReplyDTO::ok($request->correlationId, [
            ChatCommandConstants::phase => Hilos::$rt->connections[$query->acceptKey]->outboundModerationPhase,
            ChatCommandConstants::activeCheckRequestIds => $requestIds,
        ]));
    }

    /**
     * Ages the moment of the person's last accepted send, so MainPage's own rate-limit rule accepts the next one.
     *
     * @param CommandRequestDTO $request Command to answer
     * @param SendPauseEndCommandData $query Person whose send pause ends
     * @throws HilosException When the user state cannot be read or written or the reply cannot be queued
     */
    private function endSendPause(CommandRequestDTO $request, SendPauseEndCommandData $query): void
    {
        $userState = Hilos::$rt->userStates[$query->userId];
        if ($userState === null || $userState->lastOutboundSubmittedAt === 0.0) {
            $this->replyToCommand(CommandReplyDTO::error($request->correlationId, "No send pause to end for user {$query->userId}"));

            return;
        }
        $userState->actions->endSendPause();
        $this->replyToCommand(CommandReplyDTO::ok($request->correlationId, [ChatCommandConstants::userId => $query->userId]));
    }

    /**
     * Applies only the fresh check for the exact current attempt and unchanged composer.
     *
     * @param ModerationVerdictSignalData $verdict Typed common-engine verdict
     * @throws HilosException When runtime writes or persistence fail
     */
    private function handleMessageModerationVerdict(ModerationVerdictSignalData $verdict): void
    {
        if (!in_array($verdict->kind, self::MESSAGE_CHECK_KINDS, true)
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::connections)) {
            return;
        }
        $check = Hilos::$rt->hilosModerationChecks->find($verdict->acceptKey, $verdict->kind);
        $connection = Hilos::$rt->connections[$verdict->acceptKey];
        if (
            $check === null || $check->staleSince() !== null || $check->verdictTo !== self::AGENT_TYPE
            || $check->requestId !== $verdict->requestId || $connection === null
            || $connection->staleSince() !== null || $connection->isUnregisterOwed()
            || !$this->isCurrentMessageCheck($check, $connection)
        ) {
            return;
        }
        $this->watchMessageCheck($check, TimeHelper::nowMs());
        if ($connection->sessionId === null || !Hilos::$db->sessions->matchesStoredIdentity($connection->sessionId, $check->userId)) {
            $this->retireMessageComposer($connection);
            $this->forgetExactMessageCheck($check);
            return;
        }
        if ($check->deadlineAt <= TimeHelper::nowMs()) {
            $this->failMessageModeration($verdict->acceptKey);
            $this->forgetExactMessageCheck($check);
            return;
        }
        if (!$verdict->allow || $verdict->reason !== ModerationReason::OK) {
            $contentReason = !$verdict->allow && in_array($verdict->reason, Hilos::moderationKinds()[$check->kind]->reasons, true);
            $unreadable = !$verdict->allow && $check->kind === ChatModerationKindKey::MESSAGE_PICTURE
                && $verdict->reason === ModerationReason::UNREADABLE;
            $phase = $contentReason || $unreadable
                ? ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED
                : ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE;
            $reason = $contentReason || $unreadable ? $verdict->reason : ModerationReason::SERVICE_UNAVAILABLE;
            Hilos::$rt->connections[$verdict->acceptKey]->actions->failOutboundModeration(
                $phase,
                $reason,
                $phase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED ? $check->clientUploadId : null,
            );
            $this->forgetExactMessageCheck($check);
            if ($contentReason && ChatModerationRefusal::isAboutTheContent($reason)) {
                $this->notifyMessageRejected(
                    $check->userId,
                    Hilos::$rt->connections[$verdict->acceptKey]->outboundModerationMessage,
                    $check->clientUploadId === null
                        ? ChatModerationRefusal::publicMessageReason($reason, $phase)
                        : ChatModerationRefusal::publicPictureReason(
                            $reason,
                            $phase,
                            ...OutboundModerationBrowserPayload::pictureOf(Hilos::$rt->connections[$verdict->acceptKey]),
                        ),
                );
            }
            return;
        }

        try {
            $this->advanceMessageModeration($check);
        } catch (MessageAuthorChangedException) {
            $this->retireMessageComposer($connection);
            $this->forgetExactMessageCheck($check);
        } catch (AnnouncementFailedException $failure) {
            // The stage or message already committed.
            throw $failure;
        } catch (RtWriteBackpressureException $failure) {
            // The rolled-back stage keeps its exact check and original deadline.
            $this->logAgentError('Cannot continue message moderation: ' . $failure->getMessage());
        } catch (Throwable $failure) {
            $this->logAgentError('Cannot continue message moderation: ' . $failure->getMessage());
            if ($this->isCurrentMessageCheck($check, $connection)) {
                $this->failMessageModeration($verdict->acceptKey);
                $this->forgetExactMessageCheck($check);
                $this->forgetMessageChecks($check->acceptKey, $check->userId);
            }
            if ($failure instanceof WiringRefusal) {
                throw $failure;
            }
        }
    }

    /**
     * No stage list is retained: the current image id and the held attachment order define the next stage.
     *
     * @param ModerationCheck $check Approved check, carrying the chain's fixed deadline
     * @throws MessageAuthorChangedException When the stored session loses its author just before the write
     * @throws HilosException When uploads cannot be used or the next check/publication cannot start
     * @throws LogicException When the approved attachment group has no hold or files door
     * @throws RandomException When a fresh check id cannot be drawn
     * @throws NativeLogicException When the check row cannot be constructed
     */
    private function advanceMessageModeration(ModerationCheck $check): void
    {
        $this->assertMessageUploadsReady($check->acceptKey, $check);
        $afterCurrent = $check->kind === ChatModerationKindKey::MESSAGE;
        foreach ($check->uploadSnapshots as $snapshot) {
            if (!$afterCurrent) {
                $afterCurrent = $snapshot->clientUploadId === $check->clientUploadId;
                continue;
            }
            $upload = Hilos::$rt->hilosUploads->find($check->acceptKey, $snapshot->clientUploadId);
            if (str_starts_with(UploadMime::normalize($upload->detectedMimeType ?? $upload->mimeType), self::IMAGE_MIME_PREFIX)) {
                Database::transaction(DatabaseNames::PRIMARY, function () use ($check, $snapshot): void {
                    if ($check->kind === ChatModerationKindKey::MESSAGE) {
                        $previousPicture = Hilos::$rt->hilosModerationChecks->find($check->acceptKey, ChatModerationKindKey::MESSAGE_PICTURE);
                        if ($previousPicture !== null && $previousPicture->verdictTo === self::AGENT_TYPE
                            && $previousPicture->uploadHoldId !== null && $previousPicture->uploadHoldId !== $check->uploadHoldId) {
                            $this->watchMessageCheck($previousPicture, TimeHelper::nowMs());
                            $this->forgetExactMessageCheck($previousPicture, $check->uploadHoldId);
                        }
                    }
                    $check->actions->forget();
                    $next = Hilos::$rt->hilosModerationChecks->actions->open(
                        $check->acceptKey,
                        $check->userId,
                        ChatModerationKindKey::MESSAGE_PICTURE,
                        clientUploadId: $snapshot->clientUploadId,
                        deadlineAt: $check->deadlineAt,
                        imageCopy: true,
                        uploadSnapshots: $check->uploadSnapshots,
                        uploadHoldId: $check->uploadHoldId,
                    );
                    Database::afterCommit(fn () => $this->watchMessageCheck($next, TimeHelper::nowMs()));
                });
                return;
            }
        }
        if ($check->deadlineAt <= TimeHelper::nowMs()) {
            throw new ValidationException('The message moderation deadline expired');
        }
        if ($this->messageAuthorUnavailable($check->userId)) {
            $this->failMessageModeration($check->acceptKey);
            $this->forgetExactMessageCheck($check);
            return;
        }
        $connection = Hilos::$rt->connections[$check->acceptKey];
        if ($connection->outboundModerationAttachments === []) {
            $this->persistModeratedMessage($connection, $check->userId);
            $this->forgetExactMessageCheck($check);
            return;
        }
        $holdId = $check->uploadHoldId ?? throw new LogicException('An approved message with attachments has no upload hold');
        $connection->actions->awaitOutboundPublication($holdId, $check->deadlineAt, $check->userId);
        $this->watchPublication($connection, $check->deadlineAt);
        $check->actions->forget();
        $this->settleMessageUploadHold($check->acceptKey, $holdId, UploadHoldOutcome::APPROVED);
        (Hilos::$files ?? throw new LogicException('The files door is not created'))->publishUploads(
            $check->acceptKey,
            ChatAttachmentUploadTarget::NAME,
            Hilos::$rt->connections[$check->acceptKey]->outboundModerationAttachments,
            ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            $holdId,
        );
    }

    /**
     * @param string $acceptKey Connection whose accepted uploads must still be usable
     * @param ?ModerationCheck $check Original identities and positive owner marks required after admission
     * @throws ValidationException When an upload is unavailable, foreign, replaced or no longer held for this attempt
     * @throws HilosException When runtime data cannot be read
     */
    private function assertMessageUploadsReady(string $acceptKey, ?ModerationCheck $check = null): void
    {
        if (Hilos::$rt->hilosUploads->staleSince() !== null || Hilos::$rt->hilosModerationChecks->staleSince() !== null) {
            throw new ValidationException('Message moderation sources are unavailable');
        }
        foreach (Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments as $uploadId) {
            $upload = Hilos::$rt->hilosUploads->find($acceptKey, $uploadId);
            if (
                $upload === null || $upload->staleSince() !== null || $upload->phase !== UploadPhase::COMPLETE
                || $upload->target !== ChatAttachmentUploadTarget::NAME
                || $upload->userId !== Hilos::$rt->connections[$acceptKey]->userId
            ) {
                throw new ValidationException('An attachment is no longer ready for moderation');
            }
        }
        if ($check === null) {
            return;
        }
        $snapshotIds = [];
        foreach ($check->uploadSnapshots as $snapshot) {
            $snapshotIds[] = $snapshot->clientUploadId;
            $upload = Hilos::$rt->hilosUploads->find($acceptKey, $snapshot->clientUploadId);
            if (
                $check->uploadHoldId === null || $upload === null || $upload->tmpIndex !== $snapshot->tmpIndex
                || $upload->contentHash !== $snapshot->contentHash || $upload->uploadHoldId !== $check->uploadHoldId
                || $upload->uploadHoldDeadlineAt !== $check->deadlineAt || $check->deadlineAt <= TimeHelper::nowMs()
            ) {
                throw new ValidationException('An attachment is no longer held for this moderation attempt');
            }
        }
        if ($snapshotIds !== Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments) {
            throw new ValidationException('The attachment group no longer matches this moderation attempt');
        }
    }

    /**
     * @param string $acceptKey Connection returning its retained draft to the composer
     * @throws HilosException When runtime state cannot be written
     */
    private function failMessageModeration(string $acceptKey): void
    {
        Hilos::$rt->connections[$acceptKey]?->actions->failOutboundModeration(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            ModerationReason::SERVICE_UNAVAILABLE,
        );
    }

    /**
     * Chat runs each handler to completion: earlier messages enter its merge or erasure part.
     * A committed merge tombstone or started erasure closes late publication before sign-out or DB sync.
     * Preparation alone stays open; the guard also covers the current merge path.
     *
     * @param int $userId Person attempting to publish a message
     * @return bool Whether the database records a merge or started erasure for the author
     * @throws HilosException When the merge fact or standing erasure cannot be read
     */
    private function messageAuthorUnavailable(int $userId): bool
    {
        return Hilos::$db->userMerges->isMergedInDatabase($userId)
            || Hilos::$db->accountDeletions->startedOf($userId) !== null;
    }

    /**
     * @param string $acceptKey Closed connection or connection whose author changed
     * @param ?int $userId Former author, or null when the entire socket closed
     * @param ?string $publicationHoldId Accepted hold already released by the caller
     * @throws HilosException When a currently owned check cannot be read or removed
     */
    private function forgetMessageChecks(string $acceptKey, ?int $userId = null, ?string $publicationHoldId = null): void
    {
        if (!Hilos::hasFeature(HilosFeature::MODERATION)
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)) {
            return;
        }
        $failure = null;
        foreach (self::MESSAGE_CHECK_KINDS as $kind) {
            $check = Hilos::$rt->hilosModerationChecks->find($acceptKey, $kind);
            if ($check === null || $check->verdictTo !== self::AGENT_TYPE || ($userId !== null && $check->userId !== $userId)) {
                continue;
            }
            $this->watchMessageCheck($check, TimeHelper::nowMs());
            try {
                $this->forgetExactMessageCheck($check, $publicationHoldId);
            } catch (HilosException $refusal) {
                $failure ??= $refusal;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Cleans up only the captured check. The accepted publication, or another current stage
     * with the same hold, continues to own that hold.
     *
     * @param ModerationCheck $check Check whose work ended
     * @param ?string $publicationHoldId Accepted hold to leave to publication
     * @throws HilosException When the check cannot be read, removed or settled
     */
    private function forgetExactMessageCheck(ModerationCheck $check, ?string $publicationHoldId = null): void
    {
        $current = Hilos::$rt->hilosModerationChecks->find($check->acceptKey, $check->kind);
        $holdId = $check->uploadHoldId;
        if ($current?->requestId === $check->requestId) {
            if ($current->staleSince() !== null || $current->verdictTo !== self::AGENT_TYPE) {
                return;
            }
            $current->actions->forget();
        }
        $candidate = $this->deadlineOfCheck($check);
        Database::afterCommit(fn () => $this->forgetScheduledModeration($candidate));
        if ($holdId === null || $holdId === $publicationHoldId
            || ($current !== null && $current->requestId !== $check->requestId && $current->uploadHoldId === $holdId)) {
            return;
        }
        $otherKind = $check->kind === ChatModerationKindKey::MESSAGE ? ChatModerationKindKey::MESSAGE_PICTURE : ChatModerationKindKey::MESSAGE;
        if (Hilos::$rt->hilosModerationChecks->find($check->acceptKey, $otherKind)?->uploadHoldId !== $holdId) {
            Database::afterCommit(fn () => $this->settleMessageUploadHold($check->acceptKey, $holdId, UploadHoldOutcome::RELEASED));
        }
    }

    /**
     * @param ?Connection $connection Row read for an irreversible decision, when present
     * @return bool Whether the worker lacks a fresh fact about this connection
     */
    private function composerUnknown(?Connection $connection): bool
    {
        return !SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::connections)
            || ($connection === null ? RtStaleness::staleRows(ChatRtContext::connections) !== [] : $connection->staleSince() !== null);
    }

    /**
     * @param ModerationCheck $check Check to compare to the draft
     * @param Connection $connection Its fresh connection
     * @return bool Whether this check still carries the draft's input and phase
     * @throws HilosException When either runtime row cannot be read
     */
    private function checkMatchesDraft(ModerationCheck $check, Connection $connection): bool
    {
        if ($connection->isPublicationClearOwed()
            || $connection->outboundModerationPhase !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
            || $connection->outboundPublicationHoldId !== null) {
            return false;
        }
        if ($check->kind === ChatModerationKindKey::MESSAGE) {
            return $check->text === $connection->outboundModerationMessage
                && $check->clientUploadIds === $connection->outboundModerationAttachments;
        }
        $ids = [];
        foreach ($check->uploadSnapshots as $snapshot) {
            $ids[] = $snapshot->clientUploadId;
        }
        return $check->imageCopy && $check->clientUploadId !== null && in_array($check->clientUploadId, $ids, true)
            && $ids === $connection->outboundModerationAttachments;
    }

    /**
     * @param ModerationCheck $check Exact row already selected by kind and request id
     * @param Connection $connection Its fresh, live connection
     * @return bool Whether the check can advance this composer
     * @throws HilosException When the current draft or competing stage cannot be read
     */
    private function isCurrentMessageCheck(ModerationCheck $check, Connection $connection): bool
    {
        if ($connection->userId !== $check->userId || !$this->checkMatchesDraft($check, $connection)) {
            return false;
        }
        if ($check->kind === ChatModerationKindKey::MESSAGE) {
            return true;
        }
        $text = Hilos::$rt->hilosModerationChecks->find($check->acceptKey, ChatModerationKindKey::MESSAGE);
        return $text === null || $text->userId !== $check->userId || !$this->checkMatchesDraft($text, $connection);
    }

    /**
     * @param ModerationCheck $check Exact current check
     * @param int $now Comparison moment in epoch milliseconds
     * @return bool Whether an authoritative upload fact ended this check
     * @throws HilosException When the upload sources cannot be read
     */
    private function checkLostAnUpload(ModerationCheck $check, int $now): bool
    {
        if (RtStaleness::staleRows(HilosUpload::RT_COLLECTION) !== []) {
            return false;
        }
        foreach ($check->uploadSnapshots as $snapshot) {
            $upload = Hilos::$rt->hilosUploads->find($check->acceptKey, $snapshot->clientUploadId);
            if ($upload === null || $upload->phase !== UploadPhase::COMPLETE || $upload->tmpIndex !== $snapshot->tmpIndex
                || $upload->contentHash !== $snapshot->contentHash
                || $upload->target !== ChatAttachmentUploadTarget::NAME || $upload->userId !== $check->userId
                || ($upload->uploadHoldId === $check->uploadHoldId
                    && ($upload->uploadHoldDeadlineAt !== $check->deadlineAt || $check->deadlineAt <= $now))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Connection $connection Exact live accepted publication
     * @param string $reason Safe reason to return to the author
     * @throws HilosException When the terminal phase or hold settlement cannot be written
     */
    private function endPublication(Connection $connection, string $reason): void
    {
        $publication = $this->deadlineOfPublication($connection);
        $connection->actions->failOutboundModeration(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE, $reason);
        $this->forgetScheduledModeration($publication);
        $this->settleMessageUploadHold($connection->acceptKey, $publication?->token, UploadHoldOutcome::RELEASED);
    }

    /**
     * Ends the old draft when its author has lost the socket's session.
     *
     * @param Connection $connection Exact connection owner row being invalidated
     * @throws HilosException When the clear or release cannot be queued
     */
    private function retireMessageComposer(Connection $connection): void
    {
        $holdId = $this->invalidateMessageComposer($connection);
        $this->settleMessageUploadHold($connection->acceptKey, $holdId, UploadHoldOutcome::RELEASED);
    }

    /**
     * Records the mandatory clear and returns the old hold for release after invalidation.
     *
     * @param Connection $connection Exact connection owner row being invalidated
     * @return ?string Hold of the retired publication, if any
     * @throws HilosException When the terminal update cannot be prepared
     */
    private function invalidateMessageComposer(Connection $connection): ?string
    {
        if ($connection->isPublicationClearOwed()
            || $connection->outboundModerationPhase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE) {
            return null;
        }
        $publication = $this->deadlineOfPublication($connection);
        $holdId = $connection->outboundPublicationHoldId;
        $connection->actions->clearOutboundModeration(mandatory: true);
        $this->forgetScheduledModeration($publication);

        return $holdId;
    }

    /**
     * The check requests admission; only the uploads owner writes its marks. Approval is queued
     * before the ordinary publish request on the same FIFO channel.
     *
     * @param string $acceptKey Connection that admitted the attempt
     * @param ?string $uploadHoldId First check's token, or null for text without files
     * @param UploadHoldOutcome $outcome Terminal consumer outcome
     * @throws HilosException When a settlement cannot be constructed or queued
     */
    private function settleMessageUploadHold(string $acceptKey, ?string $uploadHoldId, UploadHoldOutcome $outcome): void
    {
        if ($uploadHoldId !== null) {
            $this->sendToAgent(
                HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE,
                new UploadHoldSettleSignalData($acceptKey, $uploadHoldId, $outcome),
            );
        }
    }

    /**
     * An upload event is a wake-up. The current check or publication and the live upload
     * owner facts determine what this connection is still waiting for.
     *
     * @param string $acceptKey Connection whose upload facts changed
     * @throws HilosException When runtime reads, cleanup or settlement fail
     */
    private function reconcileMessageUploads(string $acceptKey): void
    {
        if (!SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::connections)) {
            return;
        }
        $connection = Hilos::$rt->connections[$acceptKey];
        if ($connection === null || $this->composerUnknown($connection) || $connection->isUnregisterOwed()
            || $connection->isPublicationClearOwed()) {
            return;
        }

        $now = TimeHelper::nowMs();
        $publication = $this->deadlineOfPublication($connection);
        $unfinishedHandover = false;
        foreach (self::MESSAGE_CHECK_KINDS as $kind) {
            $check = Hilos::$rt->hilosModerationChecks->find($acceptKey, $kind);
            if ($check === null || $check->verdictTo !== self::AGENT_TYPE) {
                continue;
            }
            $this->watchMessageCheck($check, $now);
            if ($publication !== null && $check->userId === $publication->userId && $check->uploadHoldId === $publication->token) {
                $unfinishedHandover = true;
            }
            if ($check->staleSince() === null && $this->isCurrentMessageCheck($check, $connection)
                && ($check->deadlineAt <= $now || $this->checkLostAnUpload($check, $now))) {
                $this->failMessageModeration($acceptKey);
                $this->forgetExactMessageCheck($check);
                return;
            }
        }

        if ($publication === null || $publication->userId !== $connection->userId || $unfinishedHandover
            || Hilos::$rt->hilosUploads->staleSince() !== null) {
            return;
        }
        foreach ($connection->outboundModerationAttachments as $uploadId) {
            $upload = Hilos::$rt->hilosUploads->find($acceptKey, $uploadId);
            if ($upload !== null && $upload->target === ChatAttachmentUploadTarget::NAME
                && $upload->userId === $publication->userId && $upload->uploadHoldId === $publication->token
                && ($upload->uploadHoldDeadlineAt === null || $upload->uploadHoldDeadlineAt <= $now)) {
                $this->watchPublication($connection, $now);
                $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
                return;
            }
        }
    }

    /**
     * Upload rows have already been handed to the registry. Its ordered answer and the current
     * file owners supply provenance; a stale RT source supplies neither permission nor absence.
     *
     * @param FilesPublishedSignalData $published Registry answer for an approved submission
     * @throws HilosException When runtime, registry requests or event persistence fail
     */
    private function handleAttachmentsPublished(FilesPublishedSignalData $published): void
    {
        if ($published->uploadHoldId === null
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::connections)
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION)) {
            return;
        }
        $connection = Hilos::$rt->connections[$published->acceptKey];
        if ($this->composerUnknown($connection)) {
            return;
        }
        $publication = $connection === null ? null : $this->deadlineOfPublication($connection);
        if (
            $publication === null || $connection->isUnregisterOwed() || $publication->userId !== $connection->userId
            || $connection->outboundModerationAttachments !== $published->clientUploadIds
            || $publication->token !== $published->uploadHoldId
        ) {
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            return;
        }
        if ($connection->isPublicationClearOwed()) {
            $this->removeUnlinkedPublicationFiles($published->fileIds, $publication->userId);
            return;
        }
        if ($connection->sessionId === null || !Hilos::$db->sessions->matchesStoredIdentity($connection->sessionId, $publication->userId)) {
            try {
                $this->retireMessageComposer($connection);
            } finally {
                $this->removeUnlinkedPublicationFiles($published->fileIds, $publication->userId);
            }
            return;
        }
        $now = TimeHelper::nowMs();
        $this->watchPublication($connection, $publication->deadlineAt);
        if ($publication->deadlineAt <= $now) {
            try {
                $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
            } finally {
                $this->removeUnlinkedPublicationFiles($published->fileIds, $publication->userId);
            }
            return;
        }
        foreach (self::MESSAGE_CHECK_KINDS as $kind) {
            $checkId = HilosModerationCheck::keyFor($published->acceptKey, $kind);
            $check = Hilos::$rt->hilosModerationChecks[$checkId];
            if ($check === null && RtStaleness::staleSince(HilosModerationCheck::RT_COLLECTION, $checkId) !== null) {
                return;
            }
            if ($check !== null && $check->userId === $publication->userId && $check->uploadHoldId === $publication->token) {
                $this->watchMessageCheck($check, $now);
                return;
            }
        }
        if ($published->error !== null) {
            $this->endPublication($connection, $published->error);
            return;
        }
        $files = Hilos::$files ?? throw new LogicException('The files door is not created');
        if (count($published->fileIds) !== count($published->clientUploadIds) || $published->fileIds === []) {
            $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            return;
        }
        foreach ($published->fileIds as $fileId) {
            if (!isset(Hilos::$db->files[$fileId])) {
                $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
                $this->removeUnlinkedPublicationFiles($published->fileIds);
                return;
            }
            if (
                Hilos::$db->files[$fileId]->ownerUserId !== $publication->userId
                || Hilos::$db->eventAttachments->forFileId($fileId) !== null
            ) {
                $this->removeUnlinkedPublicationFiles($published->fileIds, $publication->userId);
                return;
            }
        }
        if (Hilos::$rt->hilosUploads->staleSince() !== null) {
            $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            return;
        }
        if ($this->messageAuthorUnavailable($publication->userId)) {
            $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            return;
        }
        try {
            $this->persistModeratedMessage($connection, $publication->userId, $published->fileIds);
        } catch (MessageAuthorChangedException) {
            try {
                $this->retireMessageComposer($connection);
            } finally {
                $this->removeUnlinkedPublicationFiles($published->fileIds, $publication->userId);
            }
            return;
        } catch (AnnouncementFailedException $failure) {
            // Outside a worker handler the commit reports this here; the message still stands.
            $this->forgetScheduledModeration($publication);
            $files->markBound($published->fileIds);
            throw $failure;
        } catch (WiringRefusal $failure) {
            $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            throw $failure;
        } catch (Throwable $failure) {
            $this->endPublication($connection, ModerationReason::SERVICE_UNAVAILABLE);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            $this->logAgentError('Cannot persist published message: ' . $failure->getMessage());
            return;
        }
        $this->forgetScheduledModeration($publication);
        $files->markBound($published->fileIds);
    }

    /**
     * Admits both moderated writes against the current session author in the event transaction.
     *
     * @param Connection $connection Fresh composer already matched to the check or publication
     * @param int $userId Author of the admitted draft
     * @param list<int> $fileIds Published files in the original attachment order
     * @throws MessageAuthorChangedException When the session disappeared or belongs to a different author
     * @throws HilosException When the author lock, event or composer write fails
     * @throws LogicException When the composer has no message
     */
    private function persistModeratedMessage(Connection $connection, int $userId, array $fileIds = []): void
    {
        $sessionId = $connection->sessionId;
        $message = $connection->outboundModerationMessage
            ?? throw new LogicException('A message under moderation carries no text');

        Database::transaction(DatabaseNames::PRIMARY, static function () use ($connection, $sessionId, $userId, $message, $fileIds): void {
            if ($sessionId === null || !Hilos::$db->sessions->lockStoredAuthor($sessionId, $userId)) {
                throw new MessageAuthorChangedException('The stored session no longer authorizes this message');
            }
            Hilos::$db->events->actions->addMessage($message, userId: $userId, fileIds: $fileIds);
            $connection->actions->clearOutboundModeration();
        });
    }

    /**
     * Duplicate replies may name files whose event already committed. Cleanup never removes those.
     *
     * @param list<int> $fileIds Published registry ids without a current consumer
     * @param ?int $ownerUserId Expected owner when checking a reply to the current attempt
     * @throws HilosException When collection wiring refuses the read
     */
    private function removeUnlinkedPublicationFiles(array $fileIds, ?int $ownerUserId = null): void
    {
        try {
            $orphans = [];
            foreach ($fileIds as $fileId) {
                if (isset(Hilos::$db->files[$fileId]) && !Hilos::$db->files[$fileId]->bound
                    && ($ownerUserId === null || Hilos::$db->files[$fileId]->ownerUserId === $ownerUserId)
                    && Hilos::$db->eventAttachments->forFileId($fileId) === null) {
                    $orphans[] = $fileId;
                }
            }
            (Hilos::$files ?? throw new LogicException('The files door is not created'))->remove($orphans);
        } catch (WiringRefusal $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            $this->logAgentError('Cannot remove orphan publication files: ' . $failure->getMessage());
        }
    }

    /**
     * Notifies the author that moderation refused to publish their message.
     *
     * Only a verdict about the content notifies: an unavailable moderator, or a picture
     * that cannot be checked, is not something to tell the author about. The
     * emit is best-effort: the composer already retains the refusal.
     *
     * @param ?int $userId Author user id, or null when the connection carries none
     * @param string $message Rejected message text, kept so the author knows which one
     * @param string $reason Public moderation reason
     */
    private function notifyMessageRejected(?int $userId, string $message, string $reason): void
    {
        if ($userId === null) {
            return;
        }

        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: ChatNotificationType::MESSAGE_REJECTED,
                title: 'Your message was not published',
                severity: NotificationSeverity::WARNING,
                body: $reason,
                data: [
                    'reason' => $reason,
                    'message' => $message,
                ],
            ));
        } catch (HilosException $e) {
            $this->logAgentError(
                "Message rejection notification failed for userId={$userId}: {$e->getMessage()}",
            );
        }
    }

    /**
     * Publishes a generated bot message to the chat event stream.
     *
     * @param BotMessageSignalData $message Bot id and generated message body
     * @throws HilosException On bot message persistence failure
     * @throws LogicException If event id is null after sync
     */
    private function handleBotMessage(BotMessageSignalData $message): void
    {
        Hilos::$db->events->actions->addMessage($message->message, botId: $message->botId);
    }
}
