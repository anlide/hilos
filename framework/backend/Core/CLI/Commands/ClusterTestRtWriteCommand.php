<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestRtWriteCommand - Write a row of a runtime set on one node of the cluster stand (HIL-1116)
 *
 * A test-only driver (extends {@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}, so it refuses on a production-like env). It makes
 * "node A writes its set, node B may not" sayable: the command names the set the row goes into,
 * and the node's own probe agent writes it through the ordinary runtime actions - so the door
 * that allows or refuses the write is the truth-source door every application write passes,
 * and not an imitation of it. A refusal comes back in the door's own words.
 *
 * Which collection is written, and whose set the node holds, is the stand's to know: the
 * framework only carries the name, as it does for {@see ClusterTestDbWriteCommand}.
 *
 * Database-free by contract: the CLI process talks to nothing but the local command socket, and
 * the node it reaches does the runtime work.
 */
class ClusterTestRtWriteCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:rt:write)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_RT_WRITE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Write a row of a runtime set on this node of the cluster stand (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:rt:write

Description:
  Ask this node's runtime set probe to write a row into the named set: a row
  that does not exist yet is created in that set, one that does gets its text
  rewritten. The write passes the truth-source door like any other, so a node
  that does not hold the set is refused, and the refusal is printed as the door
  worded it.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:rt:write <setKey> <rowId> <text>

Examples:
  php cli.php test:cluster:rt:write s1 note-1 v1
HELP;
    }

    /**
     * Sends the write request and reports what was written.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the set key, the row id, then the text
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked three lines below
        $setKey = $args[0] ?? '';
        // external-boundary: the harness's command line, checked two lines below
        $stateId = $args[1] ?? '';
        // external-boundary: the harness's command line, checked on the very next line
        $text = $args[2] ?? '';
        if ($setKey === '' || $stateId === '' || $text === '') {
            echo "Error: setKey, rowId and text arguments are required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_RT_WRITE, [
                CommandConstants::FIELD_RT_SET_KEY => $setKey,
                CommandConstants::FIELD_RT_STATE_ID => $stateId,
                CommandConstants::FIELD_TEXT => $text,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_RT_WRITE);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo "Wrote {$stateId} into set {$setKey}\n";

        return ExitCode::SUCCESS;
    }
}
