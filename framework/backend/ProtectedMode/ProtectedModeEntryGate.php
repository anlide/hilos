<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Constants\TimeConstants;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\Utils\Logger;

/**
 * Holds a freeze request back until the lift before it has finished putting the roster together
 * (HIL-1000).
 *
 * **What it is for.** A lift asks this node's workers to start the agents the freeze stopped, and
 * an agent is only back once its worker reports it. A freeze entered in that gap deregisters an
 * agent whose start report is already on the wire: the master meets the report with a roster that
 * does not name it, stops it as an orphan, and the snapshot the new freeze took - one agent short -
 * is what the next lift replays. Nothing asks for that agent again, so the loss is permanent, and
 * it is a loss per freeze: on a stand driving the mode 58 times the roster walked from 19 agents
 * down to 9 with 56 orphan kills on the way.
 *
 * **Why a hold and not a refusal.** The asking side is an agent in the middle of a destructive
 * operation - a restore - and its question is "may I run now", not "is now convenient". Refused,
 * it would have to invent a retry of its own and every caller would invent a different one; held,
 * it waits exactly as long as the node needs and hears the same answer it always hears.
 *
 * **Why it gives up.** A start that never lands must not freeze the freeze: a node with one stuck
 * worker would refuse every restore it is ever asked for, which is a worse failure than the one
 * being prevented here. So the hold is bounded, and the entry that goes through anyway says which
 * agents it went through on top of - the same bargain the WebSocket readiness wait makes when it
 * opens degraded rather than never.
 *
 * **What it does NOT cover.** A follower being quiesced by its leader ({@see ProtectedModeSwitch}
 * implementations reach {@see ProtectedModeExecutor::enterActivating()} on that path too) does not
 * pass through here: this gate stands at the door an INITIATOR knocks on, which is the only door a
 * single-node installation has. Giving a leader's broadcast the same hold means holding a
 * cluster-wide decision on one node's local roster, and that is a question about the quiesce
 * protocol rather than about this door.
 */
final class ProtectedModeEntryGate
{
    /** Longest a freeze waits for the roster to settle before it is entered anyway. */
    private const float SETTLE_DEADLINE_SECONDS = 5.0;

    /** @var ?ProtectedModeEnableSignalData Enable held until the roster settles, or null when none is */
    private ?ProtectedModeEnableSignalData $pendingEnable = null;

    /** @var float Microtime the held enable arrived at */
    private float $pendingSince = 0.0;

    /**
     * Takes one enable request, entering the freeze now or holding it until the roster settles.
     *
     * A second request arriving while one is held lets the held one through first and follows it
     * in: what a freeze on top of a freeze means is the switch's answer to give, and giving it
     * here as well is how two answers to one question drift apart. All this gate owes the pair is
     * the order they were asked in.
     *
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     */
    public function requestEnter(ProtectedModeEnableSignalData $data): void
    {
        $held = $this->pendingEnable;
        if ($held !== null) {
            $this->pendingEnable = null;
            $this->enter($held);
            $this->enter($data);

            return;
        }

        $starting = $this->agentsStillStarting();
        if ($starting === []) {
            $this->enter($data);

            return;
        }

        $this->pendingEnable = $data;
        $this->pendingSince = microtime(true);
        Logger::info("Protected mode: holding the freeze for '{$data->operation}' until the roster settles -"
            . ' still starting: ' . implode(', ', $starting));
    }

    /**
     * Enters a held freeze as soon as the roster settles, or once the hold has waited long enough.
     *
     * Called once per daemon loop pass, which is also what makes the hold cost nothing when there
     * is nothing held: the first line answers that case.
     */
    public function tick(): void
    {
        $held = $this->pendingEnable;
        if ($held === null) {
            return;
        }

        $starting = $this->agentsStillStarting();
        $waitedMs = (int)round((microtime(true) - $this->pendingSince) * TimeConstants::MS_PER_SECOND);
        if ($starting === []) {
            $this->pendingEnable = null;
            Logger::info("Protected mode: the roster settled after {$waitedMs}ms, entering the freeze"
                . " for '{$held->operation}'");
            $this->enter($held);

            return;
        }

        if ((microtime(true) - $this->pendingSince) < self::SETTLE_DEADLINE_SECONDS) {
            return;
        }

        $this->pendingEnable = null;
        Logger::warning("Protected mode: the roster did not settle in {$waitedMs}ms, entering the freeze"
            . " for '{$held->operation}' on top of: " . implode(', ', $starting));
        $this->enter($held);
    }

    /**
     * Hands one enable to this node's freeze switch.
     *
     * A node with no switch ignores the request, exactly as it did before this gate stood in
     * front of it.
     *
     * @param ProtectedModeEnableSignalData $data Request to enter with
     */
    private function enter(ProtectedModeEnableSignalData $data): void
    {
        Hilos::$cluster?->protectedMode()?->requestEnable($data);
    }

    /**
     * @return list<string> Ids of agents this node has asked for and not yet heard back about
     */
    private function agentsStillStarting(): array
    {
        return Hilos::$cluster?->protectedModeAgentFreezer()?->agentsStillStarting() ?? [];
    }
}
