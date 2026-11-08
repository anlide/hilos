<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Generator;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Throwable;

/** Process-private progress of the master's existing guarded delivery phase. */
final class SignalDispatchState
{
    public int $intakeSequence = 1;

    public int $prefixSequence = 0;

    public int $identitySequence = 0;

    public int $peerSequence = 0;

    public int $effectSequence = 0;

    public int $effectCohortSequence = 0;

    public SignalHoldIndex $effectCohorts;

    /** @var array<int, int> Outstanding FIFO effects per causal family, without payload references */
    public array $effectCohortCounts = [];

    /** Ordinary router admissions across all exact intake incarnations. */
    public int $admissionSequence = 0;

    /** Unconsumed original retired tails, without scanning or copying their queues. */
    public int $retiredRemaining = 0;

    public int $commandCancellationSequence = 0;

    /** Next ready service and admission queue; private to this master's guarded phase. */
    public int $nextService = 0;

    public int $nextAdmission = 0;

    public SignalDispatchIntake $intake;

    /** @var array<int, SignalDispatchIntake> Retired original FIFOs, each with an immutable tail */
    public array $retired = [];

    public ?SignalDispatch $active = null;

    public ?SignalDispatch $released = null;

    /** @var list<int> Suspended one-agent cursors remain payload-owned and indexed by their holds */
    public array $suspendedReleases = [];

    /** @var array<int, SignalDTO> Ready identity releases; removing the head releases its payload */
    public array $identities = [];

    /** @var array<int, SignalDispatch> Received local-only peer lifetimes, captured on arrival */
    public array $peers = [];

    /** @var array<int, SignalDispatch> Finite lifecycle cohorts, ahead of destination delivery */
    public array $effects = [];

    /** @var array<int, CommandSignalCancellation> Finite cancellation debt, ordered by captured input tail */
    public array $commandCancellations = [];

    /** @var array<string, CommandSignalCancellation> Current caller invalidations until their finite debt settles */
    public array $abandonedCommands = [];

    /** @var array<string, Generator<int, void>> Actual address, resume and retry facts awaiting bounded discovery */
    public array $agentDiscoveries = [];

    /** @var ?Generator<int, void> Finite discovery currently spending its guarded service share */
    public ?Generator $agentProbe = null;

    /** @var array<string, Generator<int, void>> Coalesced finite identity facts awaiting their discovery share */
    public array $identityDiscoveries = [];

    /** @var ?Generator<int, void> Current bounded identity discovery, runnable until its finite cohort ends */
    public ?Generator $identityProbe = null;

    public ?SignalDispatchPrefix $stop = null;

    public ?SignalDispatchPrefix $browsers = null;

    /** @param SignalRouter $router Exact initial master input owner */
    public function __construct(SignalRouter $router)
    {
        $this->intake = new SignalDispatchIntake($this->intakeSequence, $router);
        $this->effectCohorts = new SignalHoldIndex();
    }

    /**
     * Final departure only: cancel retained work without delivering or acknowledging it.
     * The router still owns its consuming FIFO; releasing an intake never drains that FIFO.
     *
     * @throws Throwable First disposal failure, after all independent owners and prefix debt have cleared
     */
    public function discard(): void
    {
        $failure = null;
        $this->stop?->fail();
        $this->browsers?->fail();
        $this->cancelDispatch($this->active, $failure);
        $this->cancelDispatch($this->released, $failure);
        foreach ($this->peers as $dispatch) {
            $this->cancelDispatch($dispatch, $failure);
        }
        foreach ($this->effects as $dispatch) {
            $this->cancelDispatch($dispatch, $failure);
        }
        foreach (['active', 'released', 'agentProbe', 'identityProbe', 'stop', 'browsers'] as $field) {
            try {
                $this->$field = null;
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
        }
        foreach (['peers', 'effects', 'effectCohortCounts', 'identities', 'identityDiscoveries', 'agentDiscoveries', 'retired', 'suspendedReleases',
            'commandCancellations', 'abandonedCommands'] as $field) {
            try {
                $this->$field = [];
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
        }
        $this->effectCohorts = new SignalHoldIndex();
        $this->retiredRemaining = 0;
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param ?SignalDispatch $dispatch Exact retained owner, absent for an empty slot
     * @param ?Throwable $failure First cleanup failure, retained while independent owners finish
     */
    private function cancelDispatch(?SignalDispatch $dispatch, ?Throwable &$failure): void
    {
        try {
            $dispatch?->cancel();
        } catch (Throwable $disposalFailure) {
            $failure ??= $disposalFailure;
        }
    }
}
