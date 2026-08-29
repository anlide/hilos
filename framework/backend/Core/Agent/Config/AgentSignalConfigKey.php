<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

/**
 * Config keys for per-signal entries in AbstractAgent::AGENT_SIGNALS.
 *
 * Used when an agent signal must route to a specific indexed (multi-instance) agent.
 * Declare the payload field that carries the agent index:
 *
 * ```php
 * public const array AGENT_SIGNALS = [
 *     MySignal::SINGLETON_SIGNAL => MySingletonSignalData::class,
 *     MySignal::INDEXED_SIGNAL => [
 *         AgentSignalConfigKey::INDEX_FIELD => 'entityId',
 *         AgentSignalConfigKey::DTO => MyIndexedSignalData::class,
 *     ],
 *     MySignal::NODE_LOCAL_SIGNAL => [
 *         AgentSignalConfigKey::NODE_FIELD => 'nodeId',
 *         AgentSignalConfigKey::DTO => MyNodeLocalSignalData::class,
 *     ],
 * ];
 * ```
 *
 * The framework reads the named field from the inner payload's toArray() at dispatch time.
 */
final class AgentSignalConfigKey
{
    /**
     * Payload field whose value becomes the agent index for multi-instance routing.
     *
     * Accepted field values: positive int or non-empty string.
     */
    public const string INDEX_FIELD = 'indexField';

    /**
     * Payload field whose value names the cluster node hosting the target agent.
     *
     * An empty or absent value means the addressee is on this node, and the signal
     * routes exactly as it would without the declaration; a non-empty value is the
     * id of the node to address, and routing honours it in place of the placement
     * lookup. This is where the key differs from {@see self::INDEX_FIELD}, which
     * treats an empty value as an error: a node id is legitimately empty off a
     * cluster, where the single node publishes itself under one.
     */
    public const string NODE_FIELD = 'nodeField';

    /**
     * Inner payload DTO class for topology-driven parsing at dispatch time.
     */
    public const string DTO = 'dto';
}
