<?php

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliInterface;
use Hilos\Utils\Constants\CliConstants;

/**
 * StartCommand - команда запуска daemon
 */
class StartCommand
{
    private CliInterface $cli;

    public function __construct()
    {
        $this->cli = new CliInterface();
    }

    /**
     * Execute start command
     */
    public function execute(array $args): void
    {
        $foreground = in_array(CliConstants::OPTION_FOREGROUND, $args);
        
        if ($foreground) {
            echo "Starting daemon in foreground mode...\n";
        } else {
            echo "Starting daemon in background mode...\n";
        }
        
        $this->cli->startDaemon($foreground);
    }
}