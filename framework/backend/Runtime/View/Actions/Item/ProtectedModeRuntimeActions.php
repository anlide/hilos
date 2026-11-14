<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Cluster\Peer\DTO\PeerProtectedModeSyncDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeWatchdogSnapshot;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime as ViewProtectedModeRuntime;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

/**
 * Write operations for the protected-mode runtime singleton.
 *
 * One method per phase of the freeze, mirroring the transitions the cluster orchestration
 * decides: the executor says which phase this node entered, and the row plus the RT sync
 * that carries it are this class's business. The daemon master of each node is the
 * registered truth source; {@see RtActions::ensureCanWrite()} enforces that.
 *
 * @extends RtActions<ViewProtectedModeRuntime, StateProtectedModeRuntime>
 * @property-read StateProtectedModeRuntime $state
 */
final class ProtectedModeRuntimeActions extends RtActions
{
    /**
     * Puts back the freeze this node went down under, read off the disk it was left on.
     *
     * The one write that establishes a freeze without a transition deciding it: the row is memory
     * only, so without this a restart under a freeze comes back open and serves clients over a
     * database whose restore never finished. It runs during startup, before any server binds, so
     * the first handshake after the restart is already locked out.
     *
     * **The browser the freeze was started from is carried; everything else a connection was
     * recognized by is dropped.** The accept key cannot be anything but dropped: it is minted on
     * the 101 and does not outlive the process that minted it. The passes and the sessions they
     * admitted are a decision rather than a consequence - a pass lasts minutes and is read out to
     * a verifier by voice, so one who was already inside asks for another instead of keeping the
     * run of a node over a cookie that outlived the daemon.
     *
     * The initiator's session hash stays for the reason the other three go: the exit from the
     * verification window stopped being terminal-only. Since HIL-676 it is a button on the backup
     * page, gated on {@see StateProtectedModeRuntime::belongsToInitiator()}, which refuses null -
     * so dropping the hash locked out no stranger, it locked out the one operator who started the
     * operation and left them a terminal as their only door. What comes back is therefore a row
     * that locks out everybody except that browser, and the operator lifts it either by the
     * button or by the ladder ({@see enterInactive()}) once they have looked at the data.
     * A manual maintenance window is different: its initiator has no browser exit button, so its
     * session hash is dropped too. After a restart an operator enters with a new maintenance pass
     * or opens the node from the console. This exception follows the operation and entry mode,
     * not the phase; a restore verification window still keeps its initiating browser. The
     * separate entered-from-browser fact survives either kind of restart for Maintenance's source label.
     * Live agent diagnostics, node reports and watchdog presentation are discarded even if the descriptor carries
     * them. They stay unpublished until this node's master publishes its current values again.
     *
     * TODO(HIL-93): the right to enter a frozen node belongs to the roles subsystem (HIL-93
     * anchors it, HIL-97 enforces it); until it exists this half of the decision is a session
     * hash, which names a browser rather than a person.
     *
     * @param StateProtectedModeRuntime $row Freeze row as it was left on disk
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function restoreFromDisk(StateProtectedModeRuntime $row): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::phase => $row->phase,
            StateProtectedModeRuntime::entryMode => $row->entryMode,
            StateProtectedModeRuntime::operation => $row->operation,
            StateProtectedModeRuntime::freezeId => $row->freezeId,
            StateProtectedModeRuntime::initiatorAcceptKey => null,
            StateProtectedModeRuntime::initiatorSessionTokenHash => $row->isManualMaintenanceWindow()
                ? null
                : $row->initiatorSessionTokenHash,
            StateProtectedModeRuntime::initiatorAgentType => $row->initiatorAgentType,
            StateProtectedModeRuntime::initiatorAgentIndex => $row->initiatorAgentIndex,
            StateProtectedModeRuntime::initiatorNodeId => $row->initiatorNodeId,
            StateProtectedModeRuntime::startedAt => $row->startedAt,
            StateProtectedModeRuntime::activatedAt => $row->activatedAt,
            StateProtectedModeRuntime::progressAt => $row->progressAt,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => $row->enteredFromBrowser,
            StateProtectedModeRuntime::diagnosticsNodeId => null,
            StateProtectedModeRuntime::stoppedAgentCount => null,
            StateProtectedModeRuntime::preservedAgentTypes => null,
            StateProtectedModeRuntime::agentStartGateClosed => null,
            StateProtectedModeRuntime::maintenanceNodes => null,
            StateProtectedModeRuntime::watchdogVerdict => null,
            StateProtectedModeRuntime::watchdogPendingNodeIds => [],
            StateProtectedModeRuntime::watchdogQuiesceTimeoutSeconds => null,
            StateProtectedModeRuntime::watchdogSilenceTimeoutSeconds => null,
            StateProtectedModeRuntime::watchdogAlertIntervalSeconds => null,
            StateProtectedModeRuntime::watchdogAlertEmails => null,
            StateProtectedModeRuntime::watchdogManualRestartIsNormal => null,
        ]);
    }

    /**
     * Assigns only a legacy generation; phase, rights, clocks and roster diagnostics stay intact.
     *
     * @param string $freezeId Locally generated nonempty correlation
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function bootstrapFreezeId(string $freezeId): void
    {
        $this->ensureCanWrite();
        if ($this->state->phase === StateProtectedModeRuntime::PHASE_INACTIVE || $this->state->freezeId !== null) {
            return;
        }
        $this->applyDiffWithSync([
            StateProtectedModeRuntime::freezeId => $freezeId,
        ]);
    }

    /**
     * Imports the leader's descriptor and existing rights at the phase local effects have earned.
     * The caller keeps a full freeze closed until stop/catch-up has finished.
     *
     * @param PeerProtectedModeSyncDTO $snapshot Validated occupied snapshot
     * @param string $phase Locally safe phase
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function importSlaveSync(PeerProtectedModeSyncDTO $snapshot, string $phase): void
    {
        $this->ensureCanWrite();
        $freeze = $snapshot->payload;
        if ($freeze === null) {
            return;
        }
        $diff = [
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::entryMode => $snapshot->entryMode,
            StateProtectedModeRuntime::operation => $freeze->operation,
            StateProtectedModeRuntime::freezeId => $freeze->freezeId,
            StateProtectedModeRuntime::initiatorAcceptKey => null,
            StateProtectedModeRuntime::initiatorSessionTokenHash => $freeze->initiatorSessionTokenHash,
            StateProtectedModeRuntime::enteredFromBrowser => $freeze->initiatorSessionTokenHash !== null,
            StateProtectedModeRuntime::initiatorAgentType => $freeze->initiatorAgentType,
            StateProtectedModeRuntime::initiatorAgentIndex => $freeze->initiatorAgentIndex,
            StateProtectedModeRuntime::initiatorNodeId => $freeze->initiatorNodeId,
            StateProtectedModeRuntime::passHashes => $snapshot->passHashes,
            StateProtectedModeRuntime::admittedSessionTokenHashes => $snapshot->admittedSessionTokenHashes,
            StateProtectedModeRuntime::circleNamedCount => $snapshot->circleNamedCount,
            StateProtectedModeRuntime::circleSessionTokenHashes => $snapshot->circleSessionTokenHashes,
        ];
        if ($this->state->freezeId !== $freeze->freezeId) {
            $diff[StateProtectedModeRuntime::startedAt] = time();
            $diff[StateProtectedModeRuntime::activatedAt] = null;
            $diff[StateProtectedModeRuntime::progressAt] = null;
        }
        $this->applyDiffWithSync($diff);
    }

    /**
     * Publishes the local master's diagnostic node projection to this node's workers on change.
     * The array is the six-field runtime serialization boundary, not a freeze control payload.
     *
     * @param ?list<array<string, string|bool|null>> $nodes Node reports, or null while unavailable or unpublished
     * @throws InvalidFormatException When a report does not match the projection shape
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function updateMaintenanceNodes(?array $nodes): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::maintenanceNodes => $nodes,
        ]);
    }

    /**
     * Publishes one validated watchdog judgment and configuration to this node's workers.
     * The freeze descriptor is untouched; none of these values survives a restart.
     *
     * @param ProtectedModeWatchdogSnapshot $snapshot Current judgment and effective configuration
     * @throws InvalidFormatException When a verdict or string list is malformed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function updateWatchdog(ProtectedModeWatchdogSnapshot $snapshot): void
    {
        $this->ensureCanWrite();
        $validated = ProtectedModeWatchdogSnapshot::fromArray($snapshot->toArray());

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::watchdogVerdict => $validated->watchdogVerdict,
            StateProtectedModeRuntime::watchdogPendingNodeIds => $validated->watchdogPendingNodeIds,
            StateProtectedModeRuntime::watchdogQuiesceTimeoutSeconds => $validated->watchdogQuiesceTimeoutSeconds,
            StateProtectedModeRuntime::watchdogSilenceTimeoutSeconds => $validated->watchdogSilenceTimeoutSeconds,
            StateProtectedModeRuntime::watchdogAlertIntervalSeconds => $validated->watchdogAlertIntervalSeconds,
            StateProtectedModeRuntime::watchdogAlertEmails => $validated->watchdogAlertEmails,
            StateProtectedModeRuntime::watchdogManualRestartIsNormal => $validated->watchdogManualRestartIsNormal,
        ]);
    }

    /**
     * Publishes this node's agent-stop diagnostics onto the runtime row.
     *
     * The four values are a live fact this node's master holds. The write goes through this
     * mounted item action and syncs to the node's workers; the freeze file is not touched.
     * An empty preserved-type list is a published fact that the full-stop policy names no
     * exception. A list that is not a list of strings is refused by the same row reader the
     * freeze uses, before any field is written and before the sync.
     *
     * @param string $nodeId Node the counts describe
     * @param int $stoppedAgentCount Agents this node is holding stopped
     * @param array<mixed> $preservedAgentTypes Agent types the full-stop policy leaves running
     * @param bool $agentStartGateClosed Whether agent start is actually closed on this node
     * @throws InvalidFormatException When the preserved agent types are not a list of strings
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function updateAgentDiagnostics(
        string $nodeId,
        int $stoppedAgentCount,
        array $preservedAgentTypes,
        bool $agentStartGateClosed,
    ): void {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::preservedAgentTypes => $preservedAgentTypes,
            StateProtectedModeRuntime::diagnosticsNodeId => $nodeId,
            StateProtectedModeRuntime::stoppedAgentCount => $stoppedAgentCount,
            StateProtectedModeRuntime::agentStartGateClosed => $agentStartGateClosed,
        ]);
    }

    /**
     * Records the freeze this node is entering and the identity allowed through it.
     *
     * The descriptor carries the operator's session hash to every master (HIL-1305), while the
     * accept key stays on the node of its socket. Neither admits anyone during frozen phases.
     *
     * A new freeze starts with no passes, nobody admitted and nobody named, and that is written
     * rather than assumed: {@see ViewProtectedModeRuntime::admits()} reads the frozen phases as
     * empty by construction, and three paths can arrive here holding an abandoned window's hashes -
     * a demoted leader still on verifying, quiesced again by whoever took leadership, a repeat
     * entry from the verification window (HIL-1057), and the close back from it (HIL-1128). Every
     * other way in passes through a clear already, so this costs three assignments and closes the
     * one hole where a voided pass, or a circle photographed for the previous operation, could
     * admit its holder to the next one.
     *
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity the freeze protects
     * @param ?string $initiatorAcceptKey Accept key recorded here and admitted once the verification
     *                                    window opens; null on a follower node
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVATING,
            StateProtectedModeRuntime::entryMode => StateProtectedModeRuntime::ENTRY_MODE_FREEZE,
            StateProtectedModeRuntime::operation => $freeze->operation,
            StateProtectedModeRuntime::freezeId => $freeze->freezeId,
            StateProtectedModeRuntime::initiatorAcceptKey => $initiatorAcceptKey,
            StateProtectedModeRuntime::initiatorSessionTokenHash => $freeze->initiatorSessionTokenHash,
            StateProtectedModeRuntime::enteredFromBrowser => $freeze->initiatorSessionTokenHash !== null,
            StateProtectedModeRuntime::initiatorAgentType => $freeze->initiatorAgentType,
            StateProtectedModeRuntime::initiatorAgentIndex => $freeze->initiatorAgentIndex,
            StateProtectedModeRuntime::initiatorNodeId => $freeze->initiatorNodeId,
            StateProtectedModeRuntime::startedAt => time(),
            StateProtectedModeRuntime::activatedAt => null,
            StateProtectedModeRuntime::progressAt => null,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::watchdogVerdict => null,
            StateProtectedModeRuntime::watchdogPendingNodeIds => [],
        ]);
    }

    /**
     * Opens a window directly while leaving the node's agents running.
     *
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity
     * @param ?string $initiatorAcceptKey Initiating connection, or null for CLI entry
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function enterVerificationWindow(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->ensureCanWrite();

        $now = time();
        $this->applyDiffWithSync([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::entryMode => StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
            StateProtectedModeRuntime::operation => $freeze->operation,
            StateProtectedModeRuntime::freezeId => $freeze->freezeId,
            StateProtectedModeRuntime::initiatorAcceptKey => $initiatorAcceptKey,
            StateProtectedModeRuntime::initiatorSessionTokenHash => $freeze->initiatorSessionTokenHash,
            StateProtectedModeRuntime::enteredFromBrowser => $freeze->initiatorSessionTokenHash !== null,
            StateProtectedModeRuntime::initiatorAgentType => $freeze->initiatorAgentType,
            StateProtectedModeRuntime::initiatorAgentIndex => $freeze->initiatorAgentIndex,
            StateProtectedModeRuntime::initiatorNodeId => $freeze->initiatorNodeId,
            StateProtectedModeRuntime::startedAt => $now,
            StateProtectedModeRuntime::activatedAt => null,
            StateProtectedModeRuntime::progressAt => $now,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::watchdogVerdict => null,
            StateProtectedModeRuntime::watchdogPendingNodeIds => [],
        ]);
    }

    /**
     * Marks the freeze fully established: every node has quiesced.
     *
     * Every way into active now runs through {@see enterActivating()}, the operator closing the
     * system back from {@see enterVerifying()} included (HIL-1128), so the passes, the admissions
     * they earned and the photographed circle are already empty here and the clear costs nothing.
     * It stays as a guard: a pass that outlived the verification it was minted for would let its
     * holder in during the next operation, and a circle photographed before one database
     * replacement says nothing about who should be inside the next.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function enterActive(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE,
            StateProtectedModeRuntime::activatedAt => time(),
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
        ]);
    }

    /**
     * Opens the verification window: the operation is over, but the mode is not lifted yet.
     *
     * The operation and the whole initiator identity stay on the row - the initiator still
     * drives, and it is the only one that may end this phase in either direction.
     *
     * Reaching the window is itself progress, so it is stamped as one: the operation that just
     * finished may well have been silent for longer than the watchdog's threshold, and without
     * this stamp the window would be reported stuck the moment it opened, for the silence of the
     * step before it. What it is instead given is a full threshold of its own - a window nobody
     * ever ends is a real condition, and it has to be reported on its own account.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function enterVerifying(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::progressAt => time(),
        ]);
    }

    /**
     * Stamps the row with the moment the operation behind the freeze last moved.
     *
     * The one write on this class that changes no phase: it records life, not a transition. The
     * value is taken here, on the master that owns the row, and never from the request that asked
     * for it - a mark carried on the wire would let a node with a skewed clock decide how long
     * another node's freeze may stay silent.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markProgress(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::progressAt => time(),
        ]);
    }

    /**
     * Records one more pass minted for the verification in flight.
     *
     * Only the hash is stored; the clear key travels from the operator's terminal to the
     * verifier's browser and exists nowhere on this row.
     *
     * @param string $passHash SHA-256 of the minted pass
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function issuePass(string $passHash): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync($this->state->appendedListDiff(StateProtectedModeRuntime::passHashes, $passHash));
    }

    /**
     * Records a browser session that presented a valid pass as let in.
     *
     * One entry per admitted session, and presenting the same pass again from a second tab of the
     * same browser adds nothing: the row is keyed by the session behind the connection, so one
     * browser is one entry however many sockets it opens. Without the guard the list would grow
     * by one on every reconnect of every tab of a verifier who is already inside. What bounds it
     * beyond that is the window rather than the count - both ways out of it ({@see enterActive()},
     * {@see enterInactive()}) clear the lists, and it is open for as long as an operator is
     * looking at the system.
     *
     * Membership is tested with {@see hash_equals()} rather than {@see in_array()} for the reason
     * {@see StateProtectedModeRuntime::admits()} compares that way: both sides are derived from a
     * secret.
     *
     * @param string $sessionTokenHash Hash of the admitted browser's session token
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function admitSession(string $sessionTokenHash): void
    {
        $this->ensureCanWrite();

        foreach ($this->state->admittedSessionTokenHashes as $admittedSessionTokenHash) {
            if (hash_equals($admittedSessionTokenHash, $sessionTokenHash)) {
                return;
            }
        }

        $this->applyDiffWithSync(
            $this->state->appendedListDiff(StateProtectedModeRuntime::admittedSessionTokenHashes, $sessionTokenHash),
        );
    }

    /**
     * Writes the verifier circle photographed on the initiator's ready relay, whole.
     *
     * Whole rather than one hash at a time, which is what tells it apart from
     * {@see admitSession()}: for a restore it is read under the freeze and before the database is
     * replaced; for direct entry it is read at closing. The standalone switch accepts only the
     * first direct photograph, so a repeat ready cannot replace who was admitted.
     *
     * A freeze of an installation that named nobody writes nothing and syncs nothing: the row is
     * already empty there - both ways into the freeze clear it - so the diff to every worker on
     * the node would say nothing anyway. A circle that WAS named syncs even when the photograph
     * came out empty, because the count is the difference between "nobody was named" and "nobody
     * named was online", and only the row can still tell them apart afterwards.
     *
     * @param VerifierCircleSnapshot $snapshot Circle photographed on the initiator's ready relay
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function admitCircle(VerifierCircleSnapshot $snapshot): void
    {
        $this->ensureCanWrite();

        if ($snapshot->namedCount === 0) {
            return;
        }

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::circleSessionTokenHashes => $snapshot->sessionTokenHashes,
            StateProtectedModeRuntime::circleNamedCount => $snapshot->namedCount,
        ]);
    }

    /**
     * Marks the freeze as lifting; the lockdown stays up until it is inactive.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function enterDeactivating(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_DEACTIVATING,
        ]);
    }

    /**
     * Lifts the freeze and forgets the initiator.
     *
     * The whole identity is cleared with the phase: a stale accept key left on the row would hand
     * one connection a privilege after the operation that earned it is over, and a stale session
     * hash would hand it to a whole browser, for as long as its cookie lives. The passes, the
     * sessions they admitted and the photographed circle go the same way and for the same reason.
     *
     * A terminal transition: it takes the service reserve of the source's FIFO and is never refused
     * for want of room - one the reserve cannot take now is owed and retried as the FIFO frees.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function enterInactive(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffAsCleanup([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_INACTIVE,
            StateProtectedModeRuntime::entryMode => null,
            StateProtectedModeRuntime::operation => null,
            StateProtectedModeRuntime::freezeId => null,
            StateProtectedModeRuntime::initiatorAcceptKey => null,
            StateProtectedModeRuntime::initiatorSessionTokenHash => null,
            StateProtectedModeRuntime::enteredFromBrowser => false,
            StateProtectedModeRuntime::initiatorAgentType => null,
            StateProtectedModeRuntime::initiatorAgentIndex => null,
            StateProtectedModeRuntime::initiatorNodeId => null,
            StateProtectedModeRuntime::startedAt => null,
            StateProtectedModeRuntime::activatedAt => null,
            StateProtectedModeRuntime::progressAt => null,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::watchdogVerdict => null,
            StateProtectedModeRuntime::watchdogPendingNodeIds => [],
        ]);
    }

    /**
     * Invalidates analytics and cues the native consumer before a potentially refused sync.
     *
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    protected function sync(): void
    {
        Hilos::$ac?->invalidateProtectedMode();
        Hilos::$rt?->noteProtectedModeChanged();
        parent::sync();
    }
}
