<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use Throwable;
use WeakReference;

/**
 * Process-local declaration debt for an explicit set of addresses. Existing placement records
 * and worker links remain the truth; accepted waits belong to those owners, never a retry here.
 */
final class AgentDeclarationDuty
{
    private const int PORTION_SIZE = 64;

    /** @var array<string, AgentId> Addresses owned by the caller's declaration */
    private array $targets = [];

    /** @var array<string, AgentId> Missing demand, including event-only rebuild holds */
    private array $missing = [];

    /** @var array<string, AgentId> Finite ready portion, independent of native queue emptiness */
    private array $ready = [];

    private ?WeakReference $source = null;
    private ?WeakReference $cluster = null;
    private ?WeakReference $leadership = null;
    private ?int $term = null;
    private bool $eligible = false;
    private bool $closed = false;
    private ?EventLoop $loop = null;
    private ?EventLoopTimer $retryTimer = null;
    private ?float $retryAt = null;
    private bool $retryDue = false;
    private int $generation = 0;

    /**
     * @param float $retrySeconds Original attempt-to-retry span for an untracked exception
     */
    public function __construct(private readonly float $retrySeconds)
    {
    }

    /**
     * @param iterable<string, AgentId> $targets Current catalog addresses; evaluated only on catalog replacement
     */
    public function replaceTargets(iterable $targets): void
    {
        $this->suspend();
        $this->targets = [];
        foreach ($targets as $agentId => $target) {
            $this->targets[$agentId] = $target;
        }
    }

    /**
     * Same-base attachment preserves original future retry and completed declarations.
     *
     * @param EventLoop $loop Existing master's native base
     * @throws EventLoopException When an original retry cannot bind
     */
    public function attachEventLoop(EventLoop $loop): void
    {
        if ($this->loop === $loop && !$this->closed) {
            return;
        }
        $this->retryTimer?->close();
        $this->retryTimer = null;
        $this->loop = $loop;
        $this->closed = false;
        $this->armRetry();
    }

    /** Terminal cancellation releases native and activated callbacks without retaining old sources. */
    public function detachEventLoop(): void
    {
        $this->suspend();
        $this->loop = null;
        $this->closed = true;
    }

    /** Loss observers call this before the coordinator has swapped its old role. */
    public function suspend(): void
    {
        ++$this->generation;
        $this->retryTimer?->close();
        $this->retryTimer = null;
        $this->retryAt = null;
        $this->retryDue = false;
        $this->eligible = false;
        $this->source = null;
        $this->cluster = null;
        $this->leadership = null;
        $this->term = null;
        $this->missing = [];
        $this->ready = [];
    }

    /**
     * Stable pre-wait reconciliation reads scalar identities only, never either inventory.
     *
     * @param ?WorkerServer $workers Local start owner, null for a cluster-only duty
     * @param bool $eligible Current guarded role/readiness/exit verdict
     * @throws EnvException When cluster mode cannot be read
     */
    public function reconcile(?WorkerServer $workers, bool $eligible): void
    {
        $cluster = Hilos::$cluster;
        $source = $cluster?->placement() ?? (($cluster?->isEnabled() ?? false) ? null : $workers);
        $leadership = $cluster?->leadership();
        $term = $leadership instanceof ConsensusInspection ? $leadership->term() : null;
        $eligible = $eligible && $source !== null && !$this->closed;
        if ($this->source?->get() === $source && $this->cluster?->get() === $cluster
            && $this->leadership?->get() === $leadership && $this->term === $term && $this->eligible === $eligible) {
            return;
        }
        $this->suspend();
        $this->source = $source !== null ? WeakReference::create($source) : null;
        $this->cluster = $cluster !== null ? WeakReference::create($cluster) : null;
        $this->leadership = $leadership !== null ? WeakReference::create($leadership) : null;
        $this->term = $term;
        $this->eligible = $eligible;
        if ($eligible) {
            $this->missing = $this->targets;
            $this->ready = $this->targets;
        }
    }

    /**
     * Addressed changes qualify one known key. Capacity/settlement releases only missing demand;
     * a fully declared duty does no inventory work for such a fact.
     *
     * @param ?string $agentId Changed address, null for an actual capacity/basis/settlement fact
     */
    public function noteChanged(?string $agentId): void
    {
        if (!$this->eligible || $this->closed) {
            return;
        }
        if ($agentId === null) {
            $source = $this->source?->get();
            if ($source instanceof ClusterPlacement && $source->registry()->count() === 0) {
                $this->missing = $this->targets;
            }
            $this->ready += $this->missing;
        } elseif (!isset($this->targets[$agentId])) {
            return;
        } elseif ($this->isDeclared($agentId, $this->targets[$agentId])) {
            unset($this->missing[$agentId], $this->ready[$agentId]);
            if ($this->missing === []) {
                $this->retryTimer?->close();
                $this->retryTimer = null;
                $this->retryAt = null;
                $this->retryDue = false;
            }
            return;
        } else {
            $this->missing[$agentId] = $this->targets[$agentId];
            $this->ready[$agentId] = $this->targets[$agentId];
        }
        if ($this->ready !== []) {
            $this->retryDue = true;
        }
    }

    /** @return bool Current finite demand or a due retry; absent sources and event-only holds sleep */
    public function hasReadyWork(): bool
    {
        return $this->eligible && !$this->closed && ($this->ready !== [] || $this->retryDue)
            && ($this->retryAt === null || $this->retryDue || ($this->loop === null && $this->now() >= $this->retryAt));
    }

    /**
     * Executes one guarded portion. Native callbacks never call a placement or send a frame.
     * An exception aborts the suffix as before; only one without a tracked record owns a retry.
     *
     * @throws Throwable When the original declaration refuses, or its native retry cannot arm
     */
    public function dispatch(): void
    {
        if (!$this->hasReadyWork()) {
            return;
        }
        if (!$this->isCurrentSource()) {
            $this->suspend();
            return;
        }
        $generation = $this->generation;
        $this->retryDue = false;
        $this->retryAt = null;
        $this->retryTimer?->armAt(null);
        try {
            for ($step = 0; $step < self::PORTION_SIZE && $this->ready !== []; $step++) {
                $agentId = array_key_first($this->ready);
                $target = $this->ready[$agentId];
                unset($this->ready[$agentId]);
                if ($this->isDeclared($agentId, $target)) {
                    unset($this->missing[$agentId]);
                    continue;
                }
                $source = $this->source?->get();
                $attemptAt = $this->now();
                try {
                    if ($source instanceof ClusterPlacement) {
                        $source->placeAgentOnBestNode($target->type, $target->index);
                    } elseif ($source instanceof WorkerServer) {
                        $source->executePlacement($target->type, $target->index);
                    }
                } catch (EventLoopException $failure) {
                    throw $failure;
                } catch (Throwable $failure) {
                    if ($generation === $this->generation && !$this->isDeclared($agentId, $target)) {
                        $this->ready = [$agentId => $target] + $this->ready;
                        $this->retryAt = $attemptAt + $this->retrySeconds;
                        $this->retryDue = false;
                    } elseif ($generation === $this->generation) {
                        unset($this->missing[$agentId]);
                    }
                    throw $failure;
                }
                if ($generation !== $this->generation || !$this->isCurrentSource()) {
                    $this->suspend();
                    return;
                }
                if ($source instanceof WorkerServer || $this->isDeclared($agentId, $target)) {
                    unset($this->missing[$agentId], $this->ready[$agentId]);
                }
            }
        } finally {
            $this->armRetry();
        }
    }

    /**
     * @return bool The current facade still names the sources captured before this portion
     * @throws EnvException When current leadership cannot be read
     */
    private function isCurrentSource(): bool
    {
        $cluster = Hilos::$cluster;
        $source = $this->source?->get();
        $placement = $cluster?->placement();
        if ($source === null || $this->cluster?->get() !== $cluster
            || ($source instanceof ClusterPlacement ? $placement !== $source : $placement !== null)) {
            return false;
        }
        $leadership = $cluster?->leadership();
        return $this->leadership?->get() === $leadership
            && $this->term === ($leadership instanceof ConsensusInspection ? $leadership->term() : null);
    }

    /**
     * @param string $agentId Current address key
     * @param AgentId $target Owned address to inspect through the current domain API
     * @return bool A tracked placement, linked local agent or accepted local wait already owns this address
     */
    private function isDeclared(string $agentId, AgentId $target): bool
    {
        $source = $this->source?->get();
        if ($source instanceof ClusterPlacement) {
            return $source->registry()->get($agentId) !== null;
        }
        return $source instanceof WorkerServer && ($source->placedWorkerId($target->type, $target->index) !== null
            || $source->isAgentAwaitingWorker($agentId) || $source->isAgentAwaitingStopReport($agentId));
    }

    /** @throws EventLoopException When an actual unresolved exception retry cannot arm */
    private function armRetry(): void
    {
        if ($this->retryAt === null) {
            $this->retryTimer?->close();
            $this->retryTimer = null;
            return;
        }
        if ($this->loop === null || $this->closed) {
            return;
        }
        if ($this->retryTimer === null) {
            $owner = WeakReference::create($this);
            $generation = $this->generation;
            $this->retryTimer = $this->loop->createTimer(static function () use ($owner, $generation): void {
                $duty = $owner->get();
                if ($duty !== null && $duty->generation === $generation && $duty->eligible && !$duty->closed) {
                    $duty->retryDue = true;
                }
            });
        }
        $this->retryTimer->armAt($this->retryAt);
    }

    /** @return float Process-local monotonic seconds, independent of epoch payloads and calendar time */
    private function now(): float
    {
        return hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }
}
