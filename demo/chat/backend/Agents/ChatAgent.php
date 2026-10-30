<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\CLI\DTO\SubmissionObserveCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Browser\OutboundModerationBrowserPayload;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Moderation\ChatModerationRefusal;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
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
use Hilos\Runtime\View\Item\HilosModerationCheck as ModerationCheck;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\WiringRefusal;
use Random\RandomException;
use Throwable;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use LogicException as NativeLogicException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\RelayedActionReplyDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\HilosException;
use Hilos\Runtime\View\Collection\HilosSessionConnections;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

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
 */
final class ChatAgent extends AbstractAgent
{
    /**
     * The room and everything written into it: the events, their messages, who joined, and what
     * was attached - and the links of the rename journal to the room's events, which clearing
     * the room's history takes off.
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
        // Borrowed to sweep room references from the rename journal while clearing the room's history.
        // See docs/agents/architecture/instance-owners.md#operations-over-many-instances.
        ChatDbContext::userRenames => [TruthSourceOperation::Update],
        ChatDbContext::eventAttachments => TruthSourceOperation::BY_KIND,
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

    /** @var list<string> Check timeout settings and the registry rows being linked */
    public const array READS_DB = [HilosDbContext::settings, HilosDbContext::files];

    public const string AGENT_TYPE = AgentType::CHAT;

    public const array AGENT_COMMANDS = [
        ChatCommandConstants::SUBMISSION_TEST_OBSERVE => SubmissionObserveCommandData::class,
    ];

    // The library frame is declared HERE and not in the library, which is what routes it to
    // this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself (HIL-710).
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
        HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
        ChatSignalConstants::ATTACHMENTS_PUBLISHED => FilesPublishedSignalData::class,
    ];

    private const string IMAGE_MIME_PREFIX = 'image/';

    /** @var int Next local sweep moment in epoch milliseconds; the checks themselves live in RT */
    private int $nextModerationSweepAt = 0;

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
        if ($data->command !== ChatCommandConstants::SUBMISSION_TEST_OBSERVE) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

            return;
        }
        if (!NonProductionGate::admitted()) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Submission observation is test-only'));

            return;
        }
        $this->observeSubmission($data, $data->parsedPayload);
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
            || Hilos::$rt->connections[$acceptKey]->userId === null
            || Hilos::$rt->connections[$acceptKey]->outboundModerationPhase
                !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            return;
        }

        try {
            $this->assertMessageUploadsReady($acceptKey);
            Hilos::$rt->hilosModerationChecks->actions->open(
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
        } catch (WiringRefusal $failure) {
            $this->failMessageModeration($acceptKey);
            throw $failure;
        } catch (Throwable $failure) {
            $this->forgetMessageChecks($acceptKey);
            $this->failMessageModeration($acceptKey);
            $this->logAgentError('Cannot start message moderation: ' . $failure->getMessage());
        }
    }

    /**
     * Checks outlive the worker; only this process's sweep cadence stays on the instance.
     *
     * @throws HilosException When runtime cleanup fails
     */
    public function onTick(): void
    {
        $now = TimeHelper::nowMs();
        if ($now < $this->nextModerationSweepAt) {
            return;
        }
        $this->nextModerationSweepAt = $now + TimeConstants::MS_PER_SECOND;
        foreach (Hilos::$rt->hilosModerationChecks as $check) {
            if ($check->verdictTo !== self::AGENT_TYPE || $check->staleSince() !== null) {
                continue;
            }
            if (Hilos::$rt->connections->staleSince() !== null) {
                continue;
            }
            if (Hilos::$rt->connections[$check->acceptKey] === null) {
                $this->forgetMessageChecks($check->acceptKey);
                continue;
            }
            if (Hilos::$rt->connections[$check->acceptKey]->staleSince() !== null) {
                continue;
            }
            if (Hilos::$rt->connections[$check->acceptKey]->userId !== $check->userId) {
                $this->forgetMessageChecks($check->acceptKey);
                Hilos::$rt->connections[$check->acceptKey]->actions->clearOutboundModeration();
            } elseif ($check->deadlineAt <= $now) {
                $this->failMessageModeration($check->acceptKey);
                $this->forgetMessageChecks($check->acceptKey);
            } else {
                $this->reconcileMessageUploads($check->acceptKey);
            }
        }
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
        if ($data->collectionKey === HilosUpload::RT_COLLECTION) {
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
            $this->reconcileMessageUploads($connection->acceptKey);
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
        $this->forgetMessageChecks($data->acceptKey);
        Hilos::$rt->connections[$data->acceptKey]?->actions->unregister();
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
        Hilos::$db->events->actions->addChatStopped();
        Hilos::$rt->userStates->actions->clear();
    }

    /**
     * Handles chat-owned cron cleanup of the persisted history and the files its messages carried.
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
                $fileIds = Hilos::$db->eventAttachments->allFileIds();
                Hilos::$db->events->actions->deleteAll();
                Hilos::$db->events->actions->addChatCleared();
                (Hilos::$files ?? throw new LogicException('The files door is not created'))->remove($fileIds);

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
     * @throws InvalidArgumentException When a signal of the session-state answer cannot be named
     * @throws HilosException On bot message publish failure, or on database or runtime failure
     * @throws LogicException On payload type mismatch, or if event id is null after sync
     * @throws NativeLogicException When the runtime item factory rejects a state row
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_MODERATION_VERDICT:
                if (!$data->data instanceof ModerationVerdictSignalData) {
                    throw new LogicException('Moderation verdict payload must be ' . ModerationVerdictSignalData::class);
                }
                $this->handleMessageModerationVerdict($data->data);
                return;
            case ChatSignalConstants::ATTACHMENTS_PUBLISHED:
                if (!$data->data instanceof FilesPublishedSignalData) {
                    throw new LogicException('Publication payload must be ' . FilesPublishedSignalData::class);
                }
                $this->handleAttachmentsPublished($data->data);
                return;
            case HilosSignalConstants::HILOS_SESSION_STATE:
                if (!$data->data instanceof SessionStateSignalData) {
                    throw new LogicException(
                        HilosSignalConstants::HILOS_SESSION_STATE . ' payload must be '
                        . SessionStateSignalData::class,
                    );
                }
                $this->applySessionState($data->data);
                return;
            case ChatSignalConstants::BOT_MESSAGE:
                if (!$data->data instanceof BotMessageSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::BOT_MESSAGE . ' payload must be ' . BotMessageSignalData::class,
                    );
                }
                $this->handleBotMessage($data->data);
                return;
            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Says out loud what the sessions library concluded about one session (HIL-710).
     *
     * The project half of the seam, and the ONE handler behind every ending a session can
     * reach: a handshake, a sign-in, a sign-out, an impersonation, an ack raised or
     * dismissed. It replaces what used to be four hooks of the session-host trait
     * (`onSignalHandshake`, `bindConnectionUser`, `markConnectionAck` and the response signal
     * name), and it can be one handler precisely because the library states the session's
     * whole state rather than the change it made.
     *
     * THE ORDER IS THE MECHANISM, not a sequence of chores:
     * 1. the connection rows are written first, because everything below is read against
     *    them - the page re-decision judges "this connection belongs to N", and a browser
     *    told who it is before the row said so would be judged as the person it no longer is;
     * 2. every socket of the session is then handed the identity, stamped by the framework
     *    with the clock and the registration step the frame carried - the ones the frame names
     *    and the ones only this register knows of yet
     *    ({@see HilosSessionConnections::acceptKeysForSessionFrame()});
     * 3. a rotation ticket goes to the one socket that earned it, AFTER that identity, so the
     *    browser reconnects knowing who it already is;
     * 4. the pages are re-asked their access question once, not once per socket;
     * 5. the action that was waiting is answered LAST - behind the identity it announces,
     *    which is the rule the sign-in commands were split along (HIL-622).
     *
     * @param SessionStateSignalData $frame What the session is now, and whom to answer
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws HilosException On database or runtime failure
     */
    private function applySessionState(SessionStateSignalData $frame): void
    {
        $userId = $frame->userId;
        if ($userId !== null) {
            Hilos::$rt->userStates->actions->ensure($userId);
        }

        // One identity for the whole frame: every socket it reaches belongs to the one session
        // it is about, so the user, the name and the administrator behind them are the same
        // for all of them.
        $identity = $this->handshakeIdentity($frame);

        foreach (
            Hilos::$rt->connections->acceptKeysForSessionFrame($frame->acceptKeys, $frame->sessionToken)
            as $acceptKey
        ) {
            $this->settleConnection($acceptKey, $frame);
            $this->sendHandshakeResponse(ChatSignalConstants::HANDSHAKE_RESPONSE, $acceptKey, $identity, $frame);
        }

        $this->handOverRotationTicket($frame);

        // Whatever page these browsers had open was answered for whoever they were a moment
        // ago (HIL-652, HIL-627). A sign-in can ask by user, because the identity has just
        // appeared; a sign-out has to ask by connection, because the identity it would ask
        // under is the one being erased.
        if ($userId !== null) {
            PageAccessReassessment::forUser($userId);
        } else {
            PageAccessReassessment::forConnections($frame->acceptKeys);
        }

        $this->answerLibraryAction($frame);
    }

    /**
     * Brings one live connection row to the state the frame states.
     *
     * A socket the collection does not hold yet is a HANDSHAKE and nothing else: every other
     * frame names sockets the library read out of this very collection. So the row is opened
     * here, and the analytics identity is attached here, exactly where the handshake handler
     * used to do both.
     *
     * Each write happens only where it changes something. That is not thrift but fidelity: a
     * row written is a row synced to every reader of it, and a frame that merely restates an
     * unchanged session - an ack dismissed, an action answered - has no business telling the
     * rest of the node that the connections moved.
     *
     * @param string $acceptKey Accept key of the connection being brought up to date
     * @param SessionStateSignalData $frame What the session is now
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the queued runtime-sync signal cannot be named
     */
    private function settleConnection(string $acceptKey, SessionStateSignalData $frame): void
    {
        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null) {
            Hilos::$rt->connections->actions->register(
                $acceptKey,
                $frame->userId,
                $frame->sessionToken,
                $frame->sessionId,
            );
            if ($frame->userId !== null) {
                Hilos::$ac?->identifyBrowserSessionUser($frame->sessionToken, $frame->userId);
            }
        } else {
            if ($connection->sessionToken !== $frame->sessionToken || $connection->sessionId !== $frame->sessionId) {
                // The session did not change, its secret name did: a login rotated the token
                // out from under a value somebody may have planted (HIL-582).
                Hilos::$rt->connections->actions->repointSessionToken(
                    $acceptKey,
                    $frame->sessionToken,
                    $frame->sessionId,
                );
            }
            if ($connection->userId !== $frame->userId) {
                $this->forgetMessageChecks($acceptKey);
                $connection->actions->clearOutboundModeration();
                $connection->actions->bindUser($frame->userId);
            }
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
     * Applies only the fresh check for the exact current attempt and unchanged composer.
     *
     * @param ModerationVerdictSignalData $verdict Typed common-engine verdict
     * @throws HilosException When runtime writes or persistence fail
     */
    private function handleMessageModerationVerdict(ModerationVerdictSignalData $verdict): void
    {
        if (!in_array($verdict->kind, [ChatModerationKindKey::MESSAGE, ChatModerationKindKey::MESSAGE_PICTURE], true)) {
            return;
        }
        $check = Hilos::$rt->hilosModerationChecks->find($verdict->acceptKey, $verdict->kind);
        if (
            $check === null || $check->staleSince() !== null || $check->verdictTo !== self::AGENT_TYPE
            || $check->requestId !== $verdict->requestId
            || Hilos::$rt->connections->staleSince() !== null
            || Hilos::$rt->connections[$verdict->acceptKey] === null
            || Hilos::$rt->connections[$verdict->acceptKey]->staleSince() !== null
            || Hilos::$rt->connections[$verdict->acceptKey]->userId !== $check->userId
            || Hilos::$rt->connections[$verdict->acceptKey]->outboundModerationPhase
                !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            return;
        }
        if ($check->kind === ChatModerationKindKey::MESSAGE) {
            if (
                $check->text !== Hilos::$rt->connections[$verdict->acceptKey]->outboundModerationMessage
                || $check->clientUploadIds !== Hilos::$rt->connections[$verdict->acceptKey]->outboundModerationAttachments
            ) {
                return;
            }
        } elseif (
            !$check->imageCopy || $check->clientUploadId === null
            || !in_array($check->clientUploadId, Hilos::$rt->connections[$verdict->acceptKey]->outboundModerationAttachments, true)
        ) {
            return;
        }
        if ($check->deadlineAt <= TimeHelper::nowMs()) {
            $this->failMessageModeration($verdict->acceptKey);
            $this->forgetMessageChecks($verdict->acceptKey);
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
            $this->forgetMessageChecks($verdict->acceptKey);
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
        } catch (WiringRefusal $failure) {
            $this->forgetMessageChecks($check->acceptKey, $check);
            $this->failMessageModeration($verdict->acceptKey);
            throw $failure;
        } catch (Throwable $failure) {
            $this->forgetMessageChecks($verdict->acceptKey, $check);
            $this->failMessageModeration($verdict->acceptKey);
            $this->logAgentError('Cannot continue message moderation: ' . $failure->getMessage());
        }
    }

    /**
     * No stage list is retained: the current image id and the held attachment order define the next stage.
     *
     * @param ModerationCheck $check Approved check, carrying the chain's fixed deadline
     * @throws HilosException When uploads cannot be used or the next check/publication cannot start
     * @throws RandomException When a fresh check id cannot be drawn
     * @throws NativeLogicException When the check row cannot be constructed
     */
    private function advanceMessageModeration(ModerationCheck $check): void
    {
        $this->assertMessageUploadsReady($check->acceptKey, $check);
        $check->actions->forget();
        $afterCurrent = $check->kind === ChatModerationKindKey::MESSAGE;
        foreach ($check->uploadSnapshots as $snapshot) {
            if (!$afterCurrent) {
                $afterCurrent = $snapshot->clientUploadId === $check->clientUploadId;
                continue;
            }
            $upload = Hilos::$rt->hilosUploads->find($check->acceptKey, $snapshot->clientUploadId);
            if (str_starts_with(UploadMime::normalize($upload->detectedMimeType ?? $upload->mimeType), self::IMAGE_MIME_PREFIX)) {
                Hilos::$rt->hilosModerationChecks->actions->open(
                    $check->acceptKey,
                    $check->userId,
                    ChatModerationKindKey::MESSAGE_PICTURE,
                    clientUploadId: $snapshot->clientUploadId,
                    deadlineAt: $check->deadlineAt,
                    imageCopy: true,
                    uploadSnapshots: $check->uploadSnapshots,
                    uploadHoldId: $check->uploadHoldId,
                );
                return;
            }
        }
        if ($check->deadlineAt <= TimeHelper::nowMs()) {
            throw new ValidationException('The message moderation deadline expired');
        }
        if (Hilos::$rt->connections[$check->acceptKey]->outboundModerationAttachments === []) {
            $message = Hilos::$rt->connections[$check->acceptKey]->outboundModerationMessage;
            Hilos::$db->events->actions->addMessage($message, userId: $check->userId);
            Hilos::$rt->connections[$check->acceptKey]->actions->clearOutboundModeration();
            return;
        }
        $this->settleMessageUploadHold($check->acceptKey, $check->uploadHoldId, UploadHoldOutcome::APPROVED);
        (Hilos::$files ?? throw new LogicException('The files door is not created'))->publishUploads(
            $check->acceptKey,
            ChatAttachmentUploadTarget::NAME,
            Hilos::$rt->connections[$check->acceptKey]->outboundModerationAttachments,
            ChatSignalConstants::ATTACHMENTS_PUBLISHED,
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
     * @param string $acceptKey Connection whose chat checks ended
     * @param ?ModerationCheck $ended Check already removed while advancing to the next stage
     * @throws HilosException When runtime cleanup fails
     */
    private function forgetMessageChecks(string $acceptKey, ?ModerationCheck $ended = null): void
    {
        $tokens = $ended?->uploadHoldId === null ? [] : [$ended->uploadHoldId];
        foreach ([ChatModerationKindKey::MESSAGE, ChatModerationKindKey::MESSAGE_PICTURE] as $kind) {
            $check = Hilos::$rt->hilosModerationChecks->find($acceptKey, $kind);
            if ($check === null) {
                continue;
            }
            if ($check->uploadHoldId !== null) {
                $tokens[] = $check->uploadHoldId;
            }
        }
        if ($tokens === [] && Hilos::$rt->connections[$acceptKey] !== null) {
            foreach (Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments as $uploadId) {
                $upload = Hilos::$rt->hilosUploads->find($acceptKey, $uploadId);
                if ($upload !== null && $upload->staleSince() === null && $upload->target === ChatAttachmentUploadTarget::NAME
                    && $upload->userId === Hilos::$rt->connections[$acceptKey]->userId && $upload->uploadHoldId !== null
                ) {
                    $tokens[] = $upload->uploadHoldId;
                }
            }
        }
        foreach (array_unique($tokens) as $token) {
            $this->settleMessageUploadHold($acceptKey, $token, UploadHoldOutcome::RELEASED);
        }
        foreach ([ChatModerationKindKey::MESSAGE, ChatModerationKindKey::MESSAGE_PICTURE] as $kind) {
            Hilos::$rt->hilosModerationChecks->find($acceptKey, $kind)?->actions->forget();
        }
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
     * Unconfirmed initial marks may arrive separately. A known source loss or a released matching
     * token ends the wait; absent rows after the last check are the normal publication handover.
     *
     * @param string $acceptKey Connection whose current owner facts changed
     * @throws HilosException When runtime reads, cleanup or settlement fail
     */
    private function reconcileMessageUploads(string $acceptKey): void
    {
        if (Hilos::$rt->connections->staleSince() !== null || Hilos::$rt->hilosUploads->staleSince() !== null
            || Hilos::$rt->hilosModerationChecks->staleSince() !== null || Hilos::$rt->connections[$acceptKey] === null
            || Hilos::$rt->connections[$acceptKey]->staleSince() !== null
            || Hilos::$rt->connections[$acceptKey]->outboundModerationPhase
                !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            return;
        }
        $check = Hilos::$rt->hilosModerationChecks->find($acceptKey, ChatModerationKindKey::MESSAGE)
            ?? Hilos::$rt->hilosModerationChecks->find($acceptKey, ChatModerationKindKey::MESSAGE_PICTURE);
        if ($check !== null && ($check->staleSince() !== null || $check->userId !== Hilos::$rt->connections[$acceptKey]->userId)) {
            return;
        }
        if ($check !== null) {
            foreach ($check->uploadSnapshots as $snapshot) {
                $upload = Hilos::$rt->hilosUploads->find($acceptKey, $snapshot->clientUploadId);
                if ($upload === null || $upload->phase !== UploadPhase::COMPLETE
                    || $upload->tmpIndex !== $snapshot->tmpIndex || $upload->contentHash !== $snapshot->contentHash
                    || $upload->target !== ChatAttachmentUploadTarget::NAME || $upload->userId !== $check->userId
                    || ($upload->uploadHoldId === $check->uploadHoldId
                        && ($upload->uploadHoldDeadlineAt !== $check->deadlineAt || $check->deadlineAt <= TimeHelper::nowMs()))
                ) {
                    $this->failMessageModeration($acceptKey);
                    $this->forgetMessageChecks($acceptKey);
                    return;
                }
            }
            return;
        }
        foreach (Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments as $uploadId) {
            $upload = Hilos::$rt->hilosUploads->find($acceptKey, $uploadId);
            if ($upload !== null && $upload->target === ChatAttachmentUploadTarget::NAME
                && $upload->userId === Hilos::$rt->connections[$acceptKey]->userId && $upload->uploadHoldId !== null
                && ($upload->uploadHoldDeadlineAt === null || $upload->uploadHoldDeadlineAt <= TimeHelper::nowMs())
            ) {
                $this->failMessageModeration($acceptKey);
                $this->forgetMessageChecks($acceptKey);
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
        $files = Hilos::$files ?? throw new LogicException('The files door is not created');
        if (
            Hilos::$rt->connections[$published->acceptKey]?->staleSince() !== null
            || (Hilos::$rt->connections[$published->acceptKey] === null && Hilos::$rt->connections->staleSince() !== null)
        ) {
            return;
        }
        if (
            Hilos::$rt->connections[$published->acceptKey] === null
            || Hilos::$rt->connections[$published->acceptKey]->userId === null
            || Hilos::$rt->connections[$published->acceptKey]->outboundModerationPhase
                !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
            || Hilos::$rt->connections[$published->acceptKey]->outboundModerationAttachments !== $published->clientUploadIds
            || Hilos::$rt->hilosModerationChecks->find($published->acceptKey, ChatModerationKindKey::MESSAGE) !== null
            || Hilos::$rt->hilosModerationChecks->find($published->acceptKey, ChatModerationKindKey::MESSAGE_PICTURE) !== null
        ) {
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            return;
        }
        if ($published->error !== null) {
            Hilos::$rt->connections[$published->acceptKey]->actions->failOutboundModeration(
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                $published->error,
            );
            $this->forgetMessageChecks($published->acceptKey);
            return;
        }
        if (count($published->fileIds) !== count($published->clientUploadIds) || $published->fileIds === []) {
            $this->failMessageModeration($published->acceptKey);
            $this->forgetMessageChecks($published->acceptKey);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            return;
        }
        foreach ($published->fileIds as $fileId) {
            if (!isset(Hilos::$db->files[$fileId])) {
                $this->failMessageModeration($published->acceptKey);
                $this->removeUnlinkedPublicationFiles($published->fileIds);
                return;
            }
            if (
                Hilos::$db->files[$fileId]->ownerUserId !== Hilos::$rt->connections[$published->acceptKey]->userId
                || Hilos::$db->eventAttachments->forFileId($fileId) !== null
            ) {
                $this->removeUnlinkedPublicationFiles($published->fileIds);
                return;
            }
        }
        if (Hilos::$rt->hilosUploads->staleSince() !== null || Hilos::$rt->hilosModerationChecks->staleSince() !== null) {
            $this->failMessageModeration($published->acceptKey);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            return;
        }
        $message = Hilos::$rt->connections[$published->acceptKey]->outboundModerationMessage
            ?? throw new LogicException('A message under moderation carries no text');
        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($message, $published): void {
                Hilos::$db->events->actions->addMessage(
                    $message,
                    userId: Hilos::$rt->connections[$published->acceptKey]->userId,
                    fileIds: $published->fileIds,
                );
                Hilos::$rt->connections[$published->acceptKey]->actions->clearOutboundModeration();
            });
        } catch (AnnouncementFailedException $failure) {
            // Outside a worker handler the commit reports this here; the message still stands.
            $files->markBound($published->fileIds);
            throw $failure;
        } catch (WiringRefusal $failure) {
            $this->failMessageModeration($published->acceptKey);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            throw $failure;
        } catch (Throwable $failure) {
            $this->failMessageModeration($published->acceptKey);
            $this->removeUnlinkedPublicationFiles($published->fileIds);
            $this->logAgentError('Cannot persist published message: ' . $failure->getMessage());
            return;
        }
        $files->markBound($published->fileIds);
    }

    /**
     * Duplicate replies may name files whose event already committed. Cleanup never removes those.
     *
     * @param list<int> $fileIds Published registry ids without a current consumer
     * @throws HilosException When collection wiring refuses the read
     */
    private function removeUnlinkedPublicationFiles(array $fileIds): void
    {
        try {
            $orphans = [];
            foreach ($fileIds as $fileId) {
                if (Hilos::$db->eventAttachments->forFileId($fileId) === null) {
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
