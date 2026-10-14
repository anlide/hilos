<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager - the place a project adds commands of its own, kept empty on purpose.
 *
 * All current commands belong to the framework, including the feature-aware
 * paired test database reset. This override demonstrates the project seam.
 */
final class ChatCliManager extends CliManager
{
    /** No project commands are registered; the framework supplies the complete registry. */
    protected function registerProjectCommands(): void
    {
    }
}
