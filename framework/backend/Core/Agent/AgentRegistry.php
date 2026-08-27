<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
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
        AgentRegistryKey::SCOPE,
        AgentRegistryKey::PLACEMENT,
        AgentRegistryKey::IDLE_TIMEOUT,
    ];

    /**
     * Seconds of silence after which an instance agent that declares no number of its own is
     * stopped.
     *
     * The framework carries the number so a project declares the policy by pointing at it rather
     * than by inventing a duration it has no way to reason about. Taken from the user task of the
     * hleb installation, which has run this exact shape of life for years.
     */
    public const int DEFAULT_IDLE_TIMEOUT_SEC = 240;

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

    /**
     * How many instances of the agent type exist.
     *
     * An entry that declares nothing, or declares something other than a scope case, reads as
     * {@see AgentScope::CLUSTER}: an undeclared axis must under-run an agent, never double-run a
     * truth source. Topology validation is what reports the malformed declaration.
     *
     * @param mixed $registryEntry Raw Hilos::AGENTS entry for one agent type
     * @return AgentScope Declared scope, or the cluster-wide default
     */
    public static function scope(mixed $registryEntry): AgentScope
    {
        if (!is_array($registryEntry)) {
            return AgentScope::CLUSTER;
        }

        $scope = $registryEntry[AgentRegistryKey::SCOPE] ?? null;

        return $scope instanceof AgentScope ? $scope : AgentScope::CLUSTER;
    }

    /**
     * Who picks the node a cluster-wide agent runs on.
     *
     * Reads as {@see AgentPlacement::LEADER} when undeclared or malformed, which is what every
     * agent did before the axes arrived. Meaningless next to {@see AgentScope::NODE}, and
     * topology validation refuses that pair rather than silently ignoring it.
     *
     * @param mixed $registryEntry Raw Hilos::AGENTS entry for one agent type
     * @return AgentPlacement Declared placement, or the leader-hosted default
     */
    public static function placement(mixed $registryEntry): AgentPlacement
    {
        if (!is_array($registryEntry)) {
            return AgentPlacement::LEADER;
        }

        $placement = $registryEntry[AgentRegistryKey::PLACEMENT] ?? null;

        return $placement instanceof AgentPlacement ? $placement : AgentPlacement::LEADER;
    }

    /**
     * How long the agent may sit unaddressed before it is stopped, or null when it lives forever.
     *
     * Null is the answer for an entry that declares nothing, and equally for one whose value is
     * not a positive whole number of seconds: an undeclared or malformed window must leave the
     * agent alive rather than guess a duration at which to kill it. Topology validation is what
     * reports the malformed declaration, exactly as it does for {@see self::scope()}.
     *
     * @param mixed $registryEntry Raw Hilos::AGENTS entry for one agent type
     * @return ?int Declared idle window in seconds, or null when the agent declares none
     */
    public static function idleTimeout(mixed $registryEntry): ?int
    {
        if (!is_array($registryEntry)) {
            return null;
        }

        $idleTimeout = $registryEntry[AgentRegistryKey::IDLE_TIMEOUT] ?? null;

        return is_int($idleTimeout) && $idleTimeout > 0 ? $idleTimeout : null;
    }

    /**
     * Whether the entry opts into an every-node start pass rather than a single cluster-wide
     * instance.
     *
     * A readable shorthand over the single source of truth, not a second one: it is exactly
     * {@see self::scope()} answering {@see AgentScope::NODE}.
     *
     * @param mixed $registryEntry Raw Hilos::AGENTS entry for one agent type
     * @return bool True when the entry declares {@see AgentScope::NODE}
     */
    public static function startsOnEveryNode(mixed $registryEntry): bool
    {
        return self::scope($registryEntry) === AgentScope::NODE;
    }
}
