<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;

/**
 * PingCommand - probe the daemon command channel.
 *
 * Sends a `ping` over the dedicated command socket and prints the echoed reply.
 * A health check for the CLI<->daemon command transport (the AsyncCommandClient /
 * CommandServer round-trip), separate from the HTTP `daemon:status` endpoint.
 */
class PingCommand implements CommandInterface
{
    use CommandChannelClientTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (daemon:ping)
     */
    public function getName(): string
    {
        return 'daemon:ping';
    }

    /**
     * Declares the rule: the daemon does the work and this process only initiates it and prints.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Ping the daemon over the command channel';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: daemon:ping

Description:
  Probe the CLI<->daemon command channel. Sends a ping over the dedicated
  command socket and prints the echoed reply. Useful to verify the command
  transport is up, independently of the HTTP status endpoint.

Usage:
  php cli.php daemon:ping [message]

Examples:
  php cli.php daemon:ping
  php cli.php daemon:ping hello
HELP;
    }

    /**
     * Execute ping command.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the echo message
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $message = $args[0] ?? CommandConstants::COMMAND_PING;
        echo "Pinging daemon command channel...\n";

        try {
            $result = $this->sendCommand(CommandConstants::COMMAND_PING, [CommandConstants::FIELD_MESSAGE => $message]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_PING);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            $detail = (string) ($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        // The echo of the very field this command put on the wire, mirrored back by the master.
        // A reply without it is a broken command channel, not a ping with nothing to say — and
        // printing "Reply (ok): " would read as a healthy daemon.
        $echoed = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
        if (!is_string($echoed)) {
            echo "Command failed: the reply carries no echoed message\n";

            return ExitCode::ERROR;
        }

        echo "Reply (ok): {$echoed}\n";

        return ExitCode::SUCCESS;
    }
}
