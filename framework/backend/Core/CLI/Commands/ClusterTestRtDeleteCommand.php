<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * Test-only command that asks this node's runtime set probe to remove a note (HIL-1178).
 *
 * The CLI only sends the request. The probe agent deletes the item through its runtime action,
 * so the same truth-source door judges Remove as it does any application write.
 */
class ClusterTestRtDeleteCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * @return string Command name
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_RT_DELETE;
    }

    /**
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Delete a row of a runtime set on this node of the cluster stand (test-only)';
    }

    /**
     * @return string Help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:rt:delete

Description:
  Ask this node's runtime set probe to delete a note through its truth-source
  door. A node that does not hold the note's set is refused. Refuses on a
  production-like environment.

Usage:
  php cli.php test:cluster:rt:delete <rowId>

Examples:
  php cli.php test:cluster:rt:delete note-1
HELP;
    }

    /**
     * Sends the delete request and reports which note was removed.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the row id
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked on the next line
        $stateId = $args[0] ?? '';
        if ($stateId === '') {
            echo "Error: rowId argument is required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_RT_DELETE, [
                CommandConstants::FIELD_RT_STATE_ID => $stateId,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_RT_DELETE);
        }

        $reply = $result->reply;
        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo "Deleted {$stateId}\n";

        return ExitCode::SUCCESS;
    }
}
