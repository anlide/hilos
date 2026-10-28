<?php

declare(strict_types=1);

namespace Hilos\Status;

use ArrayIterator;
use Closure;
use DateTimeImmutable;
use Hilos\Auth\IdentifierMask;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Database\View\Item\StatusIncidentUpdate;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\AbstractStatusOutageMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\StatusSubscriptionConfirmMailTemplate;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\DTO\StatusRemoveSubscriberSignalData;
use Hilos\Status\DTO\StatusFilesChangedSignalData;
use Hilos\Status\DTO\StatusConfirmSubscriptionActionDTO;
use Hilos\Status\DTO\StatusSubscribeActionDTO;
use Hilos\Status\DTO\StatusSubscriptionReplyDTO;
use Hilos\Status\DTO\StatusUnsubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewReplyDTO;
use Hilos\Status\Exception\StatusComponentDeclarationException;
use Hilos\Status\Exception\StatusIncidentWriteException;
use Hilos\Status\Exception\StatusException;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\WiringRefusal;
use Random\RandomException;
use SplQueue;
use Throwable;

/**
 * Cluster owner of Status counts and incident storage.
 *
 * The whole picture, baseline and lease are private, restartable work buffers for
 * this one owner (framework-first.md -> runtime/rt-context.md). Shared normalized
 * counts live in Hilos::$rt, and durable history in Hilos::$db.
 * Each bounded portion reads only its current incidents. One component's DB
 * transition, immutable timeline and measured RT counts commit together.
 */
final class StatusAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_STATUS;

    public const string THROTTLE_VERDICT_SIGNAL = HilosSignalConstants::STATUS_THROTTLE_VERDICT;

    public const array AGENT_ACTIONS = [
        HilosSignalConstants::STATUS_SUBSCRIBE => StatusSubscribeActionDTO::class,
        HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => StatusConfirmSubscriptionActionDTO::class,
        HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => StatusUnsubscribePreviewActionDTO::class,
        HilosSignalConstants::STATUS_UNSUBSCRIBE => StatusUnsubscribeActionDTO::class,
    ];

    public const array THROTTLED_ACTIONS = [
        HilosSignalConstants::STATUS_SUBSCRIBE,
        HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM,
        HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW,
        HilosSignalConstants::STATUS_UNSUBSCRIBE,
    ];

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
        HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
        HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
        HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
        HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER => StatusRemoveSubscriberSignalData::class,
        HilosSignalConstants::STATUS_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
    ];

    public const array OWNS_DB = [
        HilosDbContext::statusIncidents => [TruthSourceOperation::Add, TruthSourceOperation::Update],
        HilosDbContext::statusIncidentUpdates => [TruthSourceOperation::Add],
        HilosDbContext::statusSubscribers => [TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove],
    ];

    public const array OWNS_RT = [HilosStatusComponent::RT_COLLECTION => TruthSourceOperation::ALL];

    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    public const array READS_DB = [HilosDbContext::sessions];

    private const int WATCH_RENEW_INTERVAL_MS = 30000;
    private const int FIRST_SNAPSHOT_RETRY_INTERVAL_MS = 1000;
    private const int COMPONENTS_PER_TICK = 32;
    private const int NOTICE_DRAFTS_PER_TICK = 32;
    private const int SUBSCRIBER_ATTEMPTS_PER_TICK = 32;
    private const float SUBSCRIBER_PORTION_BUDGET_MS = 20.0;
    private const int PUBLIC_REVISION_BYTES = 16;
    private const string SUBSCRIPTIONS_UNAVAILABLE = 'Status subscriptions are unavailable. Try again later.';
    private const string INVALID_SUBSCRIPTION_LINK = 'This subscription link is invalid or has expired. Request a new link.';
    private const string MAIL_DATE_FORMAT = 'Y-m-d H:i:s P (e)';

    /** @var SplQueue<StatusCandidateNotice> Best-effort work of this instance, lost on stop or freeze */
    private SplQueue $candidateNotices;

    /** @var ?ArrayIterator<int, int> Current task's admin ids, never DB View references */
    private ?ArrayIterator $noticeRecipients = null;

    /** @var SplQueue<StatusSubscriberMailTask> Private best-effort work, discarded on stop, freeze or restart */
    private SplQueue $subscriberNotices;

    /** @var ArrayIterator<string, StatusComponentDefinition> Private, restartable reconciliation cursor */
    private ArrayIterator $components;

    private ClusterDaemonPicture $picture;

    /** @var array<string, ?ClusterDaemonNodeSlot> Per-node report identities at the start of observation or return online */
    private array $baseline = [];

    /** @var array<string, true> Online nodes whose report differs from the baseline and contains processes */
    private array $freshNodes = [];

    private bool $hasFullSnapshot = false;
    private ?int $observedAt = null;
    private ?int $lastWatchAt = null;
    private bool $wasFrozen = false;
    private bool $reconcilePending = false;
    private ?int $nextReconcileAt = null;

    // Private publication work of this owner; no ORM references or shared RT cache.
    private StatusPublicSnapshotBuilder $publicBuilder;
    private StatusPublicFiles $publicFiles;
    private ?StatusPublicSnapshot $publicArchive = null;
    private ?StatusPublicSnapshot $publicCurrent = null;
    private ?StatusComponentState $freezeState = null;
    private bool $publicInvalidated = true;
    private bool $publicReadPending = true;
    private bool $publicNeedsBuild = true;
    private bool $publicSourceAvailable = false;
    private int $publicDatabaseRetryAt = 0;
    private int $publicAttemptAt = 0;
    private int $publicHeartbeatAttemptAt = 0;
    private ?int $publicHistoryExpiryAt = null;
    private ?string $publicFailure = null;
    private int $publicPollIntervalSeconds;
    private int $publicStaleAfterSeconds;

    /**
     * @throws StatusComponentDeclarationException When the project's component declaration is invalid
     * @throws HilosException When runtime access or the first watch is refused
     */
    public function onStart(): void
    {
        $this->components = Hilos::statusComponents()->getIterator();
        $this->resetNotices();
        $this->resetSubscriberNotices();
        $this->resetObservation();
        $this->lastWatchAt = null;
        $this->wasFrozen = false;
        $this->reconcilePending = false;
        $this->nextReconcileAt = null;
        $this->publicBuilder = new StatusPublicSnapshotBuilder();
        $this->publicFiles = new StatusPublicFiles();
        $this->publicPollIntervalSeconds = Hilos::$env[EnvConstants::STATUS_POLL_INTERVAL_SECONDS]->int();
        $this->publicStaleAfterSeconds = Hilos::$env[EnvConstants::STATUS_STALE_AFTER_SECONDS]->int();
        $this->publicArchive = $this->publicFiles->restore();
        $this->publicCurrent = null;
        $this->publicInvalidated = true;
        $this->publicReadPending = true;
        $this->publicNeedsBuild = true;
        $this->publicSourceAvailable = false;
        $this->publicDatabaseRetryAt = 0;
        $this->publicAttemptAt = 0;
        $this->publicHeartbeatAttemptAt = 0;
        $this->publicHistoryExpiryAt = null;
        $this->publicFailure = null;
        $this->refreshFreeze();
        $nowMs = TimeHelper::nowMs();
        if (!$this->wasFrozen) {
            $this->watchIfDue($nowMs);
        }
        $this->advancePublic($nowMs);
    }

    /**
     * @throws HilosException When reconciliation, source access, watch or notice delivery is refused
     */
    public function onTick(): void
    {
        $this->tickAt(TimeHelper::nowMs());
    }

    /**
     * Guest actions use the ordinary correlated reply and the host's single throttle pool.
     *
     * @param string $acceptKey Acting socket, used only for the initial browser-zone snapshot
     * @param string $action Owned public action name
     * @param ActionPayloadDTO $dto Parsed and secret-redacted action payload
     * @return ?ActionReplyDTO Approved subscription outcome or masked preview
     * @throws AgentUnknownActionException When the action is not owned by Status
     * @throws InvalidActionPayloadException When the parsed DTO does not match the action
     * @throws HilosException When maintenance, missing sources, persistence or a link refuses the operation
     * @throws RandomException When secure challenge entropy is unavailable
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::STATUS_SUBSCRIBE:
                if (!$dto instanceof StatusSubscribeActionDTO) {
                    throw new InvalidActionPayloadException($action, StatusSubscribeActionDTO::class, $dto);
                }
                return $this->subscribe($acceptKey, $dto);

            case HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM:
                if (!$dto instanceof StatusConfirmSubscriptionActionDTO) {
                    throw new InvalidActionPayloadException($action, StatusConfirmSubscriptionActionDTO::class, $dto);
                }
                return $this->confirmSubscription($dto);

            case HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW:
                if (!$dto instanceof StatusUnsubscribePreviewActionDTO) {
                    throw new InvalidActionPayloadException($action, StatusUnsubscribePreviewActionDTO::class, $dto);
                }
                return $this->previewUnsubscribe($dto);

            case HilosSignalConstants::STATUS_UNSUBSCRIBE:
                if (!$dto instanceof StatusUnsubscribeActionDTO) {
                    throw new InvalidActionPayloadException($action, StatusUnsubscribeActionDTO::class, $dto);
                }
                return $this->unsubscribe($dto);

            default:
                throw new AgentUnknownActionException($action);
        }
    }

    /**
     * Keeps notice and lease work independent of the bounded reconciliation cursor.
     *
     * @param int $nowMs Local tick clock in epoch milliseconds UTC
     * @throws HilosException When reconciliation, source access, watch or notice delivery is refused
     */
    public function tickAt(int $nowMs): void
    {
        $this->refreshFreeze();
        try {
            if (!$this->wasFrozen && Hilos::$db !== null) {
                $this->drainSubscriberNotices();
                if (!$this->wasFrozen) {
                    $this->tickMachineAt($nowMs);
                }
            }
        } catch (DatabaseException $failure) {
            $this->publicDatabaseRetryAt = $nowMs + self::FIRST_SNAPSHOT_RETRY_INTERVAL_MS;
            throw $failure;
        } finally {
            $this->advancePublic($nowMs);
        }
    }

    /**
     * Releases only a normal stop; a freeze permits no delivery from this owner.
     *
     * @throws HilosException When runtime access or watch release is refused
     */
    public function onStop(): void
    {
        $this->refreshFreeze();
        $this->resetNotices();
        $this->resetSubscriberNotices();
        $this->publicBuilder->reset();
        $this->publicFiles->cancel();
        if (!$this->wasFrozen) {
            $this->sendToAgent(HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH, new DaemonPictureWatchSignalData(0));
        }
        $this->resetObservation();
        $this->lastWatchAt = null;
    }

    /**
     * Verdicts belong to the worker's page router and require no application here.
     *
     * @param AgentSignalData $data Topology-parsed portion, internal handover ask or throttle verdict
     * @param string $sender Collector or gatekeeper address, unused
     * @param string $name Owned signal name
     * @throws AgentUnknownSignalException When the signal is not owned by Status
     * @throws HilosException When source access or answer delivery is refused
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::STATUS_THROTTLE_VERDICT:
                // Worker delivery passes the verdict on to this instance's page router.
                return;

            case HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION:
                $this->applyPicturePortion($data->data, TimeHelper::nowMs());
                return;

            case HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER:
                if (!$data->data instanceof StatusRemoveSubscriberSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, StatusRemoveSubscriberSignalData::class, $data->data);
                }
                $this->answerSubscriberRemoval($data->data);
                return;

            case HilosSignalConstants::STATUS_CANCEL_PUBLICATION:
            case HilosSignalConstants::STATUS_PUBLISH_NOW:
            case HilosSignalConstants::STATUS_ADD_UPDATE:
                $this->answerIncidentWrite($data->data);
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * A first whole snapshot establishes the baseline, never fresh online process facts.
     * Later snapshots repair membership without restarting the freshness wait.
     *
     * @param DaemonClusterPicturePortionSignalData $portion Whole snapshot or whole changed slots
     * @param int $nowMs Local acceptance clock in epoch milliseconds UTC
     * @throws HilosException When the freeze runtime cannot be read
     */
    public function applyPicturePortion(DaemonClusterPicturePortionSignalData $portion, int $nowMs): void
    {
        $this->refreshFreeze();
        if ($this->wasFrozen) {
            return;
        }
        if (!$this->hasFullSnapshot) {
            if (!$portion->snapshot) {
                return;
            }
            $this->resetObservation();
            foreach ($portion->nodes as $node) {
                $this->picture = $this->picture->withNode($node);
                $this->baseline[$node->nodeId] = $node->slot;
            }
            $this->hasFullSnapshot = true;
            $this->observedAt = $nowMs;
            $this->reconcilePending = true;
            return;
        }

        $before = $this->picture;
        $present = [];
        foreach ($portion->nodes as $node) {
            $present[$node->nodeId] = true;
            $previous = $this->picture->node($node->nodeId);
            if ($previous === null || !$node->online) {
                $this->baseline[$node->nodeId] = $node->slot;
            }
            if ($node->online && $node->slot?->picture->processes !== null
                && $node->slot != ($this->baseline[$node->nodeId] ?? null)) {
                $this->freshNodes[$node->nodeId] = true;
            } else {
                unset($this->freshNodes[$node->nodeId]);
            }
            $this->picture = $this->picture->withNode($node);
        }
        if ($portion->snapshot) {
            foreach ($before->nodes() as $node) {
                if (!isset($present[$node->nodeId])) {
                    $this->picture = $this->picture->withNode(new ClusterDaemonNodeView($node->nodeId, false, $node->slot));
                    $this->baseline[$node->nodeId] = $node->slot;
                    unset($this->freshNodes[$node->nodeId]);
                }
            }
        }
        if ($this->picture != $before) {
            $this->observedAt = $nowMs;
            $this->reconcilePending = true;
        }
    }

    /**
     * Read by this owner's reconciliation; does not publish measured counts before its DB transaction.
     *
     * @param StatusComponentDefinition $component Declared permanent component
     * @return StatusObservation Current slot verdict, unknown during freeze or incomplete observation
     * @throws HilosException When the freeze runtime cannot be read
     */
    public function observation(StatusComponentDefinition $component): StatusObservation
    {
        $this->refreshFreeze();
        return StatusObservation::fromPicture($component, $this->picture, $this->hasFullSnapshot, $this->freshNodes, $this->observedAt);
    }

    /**
     * @throws HilosException When runtime state, the owned source or its readiness is unavailable
     */
    private function requireSubscriptionsAvailable(): void
    {
        $this->refreshFreeze();
        if ($this->wasFrozen || Hilos::$db === null
            || !SourceInterestRegistry::isReady(SourceChange::KIND_DB, HilosDbContext::statusSubscribers)) {
            throw new ValidationException(self::SUBSCRIPTIONS_UNAVAILABLE);
        }
    }

    /**
     * @param string $acceptKey Server-resolved ordering socket
     * @param StatusSubscribeActionDTO $dto Normalized address, unrelated to any account
     * @return StatusSubscriptionReplyDTO Identical outcome for new, pending and confirmed addresses
     * @throws HilosException When sources, configuration, persistence or mail handoff is refused
     * @throws RandomException When secure challenge entropy is unavailable
     */
    private function subscribe(string $acceptKey, StatusSubscribeActionDTO $dto): StatusSubscriptionReplyDTO
    {
        $this->requireSubscriptionsAvailable();
        $links = StatusSubscriptionLink::fromEnvironment();
        $connections = Hilos::$rt->sessionConnectionsSource();
        if ($connections === null || $connections->getCollectionName() === null
            || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, $connections->getCollectionName())
            || !SourceInterestRegistry::isReady(SourceChange::KIND_DB, HilosDbContext::sessions)
            || Hilos::$mail === null || Hilos::$sr === null) {
            throw new ValidationException(self::SUBSCRIPTIONS_UNAVAILABLE);
        }
        $this->subscriptionTransaction(function () use ($acceptKey, $dto, $links): void {
            $subscriberId = Hilos::$db->statusSubscribers->findByEmail($dto->email)?->id;
            if ($subscriberId === null) {
                $sessionToken = Hilos::$rt->sessionConnectionsSource()->get($acceptKey)?->sessionToken;
                $zone = $sessionToken === null ? null : Hilos::$db->sessions->findByToken($sessionToken)?->browserTimeZone;
                $confirmation = Hilos::$db->statusSubscribers->actions->create($dto->email, TimeHelper::nowMs(), $zone);
            } else {
                $confirmation = Hilos::$db->statusSubscribers[$subscriberId]->actions->issueConfirmation(TimeHelper::nowMs());
            }
            if ($confirmation !== null) {
                Database::afterCommit(function () use ($confirmation, $links): void {
                    $this->sendSubscriptionConfirmation($confirmation, $links);
                });
            }
        });
        return new StatusSubscriptionReplyDTO(StatusSubscriptionReplyDTO::CHECK_INBOX);
    }

    /**
     * Resolves the committed primary id again; no ORM object or secret is retained for later ticks.
     *
     * @param StatusSubscriberConfirmation $confirmation Ephemeral challenge from the committed write
     * @param StatusSubscriptionLink $links Public address validated before issuing that challenge
     * @throws HilosException When a source, recipient-zone selection or raw-mail handoff is refused
     */
    private function sendSubscriptionConfirmation(StatusSubscriberConfirmation $confirmation, StatusSubscriptionLink $links): void
    {
        $this->requireSubscriptionsAvailable();
        if (Hilos::$mail === null || Hilos::$sr === null) {
            throw new ValidationException(self::SUBSCRIPTIONS_UNAVAILABLE);
        }
        if (!isset(Hilos::$db->statusSubscribers[$confirmation->subscriberId])
            || Hilos::$db->statusSubscribers[$confirmation->subscriberId]->confirmed) {
            return;
        }
        $expiresAt = Hilos::$db->statusSubscribers[$confirmation->subscriberId]->confirmationExpiresAt;
        if ($expiresAt === null) {
            return;
        }
        Hilos::$db->statusSubscribers[$confirmation->subscriberId]->deliverMail(function (
            string $email, string $unsubscribeToken, ?string $savedZone,
        ) use ($confirmation, $links, $expiresAt): void {
            $projectName = trim(Hilos::$env[EnvConstants::MAIL_FROM_NAME]->string());
            Hilos::$mail->send(new MailSendSignalData(
                to: $email,
                shardKey: HilosMailer::shardKeyForAddress($email),
                templateKey: MailTemplateCatalogConstants::STATUS_SUBSCRIPTION_CONFIRM,
                params: [
                    StatusSubscriptionConfirmMailTemplate::PARAM_PROJECT_NAME => $projectName === '' ? 'Service' : $projectName,
                    StatusSubscriptionConfirmMailTemplate::PARAM_STATUS_URL => $links->statusUrl,
                    StatusSubscriptionConfirmMailTemplate::PARAM_CONFIRM_URL => $links->confirmUrl($confirmation->token),
                    StatusSubscriptionConfirmMailTemplate::PARAM_UNSUBSCRIBE_URL => $links->unsubscribeUrl($unsubscribeToken),
                    StatusSubscriptionConfirmMailTemplate::PARAM_EXPIRES_AT_LABEL => new DateTimeImmutable(
                        '@' . intdiv($expiresAt, TimeConstants::MS_PER_SECOND),
                    )->setTimezone(TimeHelper::serverTextTimeZone(browserTimeZone: $savedZone))->format(self::MAIL_DATE_FORMAT),
                ],
                locale: null,
            ));
        });
    }

    /**
     * @param StatusConfirmSubscriptionActionDTO $dto One-time confirmation challenge
     * @return StatusSubscriptionReplyDTO Subscribed only for the atomic winner
     * @throws HilosException When sources, persistence or a stale challenge refuse confirmation
     */
    private function confirmSubscription(StatusConfirmSubscriptionActionDTO $dto): StatusSubscriptionReplyDTO
    {
        $this->requireSubscriptionsAvailable();
        $this->subscriptionTransaction(function () use ($dto): void {
            $subscriberId = Hilos::$db->statusSubscribers->findByConfirmationToken($dto->token)?->id;
            if ($subscriberId === null
                || Hilos::$db->statusSubscribers[$subscriberId]->actions->confirm($dto->token, TimeHelper::nowMs()) === null) {
                throw new ValidationException(self::INVALID_SUBSCRIPTION_LINK);
            }
        });
        return new StatusSubscriptionReplyDTO(StatusSubscriptionReplyDTO::SUBSCRIBED);
    }

    /**
     * @param StatusUnsubscribePreviewActionDTO $dto Permanent unsubscribe capability
     * @return StatusUnsubscribePreviewReplyDTO Masked recipient without a write or token spend
     * @throws HilosException When sources or the unknown capability refuse the preview
     */
    private function previewUnsubscribe(StatusUnsubscribePreviewActionDTO $dto): StatusUnsubscribePreviewReplyDTO
    {
        $this->requireSubscriptionsAvailable();
        $subscriberId = Hilos::$db->statusSubscribers->findByUnsubscribeToken($dto->token)?->id;
        if ($subscriberId === null) {
            throw new ValidationException(self::INVALID_SUBSCRIPTION_LINK);
        }
        return new StatusUnsubscribePreviewReplyDTO(IdentifierMask::mask(Hilos::$db->statusSubscribers[$subscriberId]->email));
    }

    /**
     * @param StatusUnsubscribeActionDTO $dto Capability for this particular persisted subscription
     * @return StatusSubscriptionReplyDTO Safe success even when this capability's row was already removed
     * @throws HilosException When sources or persistence refuse removal
     */
    private function unsubscribe(StatusUnsubscribeActionDTO $dto): StatusSubscriptionReplyDTO
    {
        $this->requireSubscriptionsAvailable();
        $this->subscriptionTransaction(function () use ($dto): void {
            $subscriberId = Hilos::$db->statusSubscribers->findByUnsubscribeToken($dto->token)?->id;
            if ($subscriberId !== null) {
                Hilos::$db->statusSubscribers[$subscriberId]->actions->delete();
            }
        });
        return new StatusSubscriptionReplyDTO(StatusSubscriptionReplyDTO::UNSUBSCRIBED);
    }

    /**
     * Preserves domain refusals while containing unexpected callback failures at the Status boundary.
     *
     * @param Closure(): void $operation One complete guest mutation and its afterCommit registration
     * @throws HilosException When the transaction or its operation is refused
     * @throws RandomException When the challenge cannot draw secure entropy
     */
    private function subscriptionTransaction(Closure $operation): void
    {
        try {
            Database::transaction(DatabaseNames::PRIMARY, $operation);
        } catch (HilosException|RandomException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new StatusException('The status subscription operation failed.', previous: $failure);
        }
    }

    /**
     * @param StatusRemoveSubscriberSignalData $ask ADMIN gatekeeper's exact target and reply metadata
     * @throws InvalidArgumentException When the typed handover answer cannot be queued
     */
    private function answerSubscriberRemoval(StatusRemoveSubscriberSignalData $ask): void
    {
        $this->sendToAgent($ask->replySignal, HandoverAnswerSignalData::to($ask, $this->removeSubscriber($ask)));
    }

    /**
     * Rechecks the fixed page/action gate instead of trusting names carried by an internal ask.
     * The owner's caller transaction commits before the correlated answer leaves.
     *
     * @param StatusRemoveSubscriberSignalData $ask Exact persisted subscriber target
     * @return ?ActionRefusal Safe refusal, or null after the durable removal
     */
    private function removeSubscriber(StatusRemoveSubscriberSignalData $ask): ?ActionRefusal
    {
        try {
            PageAccessGate::assertAction(AbstractHilosStatusSubscribersPage::class, $ask->acceptKey,
                HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE);
            $this->requireSubscriptionsAvailable();
            Database::transaction(DatabaseNames::PRIMARY, function () use ($ask): void {
                if (!isset(Hilos::$db->statusSubscribers[$ask->subscriberId])) {
                    throw new ValidationException('This status subscriber no longer exists.');
                }
                Hilos::$db->statusSubscribers[$ask->subscriberId]->actions->delete();
            });
        } catch (WiringRefusal $failure) {
            return ActionRefusal::fromThrowable($failure);
        } catch (Throwable $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
            if ($refusal->isInternal()) {
                $this->logAgentError('Status subscriber removal failed: ' . $failure->getMessage());
            }
            return $refusal;
        }
        return null;
    }

    /**
     * @param StatusCancelPublicationSignalData|StatusPublishNowSignalData|StatusAddUpdateSignalData $ask Gatekeeper's ask
     * @throws InvalidArgumentException When the handover answer cannot be queued
     */
    private function answerIncidentWrite(
        StatusCancelPublicationSignalData|StatusPublishNowSignalData|StatusAddUpdateSignalData $ask,
    ): void {
        $this->sendToAgent($ask->replySignal, HandoverAnswerSignalData::to($ask, $this->incidentWrite($ask)));
    }

    /**
     * Reads and changes the current primary-id target in the same transaction as its timeline.
     * Null runtime is unavailable; every occupied freeze phase refuses before database access.
     *
     * @param StatusCancelPublicationSignalData|StatusPublishNowSignalData|StatusAddUpdateSignalData $ask Gatekeeper's ask
     * @return ?ActionRefusal Refusal of the write, or null after durable commit
     */
    private function incidentWrite(
        StatusCancelPublicationSignalData|StatusPublishNowSignalData|StatusAddUpdateSignalData $ask,
    ): ?ActionRefusal {
        try {
            $this->refreshFreeze();
            if ($this->wasFrozen) {
                return ActionRefusal::said('Status changes are unavailable during maintenance or while runtime state is unavailable.');
            }
            Database::transaction(DatabaseNames::PRIMARY, function () use ($ask): void {
                if (!isset(Hilos::$db->statusIncidents[$ask->incidentId])) {
                    throw new StatusIncidentWriteException('This status incident no longer exists.');
                }
                if (Hilos::$db->statusIncidents[$ask->incidentId]->resolvedAt !== null) {
                    throw new StatusIncidentWriteException('This incident has already ended.');
                }
                $nowMs = TimeHelper::nowMs();
                if ($ask instanceof StatusCancelPublicationSignalData) {
                    Hilos::$db->statusIncidents[$ask->incidentId]->actions->suppress($nowMs);
                } elseif ($ask instanceof StatusPublishNowSignalData) {
                    if (Hilos::$db->statusIncidents[$ask->incidentId]->suppressedAt !== null) {
                        throw new StatusIncidentWriteException('Publication of this incident was canceled.');
                    }
                    $this->publishIncident(Hilos::$db->statusIncidents[$ask->incidentId], $nowMs);
                } else {
                    if (Hilos::$db->statusIncidents[$ask->incidentId]->publishedAt === null) {
                        throw new StatusIncidentWriteException('Publish this incident before adding a team update.');
                    }
                    $previous = $ask->requestId === null ? null
                        : Hilos::$db->statusIncidentUpdates->forRequest($ask->incidentId, $ask->requestId);
                    $update = Hilos::$db->statusIncidentUpdates->actions->append(
                        $ask->incidentId, StatusUpdateKind::TEAM, StatusSeverity::from(Hilos::$db->statusIncidents[$ask->incidentId]->severity),
                        new StatusUpdateText($ask->text), $nowMs, $ask->requestId,
                    );
                    if ($previous === null) {
                        $this->queueSubscriberNoticeAfterCommit(Hilos::$db->statusIncidents[$ask->incidentId], $update);
                        $this->invalidatePublicAfterCommit();
                    }
                }
                Database::afterCommit(function (): void {
                    $this->publicReadPending = true;
                });
            });
        } catch (WiringRefusal $failure) {
            return $this->incidentWriteRefusal($failure);
        } catch (Throwable $failure) {
            return $this->incidentWriteRefusal($failure);
        }
        return null;
    }

    /**
     * @param Throwable $failure Domain refusal or diagnostic failure of the transaction
     * @return ActionRefusal Safe reason with internal diagnostics retained for the gatekeeper
     */
    private function incidentWriteRefusal(Throwable $failure): ActionRefusal
    {
        $refusal = ActionRefusal::fromThrowable($failure);
        if ($refusal->isInternal()) {
            $this->logAgentError('Status incident operation failed: ' . $failure->getMessage());
        }
        return $refusal;
    }

    /**
     * Joins this component's transaction; unknown observations cannot end or publish a fact.
     *
     * @param StatusComponentDefinition $component Permanent catalog entry
     * @param StatusObservation $observation Complete slot verdict or unknown counts
     * @param ?StatusIncident $incident Current durable fact, if any
     * @param int $nowMs Local transition clock in epoch milliseconds UTC
     * @return ?int Future publication deadline requiring a pass without a new frame, or null
     * @throws HilosException When settings, incident persistence or timeline append is refused
     */
    private function reconcile(
        StatusComponentDefinition $component,
        StatusObservation $observation,
        ?StatusIncident $incident,
        int $nowMs,
    ): ?int {
        if ($observation->liveInstances === null || $observation->observedAt === null) {
            return $incident !== null && $incident->publishedAt === null && $incident->suppressedAt === null && $incident->publishAt > $nowMs
                ? $incident->publishAt : null;
        }
        if ($observation->liveInstances >= $observation->expectedInstances) {
            if ($incident !== null) {
                $incident->actions->resolve($nowMs);
                $this->invalidatePublicAfterCommit();
                if ($incident->publishedAt !== null) {
                    $this->appendAutomaticUpdate($incident, StatusUpdateKind::RESOLVED, $nowMs);
                }
            }
            return null;
        }

        $severity = $observation->liveInstances === 0 ? StatusSeverity::MAJOR_OUTAGE : StatusSeverity::PARTIAL_OUTAGE;
        if ($incident === null) {
            $delay = Hilos::$setting[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]->int();
            if ($nowMs < 0 || $delay < 0 || $delay > intdiv(PHP_INT_MAX - $nowMs, TimeConstants::MS_PER_SECOND)) {
                throw new StatusIncidentWriteException('The status publication deadline is outside the supported time range.');
            }
            $incident = Hilos::$db->statusIncidents->actions->candidate(
                $component->componentKey, $component->name, $severity, $nowMs, $nowMs + $delay * TimeConstants::MS_PER_SECOND,
            );
            $this->invalidatePublicAfterCommit();
            $notice = new StatusCandidateNotice(
                $incident->id, $incident->componentKey, $incident->componentName, $incident->detectedAt, $incident->publishAt,
            );
            Database::afterCommit(function () use ($notice): void {
                $this->candidateNotices->enqueue($notice);
            });
        } elseif ($incident->severity !== $severity->value) {
            $incident->actions->changeSeverity($severity);
            if ($incident->publishedAt !== null) {
                $this->appendAutomaticUpdate($incident, StatusUpdateKind::SEVERITY_CHANGED, $nowMs);
            }
        }
        if ($incident->publishedAt === null && $incident->suppressedAt === null && $nowMs >= $incident->publishAt) {
            $this->publishIncident($incident, $nowMs);
        }
        return $incident->publishedAt === null && $incident->suppressedAt === null ? $incident->publishAt : null;
    }

    /**
     * One promotion and its first timeline row, inside the caller's transaction.
     *
     * @param StatusIncident $incident Open, unsuppressed fact
     * @param int $nowMs Local publication moment in epoch milliseconds UTC
     * @throws HilosException When publication or timeline persistence is refused
     */
    private function publishIncident(StatusIncident $incident, int $nowMs): void
    {
        if ($incident->publishedAt !== null) {
            return;
        }
        $incident->actions->publish($nowMs);
        $this->appendAutomaticUpdate($incident, StatusUpdateKind::PUBLISHED, $nowMs);
    }

    /**
     * @param StatusIncident $incident Fact carrying the current proven severity
     * @param StatusUpdateKind $kind Automatic transition kind
     * @param int $nowMs Local transition moment in epoch milliseconds UTC
     * @throws HilosException When the text or timeline persistence is refused
     */
    private function appendAutomaticUpdate(StatusIncident $incident, StatusUpdateKind $kind, int $nowMs): void
    {
        $severity = StatusSeverity::from($incident->severity);
        $text = $kind === StatusUpdateKind::RESOLVED ? 'Resolved.' : match ($severity) {
            StatusSeverity::PARTIAL_OUTAGE => 'Partial outage.',
            StatusSeverity::MAJOR_OUTAGE => 'Major outage.',
        };
        $update = Hilos::$db->statusIncidentUpdates->actions->append(
            $incident->id, $kind, $severity, new StatusUpdateText($text), $nowMs,
        );
        if ($kind !== StatusUpdateKind::SEVERITY_CHANGED) {
            $this->queueSubscriberNoticeAfterCommit($incident, $update);
        }
        $this->invalidatePublicAfterCommit();
    }

    /**
     * Captures only the newly appended immutable row, never a mutable View in the closure.
     *
     * @param StatusIncident $incident Corresponding incident at append
     * @param StatusIncidentUpdate $update New published, team or resolved row
     */
    private function queueSubscriberNoticeAfterCommit(StatusIncident $incident, StatusIncidentUpdate $update): void
    {
        $notice = new StatusSubscriberNotice(
            $update->id, $update->incidentId, $incident->componentName, StatusUpdateKind::from($update->kind),
            StatusSeverity::from($update->severity), $update->text, $incident->detectedAt, $update->at, $incident->resolvedAt,
        );
        Database::afterCommit(function () use ($notice): void {
            $this->enqueueSubscriberNotice($notice);
        });
    }

    /**
     * Hilos::$rt is deliberately not used: nobody else reads this owner's accepted best-effort work.
     * The DB owns subscribers; counts() captures the bound once without caching the audience.
     *
     * @param StatusSubscriberNotice $notice Detached event whose transaction committed
     */
    private function enqueueSubscriberNotice(StatusSubscriberNotice $notice): void
    {
        try {
            $this->requireSubscriptionsAvailable();
            if (Hilos::$mail === null || Hilos::$sr === null) {
                return;
            }
            $maxId = Hilos::$db->statusSubscribers->counts()->maxSubscriberId;
            $links = StatusSubscriptionLink::fromEnvironment();
            $projectName = trim(Hilos::$env[EnvConstants::MAIL_FROM_NAME]->string());
            $this->subscriberNotices->enqueue(new StatusSubscriberMailTask(
                $notice, $maxId, $links, $projectName === '' ? 'Service' : $projectName,
            ));
        } catch (WiringRefusal $failure) {
            $this->logAgentError('Status subscriber notice source refused: ' . $failure->getMessage());
        } catch (Throwable $failure) {
            $this->logAgentError('Status subscriber notice enqueue failed: ' . $failure->getMessage());
        }
    }

    /**
     * One task per tick bounds even empty or failed work; all recipients share the tick's budget.
     * Cursor advancement precedes handoff, so an ambiguous recipient failure is never replayed.
     */
    private function drainSubscriberNotices(): void
    {
        if ($this->subscriberNotices->isEmpty()) {
            return;
        }
        $startedAt = hrtime(true);
        $task = $this->subscriberNotices->bottom();
        try {
            $this->requireSubscriptionsAvailable();
            if (Hilos::$mail === null || Hilos::$sr === null) {
                $this->resetSubscriberNotices();
                return;
            }
            $recipients = Hilos::$db->statusSubscribers->confirmedRecipients(
                $task->notice->eventAt, $task->maxSubscriberId, $task->afterId, self::SUBSCRIBER_ATTEMPTS_PER_TICK,
            );
        } catch (WiringRefusal $failure) {
            $this->resetSubscriberNotices();
            $this->logAgentError('Status subscriber audience source refused: ' . $failure->getMessage());
            return;
        } catch (Throwable $failure) {
            $this->resetSubscriberNotices();
            $this->logAgentError('Status subscriber audience failed: ' . $failure->getMessage());
            return;
        }
        $attempts = 0;
        foreach ($recipients as $recipient) {
            if ($attempts > 0 && (hrtime(true) - $startedAt) / TimeConstants::NS_PER_MILLISECOND >= self::SUBSCRIBER_PORTION_BUDGET_MS) {
                return;
            }
            $attempts++;
            $task->afterId = $recipient->id;
            try {
                $this->sendSubscriberNotice($task, $recipient->id);
            } catch (Throwable $failure) {
                $this->logAgentError('Status subscriber mail handoff failed: ' . $failure->getMessage());
            }
            if ($this->subscriberNotices->isEmpty()) {
                return;
            }
        }
        if (count($recipients) < self::SUBSCRIBER_ATTEMPTS_PER_TICK || $task->afterId === $task->maxSubscriberId) {
            $this->subscriberNotices->dequeue();
        }
    }

    /**
     * Rechecks the primary id and confirmation immediately before reading its DB-only mail capability.
     *
     * @param StatusSubscriberMailTask $task Immutable event and captured audience boundary
     * @param int $subscriberId Current recipient's primary id, never an email lookup
     * @throws HilosException When sources, recipient formatting or raw mail handoff are refused
     */
    private function sendSubscriberNotice(StatusSubscriberMailTask $task, int $subscriberId): void
    {
        $this->requireSubscriptionsAvailable();
        if (Hilos::$mail === null || Hilos::$sr === null
            || !isset(Hilos::$db->statusSubscribers[$subscriberId])
            || !Hilos::$db->statusSubscribers[$subscriberId]->confirmed
            || Hilos::$db->statusSubscribers[$subscriberId]->confirmedAt === null
            || Hilos::$db->statusSubscribers[$subscriberId]->confirmedAt > $task->notice->eventAt) {
            return;
        }
        Hilos::$db->statusSubscribers[$subscriberId]->deliverMail(function (
            string $email, string $unsubscribeToken, ?string $savedZone,
        ) use ($task): void {
            $zone = TimeHelper::serverTextTimeZone(browserTimeZone: $savedZone);
            $label = static fn (int $at): string => new DateTimeImmutable('@' . intdiv($at, TimeConstants::MS_PER_SECOND))
                ->setTimezone($zone)->format(self::MAIL_DATE_FORMAT);
            Hilos::$mail->send(new MailSendSignalData(
                to: $email,
                shardKey: HilosMailer::shardKeyForAddress($email),
                templateKey: match ($task->notice->kind) {
                    StatusUpdateKind::PUBLISHED => MailTemplateCatalogConstants::STATUS_OUTAGE_PUBLISHED,
                    StatusUpdateKind::TEAM => MailTemplateCatalogConstants::STATUS_OUTAGE_UPDATE,
                    StatusUpdateKind::RESOLVED => MailTemplateCatalogConstants::STATUS_OUTAGE_RESOLVED,
                },
                params: [
                    AbstractStatusOutageMailTemplate::PARAM_PROJECT_NAME => $task->projectName,
                    AbstractStatusOutageMailTemplate::PARAM_STATUS_URL => $task->links->statusUrl,
                    AbstractStatusOutageMailTemplate::PARAM_UNSUBSCRIBE_URL => $task->links->unsubscribeUrl($unsubscribeToken),
                    AbstractStatusOutageMailTemplate::PARAM_COMPONENT_NAME => $task->notice->componentName,
                    AbstractStatusOutageMailTemplate::PARAM_SEVERITY => $task->notice->severity->value,
                    AbstractStatusOutageMailTemplate::PARAM_TEXT => $task->notice->text,
                    AbstractStatusOutageMailTemplate::PARAM_DETECTED_AT_LABEL => $label($task->notice->detectedAt),
                    AbstractStatusOutageMailTemplate::PARAM_EVENT_AT_LABEL => $label($task->notice->eventAt),
                    AbstractStatusOutageMailTemplate::PARAM_RESOLVED_AT_LABEL
                        => $task->notice->resolvedAt === null ? null : $label($task->notice->resolvedAt),
                ],
                locale: null,
            ));
        });
    }

    /** Discards this instance's private queue and keysets; durable history never recreates them. */
    private function resetSubscriberNotices(): void
    {
        $this->subscriberNotices = new SplQueue();
    }

    /**
     * @param int $nowMs Local reconciliation clock
     * @throws HilosException When an observation, transaction or notice fails
     */
    private function tickMachineAt(int $nowMs): void
    {
        $this->drainCandidateNotices();
        $this->watchIfDue($nowMs);
        if (!$this->components->valid()) {
            if (!$this->reconcilePending && ($this->nextReconcileAt === null || $nowMs < $this->nextReconcileAt)) {
                return;
            }
            $this->components->rewind();
            $this->reconcilePending = false;
            $this->nextReconcileAt = null;
        }
        $this->publicReadPending = true;
        $portion = [];
        while ($this->components->valid() && count($portion) < self::COMPONENTS_PER_TICK) {
            $portion[$this->components->key()] = $this->components->current();
            $this->components->next();
        }
        try {
            $incidents = [];
            foreach (Hilos::$db->statusIncidents->whereColumnIn(
                EntityStatusIncident::active_component_key, ...array_keys($portion),
            ) as $incident) {
                $incidents[$incident->componentKey] = $incident;
            }
            foreach ($portion as $key => $component) {
                $observation = $this->observation($component);
                $deadline = Database::transaction(DatabaseNames::PRIMARY, function () use (
                    $component, $observation, $incidents, $key, $nowMs,
                ): ?int {
                    Hilos::$rt->hilosStatusComponents->actions->ensure($key, $observation->expectedInstances);
                    Hilos::$rt->hilosStatusComponents[$key]->actions->update(
                        $observation->expectedInstances, $observation->liveInstances, $observation->observedAt,
                    );
                    return $this->reconcile($component, $observation, $incidents[$key] ?? null, $nowMs);
                });
                if ($deadline !== null && ($this->nextReconcileAt === null || $deadline < $this->nextReconcileAt)) {
                    $this->nextReconcileAt = $deadline;
                }
            }
        } catch (Throwable $failure) {
            // Retry from durable facts; already committed components remain idempotent.
            $this->components->rewind();
            $this->nextReconcileAt = null;
            throw $failure;
        }
    }

    /**
     * Each attempted recipient is consumed before emit: an ambiguous failure is never replayed.
     * Audience lookup failure drops that task, while later recipients can progress after an emit failure.
     *
     * @throws HilosException When audience lookup, recipient formatting or emit fails after durable commit
     */
    private function drainCandidateNotices(): void
    {
        if (Hilos::$notify === null) {
            $this->resetNotices();
            return;
        }
        for ($work = 0; $work < self::NOTICE_DRAFTS_PER_TICK && !$this->candidateNotices->isEmpty(); $work++) {
            try {
                $this->noticeRecipients ??= new ArrayIterator(Hilos::adminAudienceClass()::all());
                if ($this->noticeRecipients->valid()) {
                    $userId = $this->noticeRecipients->current();
                    $this->noticeRecipients->next();
                    Hilos::$notify->emit($this->candidateNotices->bottom()->draftFor($userId));
                }
            } catch (Throwable $failure) {
                if ($this->noticeRecipients === null || !$this->noticeRecipients->valid()) {
                    $this->candidateNotices->dequeue();
                    $this->noticeRecipients = null;
                }
                $this->logAgentError('Status candidate notification failed: ' . $failure->getMessage());
                throw $failure;
            }
            if (!$this->noticeRecipients->valid()) {
                $this->candidateNotices->dequeue();
                $this->noticeRecipients = null;
            }
        }
    }

    /** Discards only this instance's best-effort notice work; durable facts never recreate it. */
    private function resetNotices(): void
    {
        $this->candidateNotices = new SplQueue();
        $this->noticeRecipients = null;
    }

    /** Raises no file side effects inside a transaction or a rolled-back generation. */
    private function invalidatePublicAfterCommit(): void
    {
        Database::afterCommit(function (): void {
            $this->publicInvalidated = true;
        });
    }

    /**
     * One bounded build and serialization portion, independent of visitors and machine early returns.
     * Read failures retain only sanitized history. A failed write keeps its publisher retry state.
     *
     * @param int $nowMs Local publication clock
     * @throws HilosException When declarations, source wiring or hint delivery are refused
     */
    private function advancePublic(int $nowMs): void
    {
        $available = !$this->wasFrozen && Hilos::$db !== null && $nowMs >= $this->publicDatabaseRetryAt;
        if ($available !== $this->publicSourceAvailable) {
            $this->publicSourceAvailable = $available;
            $this->publicInvalidated = true;
        }
        if ($this->publicHistoryExpiryAt !== null && $nowMs >= $this->publicHistoryExpiryAt) {
            $this->publicHistoryExpiryAt = null;
            $this->publicInvalidated = true;
        }
        if ($nowMs < $this->publicAttemptAt) {
            return;
        }
        try {
            if ($this->publicInvalidated) {
                $this->publicBuilder->reset();
                $this->publicFiles->cancel();
                $this->publicInvalidated = false;
                $this->publicReadPending = true;
                $this->publicNeedsBuild = true;
            }
            // Finish this machine pass before photographing counts, including after a lift.
            // The ordinary worker re-hydrate handler completes synchronously before the lift;
            // no old SQL cursor, View reference or observation baseline is resumed here.
            if ($this->publicReadPending && (!$available || !$this->components->valid())) {
                $current = $available ? StatusSnapshot::read() : null;
                if ($current !== null && $current->incidents === null) {
                    $current = null;
                    $this->publicDatabaseRetryAt = $nowMs + self::FIRST_SNAPSHOT_RETRY_INTERVAL_MS;
                    $this->publicSourceAvailable = false;
                    $this->publicNeedsBuild = true;
                }
                $projection = $this->publicUnavailable($nowMs);
                if ($current !== null) {
                    $projection = $projection->withCurrent($current, $projection->revision, $nowMs);
                }
                if ($this->publicNeedsBuild || $projection->components != $this->publicCurrent?->components
                    || $projection->candidates != $this->publicCurrent?->candidates) {
                    $this->publicBuilder->reset();
                    $this->publicFiles->cancel();
                    if ($current === null) {
                        $this->publicBuilder->beginDetached($projection);
                    } else {
                        $this->publicBuilder->begin(
                            $current, $projection->revision, $nowMs, $this->publicPollIntervalSeconds, $this->publicStaleAfterSeconds,
                        );
                    }
                    $this->publicCurrent = $projection;
                    $this->publicNeedsBuild = false;
                }
                $this->publicReadPending = false;
            }
            $this->publicBuilder->advance();
            if ($this->publicBuilder->isReady()) {
                $this->publicArchive = $this->publicBuilder->getSnapshot();
                $this->publicHistoryExpiryAt = $this->publicBuilder->getNextHistoryExpiryAt();
                $this->publicFiles->begin($this->publicArchive);
                $this->publicBuilder->reset();
            }
            $receipt = $this->publicFiles->advance($nowMs);
            if ($receipt === null && $nowMs >= $this->publicHeartbeatAttemptAt) {
                try {
                    $receipt = $this->publicFiles->heartbeat($nowMs);
                } catch (StatusException $failure) {
                    // A refused heartbeat cannot stall the independent bounded build/staging.
                    $this->publicHeartbeatAttemptAt = $nowMs + self::FIRST_SNAPSHOT_RETRY_INTERVAL_MS;
                    $this->reportPublicFailure($failure);
                }
            }
            if ($receipt !== null) {
                $this->sendToAllConnected(HilosSignalConstants::STATUS_FILES_CHANGED, new StatusFilesChangedSignalData());
                $this->publicFailure = null;
            }
        } catch (DatabaseException $failure) {
            $this->publicDatabaseRetryAt = $nowMs + self::FIRST_SNAPSHOT_RETRY_INTERVAL_MS;
            $this->publicInvalidated = true;
            $this->reportPublicFailure($failure);
        } catch (StatusException $failure) {
            $this->publicAttemptAt = $nowMs + self::FIRST_SNAPSHOT_RETRY_INTERVAL_MS;
            $this->reportPublicFailure($failure);
        }
    }

    /**
     * Catalog plus unconditional freeze only; deliberately never calls the shared admin reader.
     * Null runtime is Unknown. Every occupied phase is Maintenance, including verification.
     *
     * @param int $nowMs Projection moment in epoch milliseconds UTC
     * @return StatusPublicSnapshot New current overlay with the last complete sanitized archive
     * @throws HilosException When the catalog, revision or publication metadata is invalid
     */
    private function publicUnavailable(int $nowMs): StatusPublicSnapshot
    {
        $components = [];
        foreach (Hilos::statusComponents() as $component) {
            $components[] = new StatusPublicComponent(
                $component->componentKey, $component->name, $component->description,
                $this->freezeState ?? StatusComponentState::UNKNOWN,
            );
        }
        try {
            $revision = RandomHelper::secureHex(self::PUBLIC_REVISION_BYTES);
        } catch (RandomException $failure) {
            throw new StatusException('Cannot mint a public Status revision.', previous: $failure);
        }
        return new StatusPublicSnapshot(
            $revision, $nowMs,
            $this->publicPollIntervalSeconds, $this->publicStaleAfterSeconds,
            $components, [], $this->publicArchive?->outages ?? [],
        );
    }

    /** @param HilosException $failure Private diagnostic; never copied to a public file */
    private function reportPublicFailure(HilosException $failure): void
    {
        if ($this->publicFailure !== $failure->getMessage()) {
            $this->logAgentError($failure->getMessage());
            $this->publicFailure = $failure->getMessage();
        }
    }

    /**
     * Null means this process holds no runtime state and must wait, just as during an active freeze.
     *
     * @throws HilosException When the freeze runtime cannot be read
     */
    private function refreshFreeze(): void
    {
        $freeze = Hilos::$rt?->hilosProtectedModeRuntime;
        $state = $freeze === null ? StatusComponentState::UNKNOWN
            : ($freeze->phase === ProtectedModeRuntime::PHASE_INACTIVE ? null : StatusComponentState::MAINTENANCE);
        if ($state !== $this->freezeState) {
            $this->freezeState = $state;
            $this->publicInvalidated = true;
            // No SQL cursor survives entry, lift or loss of the unconditional runtime row.
            if (isset($this->publicBuilder)) {
                $this->publicBuilder->reset();
            }
        }
        if ($state !== null) {
            $this->resetNotices();
            $this->resetSubscriberNotices();
            $this->resetObservation();
            $this->wasFrozen = true;
            return;
        }
        if ($this->wasFrozen) {
            $this->components->rewind();
            $this->nextReconcileAt = null;
            $this->reconcilePending = false;
            $this->lastWatchAt = null;
            $this->wasFrozen = false;
        }
    }

    /**
     * @param int $nowMs Local lease clock in epoch milliseconds UTC
     * @throws InvalidArgumentException When the watch cannot be routed
     */
    private function watchIfDue(int $nowMs): void
    {
        $interval = $this->hasFullSnapshot ? self::WATCH_RENEW_INTERVAL_MS : self::FIRST_SNAPSHOT_RETRY_INTERVAL_MS;
        if ($this->lastWatchAt === null || $nowMs - $this->lastWatchAt >= $interval) {
            $this->sendToAgent(HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH, new DaemonPictureWatchSignalData(1));
            $this->lastWatchAt = $nowMs;
        }
    }

    /** Discards only this instance's rebuildable observation buffers. */
    private function resetObservation(): void
    {
        $this->picture = ClusterDaemonPicture::empty();
        $this->baseline = [];
        $this->freshNodes = [];
        $this->hasFullSnapshot = false;
        $this->observedAt = null;
    }
}
