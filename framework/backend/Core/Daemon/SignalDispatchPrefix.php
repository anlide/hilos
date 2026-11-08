<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Generator;
use Hilos\Core\Exception\LogicException;
use Hilos\Socket\Server\WorkerServer;

/**
 * Finite completion debt, remembered with identities and positions rather than completed payloads.
 */
final class SignalDispatchPrefix
{
    public bool $complete = false;

    public bool $failed = false;

    private readonly int $currentTail;

    /** @var array<int, true> Only currently active captured ordinary/client lifetimes */
    private array $active = [];

    /** @var array<int, array<int, array{int, int}>> Pending child input intervals, containing positions only */
    private array $children = [];

    /** @var array<int, array{SignalDispatchIntake, int}> Last queued child on each exact intake incarnation */
    private array $childTails = [];

    private int $childSequence = 0;

    private int $revision = 0;

    private ?int $lastAttemptRevision = null;

    private ?int $attemptRevision = null;

    private ?int $readyRevision = null;

    /** @var ?Generator<int, void, void, bool> Bounded scalar metadata validation */
    private ?Generator $validation = null;

    /**
     * @param int $id Owner-local prefix identity
     * @param SignalDispatchIntake $current Exact current router incarnation
     * @param array<int, SignalDispatchIntake> $retired Already retired intake incarnations, captured by value
     * @param int $holdWatermark Last preexisting held obligation
     * @param int $identityWatermark Last already released identity waiter
     * @param int $peerWatermark Last already received one-hop fan-out
     * @param ?WorkerServer $workerServer Exact stop owner, or null for the final-browser prefix
     * @param ?int $workerGeneration Captured stop generation, absent for the final-browser prefix
     * @param int $effectWatermark Last queued lifecycle cohort at capture
     * @throws LogicException When a stop owner and its generation are not supplied together
     */
    public function __construct(
        public readonly int $id,
        private readonly SignalDispatchIntake $current,
        private readonly array $retired,
        private readonly int $holdWatermark,
        private readonly int $identityWatermark,
        private readonly int $peerWatermark,
        public readonly ?WorkerServer $workerServer = null,
        public readonly ?int $workerGeneration = null,
        private readonly int $effectWatermark = 0,
    ) {
        if (($workerServer === null) !== ($workerGeneration === null)) {
            throw new LogicException('Signal dispatch prefix needs its exact stop owner and generation together');
        }
        $this->currentTail = $current->getWatermark();
    }

    /**
     * @param int $intakeId Exact router incarnation
     * @param int $position Original consumed position
     * @return bool Whether that envelope belongs to this captured prefix
     */
    public function includesInput(int $intakeId, int $position): bool
    {
        if ($intakeId === $this->current->id) {
            if ($position <= $this->currentTail) {
                return true;
            }
        } else {
            $intake = $this->retired[$intakeId] ?? null;
            if ($intake !== null && $position <= $intake->getWatermark()) {
                return true;
            }
        }
        $head = key($this->children[$intakeId] ?? []);
        $window = $head === null ? null : $this->children[$intakeId][$head];
        return $window !== null && $position >= $window[0] && $position <= $window[1];
    }

    /**
     * @param AgentSignalHold $hold Pending obligation, including a cursor that left the parked state
     * @return bool Whether its original identity or originating frame belongs to this prefix
     */
    public function includesHold(AgentSignalHold $hold): bool
    {
        return $hold->id <= $this->holdWatermark || $hold->stopPrefixId === $this->id || $hold->browserPrefixId === $this->id;
    }

    /**
     * @param SignalDispatch $dispatch Newly admitted ordinary or one-hop lifetime
     * @return bool Whether this captured boundary owns the admitted lifetime
     */
    public function track(SignalDispatch $dispatch): bool
    {
        if ($dispatch->heldId !== null) {
            return false;
        }
        $included = !$this->complete && !$this->failed && $this->includesDispatch($dispatch);
        if ($included) {
            $this->captureActive($dispatch);
        }
        if ($dispatch->intakeId !== null && $dispatch->inputPosition !== null) {
            $head = key($this->children[$dispatch->intakeId] ?? []);
            if ($head !== null && $dispatch->inputPosition >= $this->children[$dispatch->intakeId][$head][1]) {
                unset($this->children[$dispatch->intakeId][$head]);
            }
        }
        return $included;
    }

    /**
     * Records only input synchronously produced by a captured effect. Unrelated later input
     * ahead of it must be consumed for FIFO, but never inherits this prefix's child ownership.
     *
     * @param SignalDispatchIntake $intake Exact router receiving the generated frames
     * @param int $first First newly queued position
     * @param int $last Last newly queued position, captured after the synchronous effect
     */
    public function addChildren(SignalDispatchIntake $intake, int $first, int $last): void
    {
        if ($this->complete || $this->failed || $first > $last) {
            return;
        }
        $this->children[$intake->id][++$this->childSequence] = [$first, $last];
        $this->childTails[$intake->id] = [$intake, $last];
        $this->revision++;
    }

    /**
     * @param SignalDispatch $dispatch Original envelope's scalar admission metadata
     * @return bool Whether its admitted position belongs to the captured boundary
     */
    public function includesDispatch(SignalDispatch $dispatch): bool
    {
        return ($dispatch->intakeId !== null && $dispatch->inputPosition !== null
                && $this->includesInput($dispatch->intakeId, $dispatch->inputPosition))
            || ($dispatch->identityReleaseId !== null && $dispatch->identityReleaseId <= $this->identityWatermark)
            || ($dispatch->peerFanoutId !== null && $dispatch->peerFanoutId <= $this->peerWatermark)
            || ($dispatch->effectId !== null && $dispatch->effectId <= $this->effectWatermark)
            || $dispatch->stopPrefixId === $this->id || $dispatch->browserPrefixId === $this->id;
    }

    /**
     * @param SignalDispatch $dispatch Ordinary or one-hop lifetime already active at capture
     */
    public function captureActive(SignalDispatch $dispatch): void
    {
        $identity = spl_object_id($dispatch);
        if (!isset($this->active[$identity])) {
            $this->active[$identity] = true;
            $this->revision++;
        }
    }

    /**
     * @param SignalDispatch $dispatch Captured lifetime settled or canceled by its owner
     */
    public function settle(SignalDispatch $dispatch): void
    {
        $identity = spl_object_id($dispatch);
        if (isset($this->active[$identity])) {
            unset($this->active[$identity]);
            $this->revision++;
        }
    }

    /**
     * @param AgentSignalHold $hold Actual pending-obligation mutation
     */
    public function noteHoldChanged(AgentSignalHold $hold): void
    {
        if ($this->includesHold($hold)) {
            $this->revision++;
        }
    }

    /** @param int $id Original ready identity obligation canceled before admission */
    public function noteIdentitySettled(int $id): void
    {
        if ($id <= $this->identityWatermark) {
            $this->revision++;
        }
    }

    /** @return bool Whether another finite validation step is runnable, excluding unchanged blocked holds */
    public function hasRunnableValidation(): bool
    {
        return !$this->failed && !$this->complete && $this->active === [] && !$this->isReadyForAccounting()
            && ($this->validation !== null || $this->revision !== $this->lastAttemptRevision);
    }

    /**
     * @param AgentSignalHolds $holds Existing pending payload owner
     * @param ?int $firstIdentity First still-queued identity release, or null
     * @param ?int $firstPeer First still-queued one-hop fan-out, or null
     * @param ?int $firstEffect First still-queued lifecycle cohort, or null
     */
    public function advanceValidation(AgentSignalHolds $holds, ?int $firstIdentity = null, ?int $firstPeer = null,
        ?int $firstEffect = null): void
    {
        if (!$this->hasRunnableValidation()) {
            return;
        }
        if ($this->validation === null) {
            $this->attemptRevision = $this->revision;
            $this->validation = $this->validate($holds, $firstIdentity, $firstPeer, $firstEffect);
        }
        if ($this->validation->valid()) {
            $this->validation->next();
        }
        if (!$this->validation->valid()) {
            $ready = $this->validation->getReturn();
            $this->validation = null;
            $this->lastAttemptRevision = $this->attemptRevision;
            if ($ready && $this->attemptRevision === $this->revision && $this->active === []) {
                $this->readyRevision = $this->revision;
            }
        }
    }

    /** @return bool Whether the whole captured prefix passed validation and still owes successful accounting */
    public function isReadyForAccounting(): bool
    {
        return !$this->failed && !$this->complete && $this->readyRevision === $this->revision && $this->active === []
            && ($this->workerServer === null || $this->workerGeneration === $this->workerServer->pendingStopDispatch());
    }

    /**
     * The owner calls this only after its existing afterDispatch returned successfully.
     *
     * @throws LogicException When a prefix is completed before its whole captured work settled
     */
    public function completeAfterAccounting(): void
    {
        if (!$this->isReadyForAccounting()) {
            throw new LogicException('Signal dispatch prefix is not ready for accounting completion');
        }
        $this->complete = true;
        $this->validation = null;
    }

    /** A failed phase or accounting attempt can never later acknowledge this same prefix. */
    public function fail(): void
    {
        $this->failed = true;
        $this->validation = null;
    }

    /**
     * @param AgentSignalHolds $holds Existing payload owner
     * @param ?int $firstIdentity First queued identity release at validation start
     * @param ?int $firstPeer First queued one-hop fan-out at validation start
     * @param ?int $firstEffect First queued lifecycle cohort at validation start
     * @return Generator<int, void, void, bool> Whether all captured input and relevant obligations are settled
     */
    private function validate(AgentSignalHolds $holds, ?int $firstIdentity, ?int $firstPeer, ?int $firstEffect): Generator
    {
        yield;
        if ($this->current->consumed < $this->currentTail
            || ($firstIdentity !== null && $firstIdentity <= $this->identityWatermark)
            || ($firstPeer !== null && $firstPeer <= $this->peerWatermark)
            || ($firstEffect !== null && $firstEffect <= $this->effectWatermark)) {
            return false;
        }
        foreach ($this->retired as $intake) {
            if ($intake->consumed < $intake->getWatermark()) {
                return false;
            }
            yield;
        }
        foreach ($this->childTails as [$intake, $last]) {
            if ($intake->consumed < $last) {
                return false;
            }
            yield;
        }
        $blocked = false;
        foreach ($holds->prefixCandidates($this->holdWatermark, $this->id) as $holdId) {
            $hold = $holds->get($holdId);
            if ($hold !== null && $this->includesHold($hold)
                && ($hold->released || $hold->dispatch?->hasWork()
                    || ($this->workerServer !== null && $this->workerGeneration !== null
                        && $this->workerServer->isStopDispatchAgent($this->workerGeneration, $hold->agentId)))) {
                $blocked = true;
            }
            yield;
        }
        return !$blocked;
    }
}
