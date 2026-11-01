<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ConnectionTestLivenessCommand - bring a liveness moment of one browser's sockets on this node to now.
 *
 * A browser socket is marked probably offline after a stretch of silence, closed after a longer
 * one, and checked against its runtime row on an interval of its own; a test that waited any of
 * them out would wait real time. This command moves the moment instead of the clock: the master
 * brings it to now on every live socket of the browser the session token names and keeps their
 * liveness on the spot, then answers how many sockets took it.
 *
 * A test-only command (extends {@see TestOnlyCommand}, so it refuses on a production-like env)
 * and database-free by contract: the master answers from the sockets it holds, so the CLI
 * process has nothing to read or write. Only this node is asked; a socket of the same browser
 * on another node of a cluster is answered for by that node.
 */
class ConnectionTestLivenessCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:connection:liveness)
     */
    public function getName(): string
    {
        return CliCommands::CONNECTION_TEST_LIVENESS;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return "Bring a liveness moment of one browser's sockets on this node to now (test-only)";
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:connection:liveness

Description:
  Bring one liveness moment of the live WebSocket connections of a browser to now,
  on this node, and run their liveness at once. The browser is named by its session
  token. probably-offline makes the silence reach the probably-offline span,
  close makes it reach the close timeout, and check makes the comparison against
  the connection's runtime row due. Fails when this node holds no live connection
  of that browser. Refuses on a production-like environment.

Usage:
  php cli.php test:connection:liveness <sessionToken> <probably-offline|close|check>

Examples:
  php cli.php test:connection:liveness a1b2c3d4e5f6 probably-offline
  php cli.php test:connection:liveness a1b2c3d4e5f6 check
HELP;
    }

    /**
     * Sends the liveness request and reports how many sockets took the moment.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the session token, then the moment
     * @return int Exit code (0 when at least one socket took the moment)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked two lines below
        $sessionToken = $args[0] ?? '';
        // external-boundary: the operator's command line, checked on the very next line
        $moment = $args[1] ?? '';
        if ($sessionToken === '' || $moment === '') {
            echo "Error: sessionToken and moment arguments are required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CliCommands::CONNECTION_TEST_LIVENESS, [
                CommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
                CommandConstants::FIELD_MOMENT => $moment,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CONNECTION_TEST_LIVENESS);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // The count is written on every successful reply by the master; a reply without it is an
        // incomplete answer, and printing it would report sockets nobody counted.
        $sockets = $reply->payload[CommandConstants::FIELD_SOCKETS] ?? null;
        if (!is_int($sockets)) {
            echo "Command failed: the reply names no socket count\n";
            return ExitCode::ERROR;
        }

        if ($sockets === 0) {
            echo "No live connection of this browser on this node\n";
            return ExitCode::ERROR;
        }

        echo "Brought {$moment} to now on {$sockets} socket(s)\n";

        return ExitCode::SUCCESS;
    }
}
