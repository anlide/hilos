<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Environment\Exception\EnvException;

/** Master entrance reserved for page requests admitted in a registered worker. */
interface PageAgentRestartDoor
{
    /**
     * @param DaemonAgentRestartRequestSignalData $request Page-admitted request and return address
     * @throws EnvException When the local cluster configuration cannot be read
     * @throws ClusterConfigurationException When this master's node identity is invalid
     * @throws ClusterDisabledException When an enabled cluster refuses its identity
     */
    public function requestPageAgentRestart(DaemonAgentRestartRequestSignalData $request): void;
}
