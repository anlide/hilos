<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
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
     */
    public function requestDisable(ProtectedModeDisableSignalData $data): void;
}
