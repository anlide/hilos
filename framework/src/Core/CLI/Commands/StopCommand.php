<?php

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliInterface;

/**
 * StopCommand - команда остановки daemon
 */
class StopCommand
{
    private CliInterface $cli;

    public function __construct()
    {
        $this->cli = new CliInterface();
    }

    /**
     * Execute stop command
     */
    public function execute(array $args): void
    {
        echo "Stopping daemon...\n";
        $this->cli->stopDaemon();
    }
}
