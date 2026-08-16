<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Client\WorkerClient;

/**
 * The initiator side of protected mode: what an initiator's own daemon can ask for.
 *
 * An initiator agent never talks to this seam directly - it queues its request as a signal,
 * its worker forwards the frame to its own master daemon, and the daemon hands the payload
 * here ({@see WorkerClient}). What happens next is a topology decision the agent must not know
 * about: {@see ClusterProtectedMode} routes the request to the leader and drives the peer rounds,
 * while {@see StandaloneProtectedMode} freezes the single node on the spot. Both live behind this
 * interface so the request path above it is one path.
 */
interface ProtectedModeSwitch
{
    /**
     * Asks for the freeze that protects a destructive operation.
     *
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestEnable(ProtectedModeEnableSignalData $data): void;

    /**
     * Asks to lift the freeze once the destructive operation has finished.
     *
     * The carried identity is how a single node authorizes the release: with no peers there is
     * no node id to compare, so the initiator agent names itself instead. The clustered
     * implementation authorizes by initiator node id and ignores it.
     *
     * @param ProtectedModeDisableSignalData $data Identity of the agent asking for the release
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestDisable(ProtectedModeDisableSignalData $data): void;

    /**
     * Asks to open the verification window once the destructive operation has finished.
     *
     * The step {@see requestDisable()} used to be reached in: the operation is over, the system
     * stays closed to everyone, and a hand-picked circle is let in by pass to confirm it came
     * back. Authorized exactly like the release, and fail-closed on the phase - only a freeze
     * that reached {@see ProtectedModeRuntime::PHASE_ACTIVE} has an operation to verify.
     *
     * @param ProtectedModeVerifySignalData $data Identity of the agent asking for the window
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestVerify(ProtectedModeVerifySignalData $data): void;

    /**
     * Reports that the operation the freeze protects has moved.
     *
     * The proof of life a stuck-freeze watchdog reads, and the one request here that changes no
     * phase and answers nobody: it stamps {@see ProtectedModeRuntime::$progressAt} and stops. The
     * moment is taken by the master that owns the row rather than from this payload, so the mark
     * travels as a bare fact and a node with a skewed clock cannot push another node's silence
     * threshold around.
     *
     * Unlike every other request here it is not fail-closed on a phase: a mark that arrives under
     * no freeze, or one phase later than the sender thought, is dropped or written harmlessly.
     * The cost of a lost mark is a false alarm on an honest long operation, which is a message;
     * the cost of a refused freeze is a system nobody can get back.
     *
     * @param ProtectedModeProgressSignalData $data Identity of the agent reporting the progress
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestProgress(ProtectedModeProgressSignalData $data): void;

    /**
     * Asks to record one more pass for the verification window in flight.
     *
     * Only the hash reaches this seam; the clear key stays in the operator's terminal. Refused
     * unless the mode is on {@see ProtectedModeRuntime::PHASE_VERIFYING}: a pass minted for a
     * window that is not open would sit on the row waiting for one.
     *
     * @param ProtectedModePassSignalData $data Minting agent identity and the hash of the pass
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function requestPass(ProtectedModePassSignalData $data): void;

    /**
     * Asks to close the system again from the verification window, voiding every pass.
     *
     * The other exit from the window, and the reason the operator can act on what the verifiers
     * found without first opening the system to real users. Refused unless the mode is on
     * {@see ProtectedModeRuntime::PHASE_VERIFYING}.
     *
     * @param ProtectedModeRefreezeSignalData $data Identity of the agent asking to close back
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void;
}
