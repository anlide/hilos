<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Reads worker and daemon classes from Hilos::AGENTS registry entries.
 */
final class AgentRegistry
{
    /** @var list<string> */
    public const array ALLOWED_CONFIG_KEYS = [
        AgentRegistryKey::WORKER,
        AgentRegistryKey::DAEMON,
        AgentRegistryKey::INDEXED,
    ];

    /**
     * @return ?class-string<AbstractAgent>
     */
    public static function workerClass(mixed $registryEntry): ?string
    {
        if (!is_array($registryEntry)) {
            return null;
        }

        $workerClass = $registryEntry[AgentRegistryKey::WORKER] ?? null;

        return is_string($workerClass) && $workerClass !== '' ? $workerClass : null;
    }

    /**
     * @return ?class-string<AbstractAgentDaemon>
     */
    public static function daemonClass(mixed $registryEntry): ?string
    {
        if (!is_array($registryEntry)) {
            return null;
        }

        $daemonClass = $registryEntry[AgentRegistryKey::DAEMON] ?? null;

        return is_string($daemonClass) && $daemonClass !== '' ? $daemonClass : null;
    }

    public static function requiresIndex(mixed $registryEntry): bool
    {
        if (!is_array($registryEntry)) {
            return false;
        }

        return ($registryEntry[AgentRegistryKey::INDEXED] ?? false) === true;
    }
}
