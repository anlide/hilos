<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * Base for test-only CLI commands that drive a running agent over the command channel.
 *
 * Extends {@see TestOnlyCommand} (so the whole family refuses on a production-like env) and
 * carries the {@see CommandChannelClientTrait} round-trip {@see PingCommand} pioneered: open
 * the socket, send a {@see CommandRequestDTO}, poll until a reply or timeout, and hand the
 * reply back. Subclasses build the payload, name the command, and render the reply.
 *
 * The round-trip itself lives in the trait because operator commands need it too, and they
 * must not inherit the test-only refusal.
 */
abstract class AbstractCommandChannelTestCommand extends TestOnlyCommand
{
    use CommandChannelClientTrait;
}
