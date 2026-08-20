<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

use Hilos\Socket\Command\TestOnlyCommandRegistry;

/**
 * Config keys for per-command entries in AbstractAgent::AGENT_COMMANDS.
 *
 * Unlike agent signals, commands route to an agent type (not a specific
 * multi-instance agent), so there is no index-field key. A command entry may
 * optionally declare the inner payload DTO the framework hydrates at dispatch,
 * and whether the command is test-only:
 *
 * ```php
 * public const array AGENT_COMMANDS = [
 *     MyCommand::PLAIN_COMMAND,
 *     MyCommand::TYPED_COMMAND => MyCommandRequestData::class,
 *     MyCommand::CONFIG_COMMAND => [
 *         AgentCommandConfigKey::DTO => MyCommandRequestData::class,
 *         AgentCommandConfigKey::TEST_ONLY => true,
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

    /**
     * Whether the command may only run outside a production-like environment.
     *
     * The machine-readable half of the test-only contract: the command socket reads
     * this flag through {@see TestOnlyCommandRegistry} and refuses the command before
     * it is parked for its agent. The human-readable half is the `test:` prefix the
     * command name carries on the wire; topology validation sews the two together, so
     * a flag here without the prefix (or the prefix without the flag) fails the start.
     */
    public const string TEST_ONLY = 'testOnly';
}
