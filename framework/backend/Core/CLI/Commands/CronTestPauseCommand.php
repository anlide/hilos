<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/** CLI entrance for pausing one current cron rule's natural schedule. */
class CronTestPauseCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** Owner the operator names for a rule of the daemon itself, sent as no agent on the wire. */
    private const string DAEMON_OWNER = 'daemon';

    /** Separator between the owner and the rule name in the one argument. */
    private const string OWNER_SEPARATOR = '/';

    /** The argument is cut at its first separator, so a rule name may carry the separator itself. */
    private const int ARGUMENT_PARTS = 2;

    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::CRON_TEST_PAUSE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Pause the natural schedule of one current cron rule (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()}

Description:
  Pause the natural schedule of one current rule. The owner is "daemon" for a
  rule of the leader's master, or the id of the agent holding the rule on this
  node. Explicit test firing still works. The returned pause id cancels only
  this pause; the pause disappears when this rule instance is replaced.
  Refuses a production-like environment.

Exit codes:
  0  the pause was applied
  1  the daemon did not answer, refused, or answered without a pause id
  2  the argument is missing or names no owner or no rule
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} <owner>/<rule>
HELP;
    }

    /**
     * Asks the owner to pause the schedule and prints the exact cancellation key.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional arguments: one, the owner and the rule joined by "/"
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $parts = count($args) === 1 ? explode(self::OWNER_SEPARATOR, $args[0], self::ARGUMENT_PARTS) : [];
        if (count($parts) !== self::ARGUMENT_PARTS || $parts[0] === '' || $parts[1] === '') {
            echo "Usage: {$this->getName()} <owner>/<rule>\n";

            return ExitCode::INVALID_ARGUMENT;
        }
        [$owner, $ruleName] = $parts;

        try {
            $result = $this->sendCommand($this->getName(), [
                CommandConstants::FIELD_AGENT_ID => $owner === self::DAEMON_OWNER ? null : $owner,
                CommandConstants::FIELD_RULE_NAME => $ruleName,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }

        $pauseId = $result->reply->payload[CommandConstants::FIELD_PAUSE_ID] ?? null;
        if (!is_string($pauseId) || $pauseId === '') {
            $this->printToStandardError("Command failed: the reply carries no pause id");

            return ExitCode::ERROR;
        }

        echo "Paused {$owner}/{$ruleName}: pause {$pauseId}\n";

        return ExitCode::SUCCESS;
    }
}
