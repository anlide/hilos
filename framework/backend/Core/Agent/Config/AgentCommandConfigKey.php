<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

/**
 * Config keys for per-command entries in AbstractAgent::AGENT_COMMANDS.
 *
 * Unlike agent signals, commands route to an agent type (not a specific
 * multi-instance agent), so there is no index-field key. A command entry may
 * optionally declare the inner payload DTO the framework hydrates at dispatch:
 *
 * ```php
 * public const array AGENT_COMMANDS = [
 *     MyCommand::PLAIN_COMMAND,
 *     MyCommand::TYPED_COMMAND => MyCommandRequestData::class,
 *     MyCommand::CONFIG_COMMAND => [
 *         AgentCommandConfigKey::DTO => MyCommandRequestData::class,
 *     ],
 * ];
 * ```
 */
final class AgentCommandConfigKey
{
    /**
     * Inner payload DTO class for topology-driven parsing at dispatch time.
     */
    public const string DTO = 'dto';
}
