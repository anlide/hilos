<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;

/**
 * DemoHilosLogsAgent - Concrete Hilos logs section agent for the tasks demo.
 *
 * Serves every page of the logs section in the demo project - overview, keys, workers, rotations,
 * the viewer and the logging modes - and holds the cluster log picture they are drawn from.
 */
final class DemoHilosLogsAgent extends AbstractHilosLogsAgent
{
}
