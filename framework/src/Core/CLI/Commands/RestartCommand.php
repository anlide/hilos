<?php

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliInterface;
use Hilos\Utils\Constants\CliConstants;

/**
 * RestartCommand - команда перезапуска daemon
 */
class RestartCommand
{
    private CliInterface $cli;

    public function __construct()
    {
        $this->cli = new CliInterface();
    }

    /**
     * Execute restart command
     */
    public function execute(array $args): void
    {
        echo "Restarting daemon...\n";
        
        // Stop daemon if running
        if ($this->cli->isDaemonRunning()) {
            echo "Stopping current daemon...\n";
            $this->cli->stopDaemon();
            
            // Wait a bit for graceful shutdown
            sleep(2);
        }
        
        // Start daemon
        $foreground = in_array(CliConstants::OPTION_FOREGROUND, $args);
        echo "Starting daemon...\n";
        $this->cli->startDaemon($foreground);
    }
}
