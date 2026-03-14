<?php

declare(strict_types=1);

namespace Hilos\Guardian\Bootstrap;

use Hilos\Guardian\Capabilities\NotesReadWriteCapability;
use Hilos\Guardian\Contracts\GuardianCapabilityInterface;

/**
 * Bootstrap for guardian capabilities.
 *
 * Provides default capability set for GuardianOpsAgent and related agents.
 */
final class GuardianBootstrap
{
    /**
     * Returns default guardian capabilities (NotesReadWriteCapability).
     *
     * @return array<string, GuardianCapabilityInterface> Capability name to instance mapping
     */
    public static function defaultCapabilities(): array
    {
        $notes = new NotesReadWriteCapability();
        return [$notes->getName() => $notes];
    }
}
