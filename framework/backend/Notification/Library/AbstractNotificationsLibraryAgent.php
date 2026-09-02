<?php

declare(strict_types=1);

namespace Hilos\Notification\Library;

use DateTimeImmutable;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\NotificationDispatcher;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\DeliveryLogPruner;
use Hilos\Notification\DTO\DeliveryRetryDoneSignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\DTO\NotificationChannelPreferenceActionDTO;
use Hilos\Notification\DTO\NotificationCreatedSignalData;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\DTO\NotificationMarkAllReadPayloadDTO;
use Hilos\Notification\DTO\NotificationMarkReadPayloadDTO;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Notification\NotificationCommandConstants;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Notification\NotificationSeverity;
use Hilos\Notification\NotificationSignalName;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;
use Hilos\Push\DTO\PushSubscribeActionDTO;
use Hilos\Push\DTO\PushUnsubscribeActionDTO;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use Throwable;

/**
 * The notifications library: the one owner of the notification set and of everything written
 * about it (HIL-771).
 *
 * An entity library in the sense of docs/agents/architecture/entity-libraries.md, and the
 * third one in code after users (HIL-622) and sessions (HIL-710). What it owns is a SET - the
 * notification rows, the per-user channel preferences, the delivery journal and the browsers'
 * push endpoints - together with the ceremony that fills it: write the row, tell the
 * recipient's open tabs, hand the row to the channels.
 *
 * Before it existed the notification tables had no owner at all. {@see HilosNotifier} was
 * declared a seam "any process calls", which held only while the process that called it also
 * happened to host an agent claiming those tables - true by accident of placement, never by
 * design, and the defect this library closes. The emit seam is now a door: it sends
 * {@see HilosSignalConstants::HILOS_NOTIFICATION_EMIT} here and the write happens in one
 * place.
 *
 * It is NOT abstract by necessity, unlike {@see AbstractSessionsLibraryAgent}, whose
 * `ensureAdminUser()` needs a project's own user table: notifications have no project half at
 * all. It is abstract by convention alone - every Hilos agent is mounted through a concrete
 * class in the project's registry - so a subclass usually adds nothing but its name.
 *
 * WHAT IT DOES NOT OWN: the delivery journal outright. A channel agent
 * ({@see AbstractDeliveryChannelAgent}) edits the row of its own attempt, so the journal is
 * co-owned by operation - this library adds and removes rows, a channel updates them. That is
 * deliberate: the dispatcher was written so that delivery never queues behind a single owner,
 * and routing every attempt's bookkeeping through here would put the latency back.
 */
abstract class AbstractNotificationsLibraryAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;

    /**
     * The two frames this library is addressed by.
     *
     * Routing takes the destination from whoever declares a name here, so the first line IS
     * the move: an emit that used to be a write in the calling worker is now a frame that
     * arrives where the tables are owned.
     *
     * The second is the admin retry, and it arrives as a frame for a different reason: the
     * action stayed on {@see AbstractHilosCommunicationsDeliveriesPage}, because the ADMIN
     * level closing it lives on a page and an agent action has none. So that page keeps the
     * door and this library keeps the journal, and the retry crosses between them.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_NOTIFICATION_EMIT => NotificationEmitSignalData::class,
        HilosSignalConstants::HILOS_DELIVERY_RETRY => DeliveryRetrySignalData::class,
    ];

    /**
     * The page-independent controls of a person's own notifications, by wire name (HIL-771).
     *
     * Every one of them used to be a page action: the first two on the notification centre,
     * the last three on a project's profile page. What they have in common is what moved them
     * - each writes a notification table, and none of them needs the page it was declared on:
     * the recipient is resolved from the ACTING connection, never from the payload, so a
     * client can only ever mark, mute or subscribe for itself.
     *
     * The wire names are unchanged, which is what keeps the frontend out of this move
     * entirely: the router picks an action's destination by name, so a name declared here is
     * simply routed to this agent instead of to whichever page hosted it.
     */
    public const array AGENT_ACTIONS = [
        NotificationAction::MARK_READ => NotificationMarkReadPayloadDTO::class,
        NotificationAction::MARK_ALL_READ => NotificationMarkAllReadPayloadDTO::class,
        NotificationPreferenceAction::CHANNEL_SET => NotificationChannelPreferenceActionDTO::class,
        PushSubscriptionAction::SUBSCRIBE => PushSubscribeActionDTO::class,
        PushSubscriptionAction::UNSUBSCRIBE => PushUnsubscribeActionDTO::class,
    ];

    /**
     * All five, because all five are about somebody's own notifications.
     *
     * The pages they came off were closed by {@see PageAccessLevel::AUTHENTICATED}, which
     * gated their actions along with the subscription. An agent action carries no page level,
     * so without this list the whole set would have opened to a guest the moment it moved -
     * and a guest reaching them would not merely be refused deeper in, since each handler
     * resolves its user from the connection and an anonymous one has none.
     */
    public const array AUTH_ACTIONS = [
        NotificationAction::MARK_READ,
        NotificationAction::MARK_ALL_READ,
        NotificationPreferenceAction::CHANNEL_SET,
        PushSubscriptionAction::SUBSCRIBE,
        PushSubscriptionAction::UNSUBSCRIBE,
    ];

    /**
     * The test-only emit, mounted here because this is where an emit happens now (HIL-771).
     *
     * It stood on {@see AbstractHilosIndexAgent} for exactly one reason - there was no
     * notification-owned agent to put it on - and the reason is gone. The wire name and the
     * reply are unchanged, including the id: the caller is answered by the agent that wrote
     * the row, so it can still report which one it wrote.
     *
     * What keeps the emit off a production node is its `test:` prefix, which the command
     * socket reads through {@see TestOnlyCommandRegistry}: the socket authenticates nobody,
     * so the name has to say what the command may do.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::NOTIFICATION_TEST_EMIT,
    ];

    /** Cron expression for the daily delivery-log prune (03:20). */
    private const string DELIVERY_LOG_PRUNE_SCHEDULE = '20 3 * * *';

    /** Name of the cron rule that prunes terminal rows out of the delivery journal. */
    private const string DELIVERY_LOG_PRUNE_RULE = 'hilos_delivery_log_prune';

    /** @var ?CronRule Once-per-day guard for the delivery-log prune */
    private ?CronRule $deliveryLogPruneRule = null;

    /**
     * @param NotificationDispatcher $dispatcher Channel-delivery dispatcher this library fans through
     */
    public function __construct(
        private readonly NotificationDispatcher $dispatcher = new NotificationDispatcher(),
    ) {
    }

    /**
     * Claims the notification set and arms the journal prune.
     *
     * All four collections are claimed OUTRIGHT and unconditionally. Nothing may write a
     * framework collection until somebody says who owns it, so a project that never registers
     * this agent simply has no notifications - rather than notifications written in whichever
     * process happened to hold the owner of something else.
     *
     * The journal is the one claim that is not exclusive: a channel agent holds an
     * update-only grant on the same collection ({@see AbstractDeliveryChannelAgent::onStart()}),
     * which the registry allows because a grant is per agent and the two do not overlap in
     * what they may do.
     *
     * A subclass that overrides this MUST call up: the claims are what the library stands on.
     *
     * The last thing it does is send the letters written while it was not running (HIL-771): a
     * restore emits with the node frozen or the daemon down, and those drafts waited in
     * {@see DeferredNotificationQueue} for exactly this moment. Sent after the claims, because
     * sending one writes a row.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(HilosDbContext::notifications);
        $this->registerDbTruthSource(HilosDbContext::notificationPreferences);
        $this->registerDbTruthSource(HilosDbContext::notificationDeliveries);
        $this->registerDbTruthSource(HilosDbContext::pushSubscriptions);

        $this->deliveryLogPruneRule = new CronRule(self::DELIVERY_LOG_PRUNE_RULE, self::DELIVERY_LOG_PRUNE_SCHEDULE);

        $this->emitDeferred();
    }

    /**
     * Runs the due-once-a-day delivery-log prune.
     *
     * Moved here whole from {@see AbstractHilosIndexAgent} (HIL-771), schedule, idempotence
     * and skip-when-unconfigured included: what it does is delete rows from the journal, and
     * the journal is this library's.
     */
    public function onTick(): void
    {
        if ($this->deliveryLogPruneRule !== null && $this->deliveryLogPruneRule->shouldRun()) {
            $this->pruneDeliveryLog();
        }
    }

    /**
     * The library holds nothing across a stop: its state is the four collections above, which
     * outlive the process that owns them.
     */
    public function onStop(): void
    {
    }

    /**
     * Writes what another process asked this library to write.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declares
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws HilosException When the notification cannot be written or delivered
     * @throws InvalidArgumentException When the retry answer cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_NOTIFICATION_EMIT:
                if (!$data->data instanceof NotificationEmitSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        NotificationEmitSignalData::class,
                        $data->data,
                    );
                }
                $this->emit($data->data->toDraft());

                return;

            case HilosSignalConstants::HILOS_DELIVERY_RETRY:
                if (!$data->data instanceof DeliveryRetrySignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        DeliveryRetrySignalData::class,
                        $data->data,
                    );
                }
                $this->retryDelivery($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Re-queues one failed delivery for an admin, and tells the page what became of it.
     *
     * The body of the retry, whole, from where a page used to run it (HIL-201, moved by
     * HIL-771): the row is looked up, judged, reset to pending with no attempts behind it and
     * re-dispatched through {@see NotificationDispatcher}. What changed is only WHERE the
     * judging happens - here, in the same process and the same breath as the write, instead of
     * in whichever worker served the admin's socket.
     *
     * Both refusals keep their sentences word for word, because they are what the admin reads:
     * a row that is not there, and a row that is not failed. Neither is an exception - the ask
     * came in as a frame, and a throw here would answer the caller with silence.
     *
     * @param DeliveryRetrySignalData $retry Which delivery to re-queue, and who is waiting
     * @throws InvalidArgumentException When the answer cannot be named or queued
     */
    private function retryDelivery(DeliveryRetrySignalData $retry): void
    {
        $this->answerRetry($retry, $this->requeue($retry->deliveryId));
    }

    /**
     * Resets and re-dispatches one journal row, or says why it cannot be.
     *
     * @param int $deliveryId Delivery journal row the admin picked
     * @return ?string Why the row was not re-queued, or null when it was
     */
    private function requeue(int $deliveryId): ?string
    {
        try {
            $delivery = $this->deliveries()->findById($deliveryId);
            if ($delivery === null) {
                return "Unknown delivery: {$deliveryId}";
            }

            if ($delivery->status !== DeliveryStatus::FAILED) {
                return 'Only failed deliveries can be retried';
            }

            $this->dispatcher->requeue($delivery);
        } catch (DatabaseException $e) {
            // The same sentence the dispatcher would have put on the wire had this been thrown
            // on the page: a storage failure is told to nobody but the log. Caught rather than
            // let out, because an ask that arrived as a frame is answered or it hangs.
            $this->logAgentError("Delivery retry failed for #{$deliveryId}: {$e->getMessage()}");

            return SignalConstants::ACTION_FAILED_REASON;
        }

        return null;
    }

    /**
     * Hands the outcome back to the page that forwarded the retry.
     *
     * @param DeliveryRetrySignalData $retry The ask, carrying whom to answer
     * @param ?string $error Why the retry was refused, or null when it went through
     * @throws InvalidArgumentException When the answer cannot be named or queued
     */
    private function answerRetry(DeliveryRetrySignalData $retry, ?string $error): void
    {
        $this->sendToAgent(
            HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
            new DeliveryRetryDoneSignalData($retry->acceptKey, $retry->requestId, $error),
        );
    }

    /**
     * Resolves the framework-owned notification-deliveries object collection.
     *
     * @return ObjectNotificationDeliveries Delivery persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function deliveries(): ObjectNotificationDeliveries
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notificationDeliveries);
        if (!$collection instanceof ObjectNotificationDeliveries) {
            throw new LogicException('Notification deliveries object collection is not configured');
        }

        return $collection;
    }

    /**
     * Persists a notification, fans it live to the recipient's connections, and dispatches channels.
     *
     * The body {@see HilosNotifier::emit()} used to run in whatever worker called it, moved
     * here whole and in the same order: the durable row first, then the live in-app frame to
     * the recipient's group, then the channel fan. Persistence is authoritative and the live
     * frame is a convenience - a recipient who was offline at emit time recovers the state
     * from the unread count.
     *
     * @param NotificationDraft $draft The notification to persist and deliver
     * @return int The persisted notification id
     * @throws EmptyValueException When the draft type or title is empty
     * @throws DatabaseException When the notification cannot be persisted
     * @throws LogicException When the notifications object collection is unavailable
     * @throws InvalidArgumentException When the live or channel signal cannot be named or queued
     */
    public function emit(NotificationDraft $draft): int
    {
        $severity = NotificationSeverity::isValid($draft->severity)
            ? $draft->severity
            : NotificationSeverity::INFO;

        $notification = $this->notifications()->createFor(
            $draft->userId,
            $draft->type,
            $severity,
            $draft->title,
            $draft->body,
            $this->encodeData($draft->data),
        );

        $id = $notification->id;
        if ($id === null) {
            throw new DatabaseException('Notification insert did not assign an id');
        }

        $this->fan(
            $draft->userId,
            NotificationSignalName::CREATED,
            new NotificationCreatedSignalData(
                id: $id,
                userId: $draft->userId,
                type: $notification->type,
                severity: $notification->severity,
                title: $notification->title,
                body: $notification->body,
                data: $notification->decodedData(),
                readAt: $notification->readAt,
                createdAt: $notification->createdAt,
            ),
        );

        $this->dispatcher->dispatch($notification, $draft->channels);

        return $id;
    }

    /**
     * Sends everything left in the queue while this library was not running (HIL-771).
     *
     * Contained on purpose, one letter at a time: the drafts come from a restore that has already
     * happened, and an agent whose start hook throws is an agent this node does not get back. A
     * letter that cannot be sent is logged and the next one is tried.
     */
    private function emitDeferred(): void
    {
        foreach (DeferredNotificationQueue::drain() as $draft) {
            try {
                $this->emit($draft);
            } catch (Throwable $e) {
                $this->logAgentError(
                    "Deferred notification for userId={$draft->userId} could not be sent: {$e->getMessage()}",
                );
            }
        }
    }

    /**
     * Runs one of the five owned controls and answers the surface that submitted it.
     *
     * None of them answers with a reply: each writes a row and either fans the change to the
     * person's own devices or leaves the browser to read its own state back, exactly as it
     * did while these were page actions. The refusals are the same objects thrown in the
     * same order, so what a client sees on a bad payload has not moved either.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: these actions answer with the framework ack alone
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the payload names no channel, endpoint or known notification
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws HilosException When a routed mutation fails
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case NotificationAction::MARK_READ:
                if (!$dto instanceof NotificationMarkReadPayloadDTO) {
                    throw new InvalidActionPayloadException($action, NotificationMarkReadPayloadDTO::class, $dto);
                }
                $this->markRead($acceptKey, $dto);

                break;

            case NotificationAction::MARK_ALL_READ:
                if (!$dto instanceof NotificationMarkAllReadPayloadDTO) {
                    throw new InvalidActionPayloadException($action, NotificationMarkAllReadPayloadDTO::class, $dto);
                }
                $this->markAllRead($acceptKey);

                break;

            case NotificationPreferenceAction::CHANNEL_SET:
                if (!$dto instanceof NotificationChannelPreferenceActionDTO) {
                    throw new InvalidActionPayloadException(
                        $action,
                        NotificationChannelPreferenceActionDTO::class,
                        $dto,
                    );
                }
                $this->setChannelPreference($acceptKey, $dto);

                break;

            case PushSubscriptionAction::SUBSCRIBE:
                if (!$dto instanceof PushSubscribeActionDTO) {
                    throw new InvalidActionPayloadException($action, PushSubscribeActionDTO::class, $dto);
                }
                $this->subscribePush($acceptKey, $dto);

                break;

            case PushSubscriptionAction::UNSUBSCRIBE:
                if (!$dto instanceof PushUnsubscribeActionDTO) {
                    throw new InvalidActionPayloadException($action, PushUnsubscribeActionDTO::class, $dto);
                }
                $this->unsubscribePush($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the test-only emit command on the command channel (HIL-514, moved by HIL-771).
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     * @throws InvalidArgumentException When the command reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($data->command !== CliCommands::NOTIFICATION_TEST_EMIT) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

            return;
        }

        $this->handleNotificationEmit($data);
    }

    /**
     * Emits one notification from the payload and reports what it produced (HIL-514).
     *
     * The whole point is still that this runs in a worker - the emit writes the durable row,
     * fans the live in-app signal and dispatches channels exactly as a product caller would,
     * which a CLI process could not do. What changed with the move is only which worker: the
     * one that owns the tables, so the reply's id names a row this agent wrote itself.
     *
     * The queued channels are read back from the delivery journal rather than assumed from the
     * dispatch, so the reply reports what the database holds - the difference between "no
     * channel was queued at all" and "the channel was queued and its agent did not deliver".
     *
     * A failed emit and a failed read-back are answered apart on purpose: the second means the
     * notification does exist, and reporting it as an emit failure would send a caller looking
     * for a row that is already there.
     *
     * @param CommandRequestDTO $data Command request carrying the draft fields
     * @throws InvalidArgumentException When the command reply carries an empty correlation id
     */
    private function handleNotificationEmit(CommandRequestDTO $data): void
    {
        $payload = $data->payload;
        $userId = $payload[NotificationCommandConstants::FIELD_USER_ID] ?? null;
        $type = $payload[NotificationCommandConstants::FIELD_TYPE] ?? null;
        $title = $payload[NotificationCommandConstants::FIELD_TITLE] ?? null;
        if (!is_int($userId) || $userId <= 0 || !is_string($type) || $type === '' || !is_string($title) || $title === '') {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Emit needs a positive userId and a non-empty type and title',
            ));

            return;
        }

        $severity = $payload[NotificationCommandConstants::FIELD_SEVERITY] ?? null;
        $body = $payload[NotificationCommandConstants::FIELD_BODY] ?? null;
        $channels = $payload[NotificationCommandConstants::FIELD_CHANNELS] ?? null;

        try {
            $notificationId = $this->emit(new NotificationDraft(
                userId: $userId,
                type: $type,
                title: $title,
                severity: is_string($severity) ? $severity : NotificationSeverity::INFO,
                body: is_string($body) ? $body : null,
                channels: is_array($channels) && $channels !== []
                    ? array_values(array_map(strval(...), $channels))
                    : null,
            ));
        } catch (Throwable $e) {
            // Deliberately does not claim nothing was written: the emit persists the row and
            // fans the in-app signal before it dispatches channels, so a failure here can
            // still leave a notification behind, and a caller told otherwise would retry.
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Emit did not complete (a notification may already exist): ' . $e->getMessage(),
            ));

            return;
        }

        try {
            $queued = $this->queuedChannels($notificationId);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                "Notification {$notificationId} was emitted, but its deliveries are unreadable: {$e->getMessage()}",
            ));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            NotificationCommandConstants::FIELD_NOTIFICATION_ID => $notificationId,
            NotificationCommandConstants::FIELD_QUEUED_CHANNELS => $queued,
        ]));
    }

    /**
     * Marks one of the recipient's notifications read and fans the read to their devices.
     *
     * The row is resolved and ownership-checked against the acting connection's own user, so
     * a client can never mark another user's notification read - the same two checks in the
     * same order the notification centre ran them in before the move.
     *
     * @param string $acceptKey Acting connection accept key
     * @param NotificationMarkReadPayloadDTO $dto Mark-read payload (notification id)
     * @throws ItemNotFoundForUpdateException When the connection has no resolvable user
     * @throws ValidationException When the notification is missing or owned by another user
     * @throws HilosException When the mark-read query fails
     * @throws InvalidArgumentException When the read signal cannot be named or queued
     */
    private function markRead(string $acceptKey, NotificationMarkReadPayloadDTO $dto): void
    {
        $userId = $this->requireUserId($acceptKey);

        $notification = Hilos::$db->notifications[$dto->id] ?? null;
        if ($notification === null || $notification->userId !== $userId) {
            throw new ValidationException('Notification not found');
        }

        $notification->actions->markRead();

        $this->fan($userId, NotificationSignalName::READ, new NotificationReadSignalData($dto->id));
    }

    /**
     * Marks every unread notification of the recipient read and fans the mark-all.
     *
     * @param string $acceptKey Acting connection accept key
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws HilosException When the bulk mark-read query fails
     * @throws InvalidArgumentException When the read signal cannot be named or queued
     */
    private function markAllRead(string $acceptKey): void
    {
        $userId = $this->requireUserId($acceptKey);

        Hilos::$db->notifications->actions->markAllReadForUser($userId);

        $this->fan(
            $userId,
            NotificationSignalName::READ,
            new NotificationReadSignalData(NotificationReadSignalData::ALL),
        );
    }

    /**
     * Applies the signed-in user's opt in/out for one notification channel (HIL-485).
     *
     * Server-authoritative and self-only: the acting user is read from the connection, never
     * from the payload. The write goes through the framework preferences action (enabling
     * deletes the sparse muted row, muting upserts one); the new full channel -> allowed map
     * is then fanned so all of the person's devices reflect the same state.
     *
     * @param string $acceptKey Acting connection accept key
     * @param NotificationChannelPreferenceActionDTO $dto Channel toggle DTO (channel, desired state)
     * @throws ValidationException When the channel name is missing
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws DatabaseException When the preference write or channel-map read query fails
     * @throws InvalidArgumentException When the preferences signal cannot be named or queued
     */
    private function setChannelPreference(string $acceptKey, NotificationChannelPreferenceActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Notification channel is required');
        }

        $userId = $this->requireUserId($acceptKey);

        Hilos::$db->notificationPreferences->actions->setChannel($userId, $dto->channel, $dto->enabled);

        $this->fan(
            $userId,
            NotificationSignalName::PREFERENCES_CHANGED,
            new NotificationPreferencesChangedSignalData(
                new NotificationChannelPreferenceProjector()->channelPreferenceMap($userId),
            ),
        );
    }

    /**
     * Registers the acting device's web-push subscription (HIL-199).
     *
     * Self-only: the acting user is read from the connection, never from the payload, so a
     * device can only ever subscribe for its own user. The write upserts by endpoint (rotated
     * keys or a new owner reuse the same row). No signal is fanned - a subscription is
     * per-device durable state the toggle reads back from the browser's own
     * getSubscription(), not shared cross-connection state (unlike the channel preference in
     * {@see setChannelPreference()}).
     *
     * @param string $acceptKey Acting connection accept key
     * @param PushSubscribeActionDTO $dto Subscription DTO (endpoint, keys, user agent)
     * @throws ValidationException When the endpoint or a key is missing
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws EmptyValueException When the endpoint is empty
     * @throws DatabaseException When the subscription write query fails
     */
    private function subscribePush(string $acceptKey, PushSubscribeActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Push subscription endpoint and keys are required');
        }

        $userId = $this->requireUserId($acceptKey);

        Hilos::$db->pushSubscriptions->actions->subscribe(
            $userId,
            $dto->endpoint,
            $dto->p256dh,
            $dto->auth,
            $dto->userAgent,
        );
    }

    /**
     * Removes the acting device's web-push subscription (HIL-199).
     *
     * The opt-out half of the toggle: the row is deleted by endpoint (endpoints are globally
     * unique). Self-only - an authenticated session is required so an anonymous client cannot
     * prune subscriptions - though the endpoint alone identifies the row. As with subscribe,
     * no signal is fanned.
     *
     * @param string $acceptKey Acting connection accept key
     * @param PushUnsubscribeActionDTO $dto Unsubscribe DTO (endpoint)
     * @throws ValidationException When the endpoint is missing
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws DatabaseException When the subscription delete query fails
     */
    private function unsubscribePush(string $acceptKey, PushUnsubscribeActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Push subscription endpoint is required');
        }

        $this->requireUserId($acceptKey);

        Hilos::$db->pushSubscriptions->actions->unsubscribe($dto->endpoint);
    }

    /**
     * Resolves the acting connection's user, or refuses the action.
     *
     * Read off the session-carrying connection rather than off {@see Hilos::$rt}'s
     * `selfConnection`, which is what the page handlers used: an agent is not the connection's
     * page host and has no "self" - it is handed an accept key and looks the row up, the same
     * way the sign-in library does ({@see AbstractLibraryCommands::acting()}).
     *
     * The refusal doubles as the guard behind {@see AUTH_ACTIONS}: an anonymous session is
     * turned away by the dispatcher before it gets here, and a connection that went anonymous
     * in between is turned away here.
     *
     * @param string $acceptKey Acting connection accept key
     * @return int Authenticated recipient user id
     * @throws ItemNotFoundForUpdateException When no live connection carries the key, or it is anonymous
     */
    private function requireUserId(string $acceptKey): int
    {
        $userId = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->userId;
        if ($userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        return $userId;
    }

    /**
     * Names the registered channels that received a delivery row for one notification.
     *
     * Asks the journal per registered channel instead of listing the notification's rows,
     * because a delivery is only meaningful against a channel the project still registers.
     * An empty registry short-circuits before the table is touched, so a project that never
     * activated the delivery table answers "nothing queued" rather than raising.
     *
     * @param int $notificationId Notification whose deliveries are read back
     * @return list<string> Channel names holding a delivery row, in registry order
     * @throws DatabaseException When the delivery lookup query fails
     * @throws TableNotActivatedException When a channel is registered but the table is not activated
     */
    private function queuedChannels(int $notificationId): array
    {
        $deliveries = Hilos::$db?->getObjectCollection(HilosDbContext::notificationDeliveries);
        if (!$deliveries instanceof ObjectNotificationDeliveries) {
            return [];
        }

        $queued = [];
        foreach (array_keys(Hilos::notificationChannelRegistryClass()::all()) as $channel) {
            if ($deliveries->findFor($notificationId, $channel) !== null) {
                $queued[] = $channel;
            }
        }

        return $queued;
    }

    /**
     * Prunes the delivery journal to its retention window, swallowing and logging any failure.
     *
     * Reads the retention setting only when the project catalogs it; a retention of 0
     * disables cleanup inside {@see DeliveryLogPruner::prune()}. Any failure is logged
     * and swallowed so a prune error never breaks the agent loop.
     */
    private function pruneDeliveryLog(): void
    {
        try {
            $setting = Hilos::$setting;
            if ($setting === null || !isset($setting[DeliveryLogPruner::RETENTION_SETTING_KEY])) {
                return;
            }

            $deleted = new DeliveryLogPruner()->prune(
                $setting[DeliveryLogPruner::RETENTION_SETTING_KEY]->int(),
                new DateTimeImmutable(),
            );

            if ($deleted > 0) {
                $this->logAgentInfo("Delivery-log prune removed {$deleted} rows");
            }
        } catch (Throwable $e) {
            $this->logAgentError('Delivery-log prune failed: ' . $e->getMessage());
        }
    }

    /**
     * Queues a server → client signal to a recipient's notification group.
     *
     * Best-effort: when the signal router is not initialized or the recipient has no
     * subscribed connection, the signal simply reaches no one - the durable row already
     * carries the state.
     *
     * @param int $userId Recipient user id (resolves the group name)
     * @param string $signalName Signal name (see NotificationSignalName)
     * @param NotificationCreatedSignalData|NotificationReadSignalData $data Inner payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    private function fan(int $userId, string $signalName, mixed $data): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_GROUP),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(
                data: $data,
                targetGroup: NotificationGroup::forUser($userId),
            ),
        );
    }

    /**
     * Encodes the draft's structured data for storage as a JSON string.
     *
     * @param ?array<string, mixed> $data Structured data, or null
     * @return ?string JSON string, or null when there is no data
     */
    private function encodeData(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? null : $encoded;
    }

    /**
     * Resolves the framework-owned notifications object collection.
     *
     * @return ObjectNotifications Notifications persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function notifications(): ObjectNotifications
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notifications);
        if (!$collection instanceof ObjectNotifications) {
            throw new LogicException('Notifications object collection is not configured');
        }

        return $collection;
    }
}
