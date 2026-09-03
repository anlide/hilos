<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Node-local monopolistic agent carrying rotated batches from staging into the archive (HIL-870).
 *
 * It exists because the copy has to happen SOMEWHERE and every other where is worse. The owner of
 * the log directory ({@see LogStoreAgent}) cannot do it: a follow runs in its tick once a second
 * and page reads are answered from the same one, and a single write to a hung network share would
 * stop both for as long as it hangs. A child process spawned for the job — the shape the backup
 * takes — was refused as a shape: it falls outside the framework, with no agent journal, no place
 * in the cluster, and no restart with the node, and everybody who reaches for it writes those
 * three by hand and differently. Long or blocking work in this framework lives in a monopolistic
 * agent, and this one is as small as such an agent gets.
 *
 * So the copy is allowed to take the whole tick, however long that is, and is deliberately NOT cut
 * into slices: the worker is this agent's own and nobody else waits behind it. That is the exact
 * privilege the shape was chosen for.
 *
 * The tick is a second, and on an ordinary node it costs one `opendir` of an empty directory. On
 * an ordinary node the carry costs nothing more either — the archive is on the device of the live
 * logs, so a batch moves by renaming one directory.
 *
 * It talks to nobody. It writes no runtime state and sends no signal, not even to the owner whose
 * index shows the batch it just moved: both agents read the same environment value and walk the
 * same two directories, so there is nothing to tell. Nor is anything hidden by the silence — the
 * batch is on the screen throughout, as a carrying row while it is in staging and as an ordinary
 * one after — and all a signal would buy is the instant the badge turns over, which the owner's
 * next full walk does anyway. What the carrier does say is what an operator has to know — one line
 * when carrying stops working, one when it starts again, and one when the batches waiting have
 * grown into a weight on the system disk.
 */
final class LogCarrierAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_CARRIER;

    /** @var float Minimum seconds between two carries, and so between two walks of the staging directory */
    private const float CARRY_TICK_INTERVAL_SECONDS = 1.0;

    /** @var int Bytes in one mebibyte, the unit the staging complaint is worded in */
    private const int BYTES_PER_MEBIBYTE = 1024 * 1024;

    /**
     * @var int Weight of the waiting batches past which the staging directory is worth a line. A
     *     constant and not a setting: rotation fires at hundreds of mebibytes of live logs, so a
     *     batch or two waiting is ordinary traffic and only a pile that has stopped moving is news.
     */
    private const int STAGING_COMPLAINT_BYTES = 4096 * self::BYTES_PER_MEBIBYTE;

    /** @var ?LogBatchCarrier Carrier bound to this node's log root, or null when the env cannot name it */
    private ?LogBatchCarrier $carrier = null;

    /** @var float Timestamp of the last carry attempt, for throttling */
    private float $lastCarryAt = 0.0;

    /**
     * @var bool Last verdict of a carry, so only a crossing is reported. Seeded successful the way
     *     the store owner seeds its index readable: a start is not a crossing, and the first
     *     refusal is what the operator has to hear about.
     */
    private bool $carryVerdict = true;

    /** @var bool Whether the weight of the staging directory has already been complained about */
    private bool $stagingComplained = false;

    /**
     * Binds the carrier to this node's log root, or leaves the agent idle when there is no root.
     *
     * The same degrade the store owner makes: an environment that cannot name the log directory
     * leaves the agent running and doing nothing, rather than leaving the node without one.
     */
    public function onStart(): void
    {
        try {
            $this->carrier = new LogBatchCarrier(dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]));
        } catch (EnvException) {
            $this->carrier = null;
        }
    }

    /**
     * Carries the oldest waiting batch, at most one a second.
     *
     * Oldest first so the archive reads in the order the rotations happened, and one at a time
     * because the next tick is a second away and a batch that took longer than that has already
     * had the worker to itself for as long as it needed.
     */
    public function onTick(): void
    {
        $carrier = $this->carrier;
        if ($carrier === null) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastCarryAt < self::CARRY_TICK_INTERVAL_SECONDS) {
            return;
        }
        $this->lastCarryAt = $now;

        $batchNames = $carrier->pendingBatchNames();
        if ($batchNames === []) {
            // Nothing waiting weighs nothing, so the complaint is armed again without a walk.
            $this->stagingComplained = false;

            return;
        }

        $this->reportCarry($carrier->carry($batchNames[0]));
        $this->complainAboutStagingWeight($carrier);
    }

    /**
     * Nothing owned to release: no file is held open and the state of a carry is the directories.
     */
    public function onStop(): void
    {
        // No-op.
    }

    /**
     * Says one line whenever carrying starts or stops working, and nothing while the answer holds.
     *
     * A far volume that is full, unreachable or read-only refuses every tick, and a line a second
     * would bury the journal it is written into — this agent writes into the very directory the
     * node logs to. The crossing is what an operator acts on.
     *
     * @param LogCarryReport $report What the carry of this tick did
     */
    private function reportCarry(LogCarryReport $report): void
    {
        $carried = $report->failure === null;
        if ($carried === $this->carryVerdict) {
            return;
        }

        $this->carryVerdict = $carried;
        if ($carried) {
            $this->logAgentInfo("Log carry is going again: batch {$report->batchName} reached the archive");

            return;
        }

        $this->logAgentError("Log carry failed for batch {$report->batchName}: {$report->failure}");
    }

    /**
     * Says one line about a staging directory that has grown, and nothing while it stays grown.
     *
     * Separate from the carry verdict on purpose: the two answer different questions. Carrying can
     * be refused for an hour with nothing much waiting, and a node rotating hard can pile up a
     * weight worth naming while every carry still succeeds, just slower than the rotations.
     *
     * @param LogBatchCarrier $carrier Carrier naming the staging directory to weigh
     */
    private function complainAboutStagingWeight(LogBatchCarrier $carrier): void
    {
        $bytes = $carrier->stagingBytes();
        if ($bytes < self::STAGING_COMPLAINT_BYTES) {
            $this->stagingComplained = false;

            return;
        }
        if ($this->stagingComplained) {
            return;
        }

        $this->stagingComplained = true;
        $mebibytes = intdiv($bytes, self::BYTES_PER_MEBIBYTE);
        $this->logAgentWarning(
            "The log staging directory has grown to {$mebibytes} MiB while batches wait to be carried",
        );
    }
}
