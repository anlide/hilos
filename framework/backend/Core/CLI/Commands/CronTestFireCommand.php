<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;

/**
 * Fires one cron rule once now by its ordinary path; the schedule does not move (test-only, HIL-1857).
 *
 * The CLI half only. A rule of the daemon is fired by the master ({@see DaemonManager}) at once;
 * a rule of an agent is armed by the worker running the agent ({@see CronRule::fireOnNextCheck()})
 * and fires at the agent's next check, after which its cron report and then the answer leave.
 * Printed is the moment it ran, in epoch milliseconds.
 */
class CronTestFireCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
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
        return CliCommands::CRON_TEST_FIRE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Fire one cron rule once now by its ordinary path; the schedule does not move (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()}

Description:
  Fire one cron rule once now, by the path its schedule takes, without waiting
  for its time. The owner is "daemon" for a rule of the daemon itself, fired by
  the leader at once, or the id of the agent holding the rule, which fires it at
  its next check; the answer comes after that agent has reported the run. The
  expression and the next scheduled run stay as they were, and the schedule does
  not fire the rule again in the same minute. Refuses an unknown rule, a daemon
  rule on a node that is not the leader or whose workers are not ready, an agent
  not running on this node, and a production-like environment.

Exit codes:
  0  the rule fired
  1  the daemon did not answer, refused, or answered without the moment
  2  the argument is missing or names no owner or no rule
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} <owner>/<rule>
HELP;
    }

    /**
     * Asks the master to fire the rule and prints the moment it ran.
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

        $firedAt = $result->reply->payload[CommandConstants::FIELD_FIRED_AT] ?? null;
        if (!is_int($firedAt)) {
            echo "Command failed: the reply carries no firing moment\n";

            return ExitCode::ERROR;
        }

        echo "Fired {$owner}/{$ruleName}: ran at epoch ms {$firedAt}\n";

        return ExitCode::SUCCESS;
    }
}
