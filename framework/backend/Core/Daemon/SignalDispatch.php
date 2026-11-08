<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\GroupOperationAuthority;
use Hilos\TruthSource\RtReplicaConfirmation;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\WebSocket\Delivery\DeliveryCleanup;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use Throwable;

/**
 * One master-local delivery lifetime. Completed cursors release their payload before accounting.
 */
final class SignalDispatch
{
    /** Fixed ownership slots; clearing one must not skip another slot after a throwing finalizer. */
    private const array PAYLOAD_FIELDS = [
        'work', 'signal', 'frame', 'release', 'destinations', 'physical', 'clientTarget', 'clientServer', 'rtConfirmation', 'groupOperation',
    ];

    private const array PAYLOAD_MAPS = ['agentsDelivered', 'predecessorsChecked'];

    public SignalDispatchPhase $phase = SignalDispatchPhase::Admission;

    public ?DeliveryFrame $frame = null;

    public bool $framePrepared = false;

    public bool $skip = false;

    public ?int $heldId = null;

    public int $precedingHoldWatermark = 0;

    public ?int $preemptHoldId = null;

    /** @var array<string, true> Agent predecessor cohorts already visited by this lifetime */
    public array $predecessorsChecked = [];

    public ?int $intakeId = null;

    public ?int $inputPosition = null;

    public ?int $identityReleaseId = null;

    public ?int $peerFanoutId = null;

    public ?string $peerOrigin = null;

    public ?string $peerApplyContext = null;

    public ?RtReplicaConfirmation $rtConfirmation = null;

    public ?GroupOperationAuthority $groupOperation = null;

    public ?int $effectId = null;

    /** Current causal effect family; a settled family is never reused for a later operation. */
    public ?int $effectCohortId = null;

    /** Preexisting causal families captured before this delivery's first guarded step. */
    public ?int $effectCohortWatermark = null;

    public ?int $stopPrefixId = null;

    public ?int $browserPrefixId = null;

    public bool $clientResolved = false;

    public ?string $clientAcceptKey = null;

    public ?BrowserDeliveryTarget $clientTarget = null;

    public ?WebSocketServer $clientServer = null;

    /** @var ?Generator<int, ?Destination> Captured ordered destination progress */
    public ?Generator $destinations = null;

    /** @var ?Generator<int, void> Current destination's physical continuation */
    public ?Generator $physical = null;

    /** @var list<AgentAddressedDestination> Agents already addressed, including refused attempts */
    public array $agentsDelivered = [];

    private ?SignalDTO $signal;

    /** @var ?Generator<int, void> Work yields once before its first effect and once after every bounded step */
    private ?Generator $work;

    /**
     * @param ?SignalDTO $signal Original envelope, absent for a local lifecycle fact
     * @param Closure(self): Generator<int, void> $workFactory Binds the continuation to this exact lifetime
     * @param ?ParkedAgentSignal $release Exact one-agent hold being released, or null for ordinary input
     * @param ?DeliveryCleanup $cleanup Existing guarded metadata retirement, absent for standalone consuming lifetimes
     */
    public function __construct(
        ?SignalDTO $signal,
        Closure $workFactory,
        public ?ParkedAgentSignal $release = null,
        private readonly ?DeliveryCleanup $cleanup = null,
    )
    {
        $this->signal = $signal;
        $this->groupOperation = $release?->groupOperation;
        $this->work = $workFactory($this);
    }

    /** @return ?SignalDTO Active envelope, absent after settling */
    public function getSignal(): ?SignalDTO
    {
        return $this->signal;
    }

    /** @return bool Whether another portion can execute work */
    public function hasWork(): bool
    {
        return $this->work !== null;
    }

    /**
     * The generator's first yield is effect-free. next() then performs at most one step,
     * so checking the last yield never executes an extra recipient outside the budget.
     * Exceptions end the cursor and propagate to the existing master guard, without retry.
     *
     * @param int $steps Maximum bounded steps this portion may execute
     * @throws Throwable When a delivery or terminal effect fails
     */
    public function runPortion(int $steps): void
    {
        if ($steps <= 0 || $this->work === null) {
            return;
        }

        try {
            if (!$this->work->valid()) {
                $this->settle(SignalDispatchPhase::Complete);
                return;
            }
            for ($step = 0; $step < $steps && $this->work !== null; $step++) {
                $this->work->next();
                if ($this->work !== null && !$this->work->valid()) {
                    $this->settle(SignalDispatchPhase::Complete);
                }
            }
        } catch (Throwable $failure) {
            $this->settle(SignalDispatchPhase::Failed, $failure);
        }
    }

    /**
     * Drops undelivered continuation work; an already sent operation is not undone.
     *
     * @throws Throwable First disposal failure, after every owned payload slot has been released
     */
    public function cancel(): void
    {
        if ($this->work !== null) {
            $this->settle(SignalDispatchPhase::Canceled);
        }
    }

    /**
     * Discards a lifetime whose owner failed before entering the continuation itself.
     *
     * @throws Throwable First disposal failure, after every owned payload slot has been released
     */
    public function fail(): void
    {
        if ($this->work !== null) {
            $this->settle(SignalDispatchPhase::Failed);
        }
    }

    /**
     * Raw carriers end here; detached destination metadata uses the existing guarded cleanup.
     * This portions slot release, not a frame/DTO's decoded tree or PHP allocation costs.
     *
     * @param SignalDispatchPhase $phase Final cursor outcome
     * @param ?Throwable $failure Existing primary failure, preferred to independent disposal failures
     * @throws Throwable Primary or first disposal failure, after all independent slots have cleared
     */
    private function settle(SignalDispatchPhase $phase, ?Throwable $failure = null): void
    {
        $this->phase = $phase;
        foreach (self::PAYLOAD_FIELDS as $field) {
            try {
                $this->$field = null;
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
        }
        foreach (self::PAYLOAD_MAPS as $field) {
            try {
                if ($this->cleanup === null) {
                    $this->$field = [];
                } else {
                    $this->cleanup->retire($this->$field, $this);
                }
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
