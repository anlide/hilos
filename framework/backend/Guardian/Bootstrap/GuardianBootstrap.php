<?php

declare(strict_types=1);

namespace Hilos\Guardian\Bootstrap;

use Hilos\Guardian\Capabilities\NotesReadWriteCapability;
use Hilos\Guardian\Contracts\GuardianCapabilityInterface;

final class GuardianBootstrap
{
    /**
     * @return array<string, GuardianCapabilityInterface>
     */
    public static function defaultCapabilities(): array
    {
        $notes = new NotesReadWriteCapability();
        return [$notes->getName() => $notes];
    }
}
