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
     * Inner payload DTO class for topology-driven parsing at dispatch time.
     */
    public const string DTO = 'dto';
}
