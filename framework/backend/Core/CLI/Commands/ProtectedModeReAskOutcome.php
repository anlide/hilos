<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\ProtectedMode\ProtectedModeReAskVerdict;

/**
 * Verdict of a protected-mode re-ask together with the snapshot it was read from.
 */
final readonly class ProtectedModeReAskOutcome
{
    /**
     * @param ProtectedModeReAskVerdict $verdict Meaning of the re-asked state
     * @param array<string, mixed> $snapshot Snapshot behind the verdict, or empty when unknown
     * @param string $driveCommand Drive command whose reply was lost
     */
    public function __construct(
        public ProtectedModeReAskVerdict $verdict,
        public array $snapshot,
        public string $driveCommand,
    ) {
    }
}
