<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

use Hilos\Core\Router\SignalDataInterface;

/**
 * Config keys for per-command entries in AbstractAgent::AGENT_COMMANDS.
 *
 * Unlike agent signals, commands route to an agent type (not a specific
 * multi-instance agent), so there is no index-field key. What a command entry may
 * declare beyond its route is the inner payload DTO the framework hydrates at
 * dispatch, and the receipt address payload fields captured in the change log:
 *
 * ```php
 * public const array AGENT_COMMANDS = [
 *     MyCommand::PLAIN_COMMAND,
 *     MyCommand::TYPED_COMMAND => MyCommandRequestData::class,
 *     MyCommand::CONFIG_COMMAND => [
 *         AgentCommandConfigKey::DTO => MyCommandRequestData::class,
 *         AgentCommandConfigKey::ADDRESS => ['settingKey'],
 *     ],
 * ];
 * ```
 */
final class AgentCommandConfigKey
{
    /**
     * Inner payload DTO class for topology-driven parsing at dispatch time.
     *
     * @var string Config key naming the {@see SignalDataInterface} class hydrated at dispatch
     */
    public const string DTO = 'dto';

    /**
     * Receipt address payload fields captured in the change log.
     *
     * @var string Config key naming the list of payload field names captured in the receipt source
     */
    public const string ADDRESS = 'address';
}
