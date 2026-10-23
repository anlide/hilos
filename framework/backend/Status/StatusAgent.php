<?php

declare(strict_types=1);

namespace Hilos\Status;

use ArrayIterator;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\Exception\StatusComponentDeclarationException;
use Hilos\Status\Exception\StatusIncidentWriteException;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\WiringRefusal;
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

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
        HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
        HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
        HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
    ];

    public const array OWNS_DB = [
        HilosDbContext::statusIncidents => [TruthSourceOperation::Add, TruthSourceOperation::Update],
        HilosDbContext::statusIncidentUpdates => [TruthSourceOperation::Add],
    ];

    public const array OWNS_RT = [HilosStatusComponent::RT_COLLECTION => TruthSourceOperation::ALL];

    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    private const int WATCH_RENEW_INTERVAL_MS = 30000;
    private const int FIRST_SNAPSHOT_RETRY_INTERVAL_MS = 1000;
    private const int COMPONENTS_PER_TICK = 32;
    private const int NOTICE_DRAFTS_PER_TICK = 32;

    /** @var SplQueue<StatusCandidateNotice> Best-effort work of this instance, lost on stop or freeze */
    private SplQueue $candidateNotices;

    /** @var ?ArrayIterator<int, int> Current task's admin ids, never DB View references */
    private ?ArrayIterator $noticeRecipients = null;

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

    /**
     * @throws StatusComponentDeclarationException When the project's component declaration is invalid
     * @throws HilosException When runtime access or the first watch is refused
     */
    public function onStart(): void
    {
        $this->components = Hilos::statusComponents()->getIterator();
        $this->resetNotices();
        $this->resetObservation();
        $this->lastWatchAt = null;
        $this->wasFrozen = false;
        $this->reconcilePending = false;
        $this->nextReconcileAt = null;
        $this->refreshFreeze();
        if (!$this->wasFrozen) {
            $this->watchIfDue(TimeHelper::nowMs());
        }
    }

    /**
     * @throws HilosException When reconciliation, source access, watch or notice delivery is refused
     */
    public function onTick(): void
    {
        $this->tickAt(TimeHelper::nowMs());
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
        if ($this->wasFrozen) {
            return;
        }
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
     * Releases only a normal stop; a freeze permits no delivery from this owner.
     *
     * @throws HilosException When runtime access or watch release is refused
     */
    public function onStop(): void
    {
        $this->refreshFreeze();
        $this->resetNotices();
        if (!$this->wasFrozen) {
            $this->sendToAgent(HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH, new DaemonPictureWatchSignalData(0));
        }
        $this->resetObservation();
        $this->lastWatchAt = null;
    }

    /**
     * @param AgentSignalData $data Topology-parsed portion or internal handover ask
     * @param string $sender Collector or gatekeeper address, unused
     * @param string $name Owned signal name
     * @throws AgentUnknownSignalException When the signal is not owned by Status
     * @throws HilosException When source access or answer delivery is refused
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION:
                $this->applyPicturePortion($data->data, TimeHelper::nowMs());
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
                    Hilos::$db->statusIncidentUpdates->actions->append(
                        $ask->incidentId, StatusUpdateKind::TEAM, StatusSeverity::from(Hilos::$db->statusIncidents[$ask->incidentId]->severity),
                        new StatusUpdateText($ask->text), $nowMs, $ask->requestId,
                    );
                }
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
        Hilos::$db->statusIncidentUpdates->actions->append(
            $incident->id, $kind, $severity, new StatusUpdateText($text), $nowMs,
        );
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

    /**
     * Null means this process holds no runtime state and must wait, just as during an active freeze.
     *
     * @throws HilosException When the freeze runtime cannot be read
     */
    private function refreshFreeze(): void
    {
        if (Hilos::$rt === null || Hilos::$rt->hilosProtectedModeRuntime === null
            || Hilos::$rt->hilosProtectedModeRuntime->phase !== ProtectedModeRuntime::PHASE_INACTIVE) {
            $this->resetNotices();
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
