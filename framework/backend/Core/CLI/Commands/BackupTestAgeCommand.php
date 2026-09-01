<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupScope;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * BackupTestAgeCommand - age a stored backup's sidecar createdAt into the past (test-only).
 *
 * Retention buckets a backup by its sidecar createdAt (files=truth), which the create path stamps
 * at capture time. To assert rotation without waiting out real days or mocking the clock, a test
 * needs an existing backup to *look* older. This asks the running {@see BackupAgent} to rewrite
 * that one field, then prints what was written.
 *
 * The rewrite happens in the agent and not here because the catalog is the agent's to own: a CLI
 * process editing sidecars beside it would be racing its rescan. This half parses `--at` / `--days`
 * / `--scope` and renders the reply. Test-only ({@see AbstractCommandChannelTestCommand}).
 */
class BackupTestAgeCommand extends AbstractCommandChannelTestCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:backup:age)
     */
    public function getName(): string
    {
        return CliCommands::BACKUP_TEST_AGE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return "Age a stored backup's sidecar createdAt into the past (test-only)";
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:backup:age <id> (--at=<ISO-8601> | --days=<N>) [--scope=<scope>]

Description:
  Ask the running backup agent to rewrite a stored backup's sidecar createdAt, so retention
  treats it as older and rotation can be driven without waiting out real time. Only createdAt
  changes, and the agent re-mirrors its index from the rewritten sidecar. Refuses on a
  production-like environment.

Arguments:
  <id>              Backup id (the YYYY-MM-DD_HH-mm-ss stem)

Options:
  --at=<ISO-8601>   Explicit createdAt to write (mutually exclusive with --days)
  --days=<N>        Write a createdAt N days before now (mutually exclusive with --at)
  --scope=<scope>   Disambiguate when the id exists under more than one scope
                    (full | schema-seed | schema-only)

Usage:
  php cli.php test:backup:age 2026-07-19_10-30-00 --days=40
  php cli.php test:backup:age 2026-07-19_10-30-00 --at=2026-01-01T00:00:00+00:00 --scope=full
HELP;
    }

    /**
     * Sends the age request to the agent and prints the createdAt it wrote.
     *
     * @param array<string, mixed> $options Parsed options (--at | --days, optional --scope)
     * @param list<string> $args Positional args: [0] backup id
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $id = $args[0] ?? '';
        if ($id === '') {
            echo "Usage: test:backup:age <id> (--at=<ISO-8601> | --days=<N>) [--scope=<scope>]\n";
            return ExitCode::INVALID_ARGUMENT;
        }

        $instant = self::resolveInstantPayload($options);
        if ($instant === null) {
            echo "Specify exactly one of --at=<ISO-8601> or --days=<N>\n";
            return ExitCode::INVALID_ARGUMENT;
        }

        $payload = $instant + [BackupConstants::FIELD_BACKUP_ID => $id];
        $scopeOption = $options[BackupConstants::SCOPE_OPTION] ?? null;
        if ($scopeOption !== null) {
            $scope = is_string($scopeOption) ? BackupScope::fromString($scopeOption) : null;
            if ($scope === null) {
                echo "Unknown scope: {$scopeOption}\n";
                return ExitCode::INVALID_ARGUMENT;
            }
            $payload[BackupConstants::FIELD_SCOPE] = $scope->value;
        }

        try {
            $result = $this->sendCommand(CliCommands::BACKUP_TEST_AGE, $payload);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::BACKUP_TEST_AGE);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // The instant the agent actually stamped, echoed back. A reply without it is a broken
        // command channel, not an aging with nothing to report - and "createdAt=" would read as
        // a sidecar that was rewritten to nothing.
        $agedAt = $reply->payload[BackupConstants::FIELD_AGE_AT] ?? null;
        if (!is_string($agedAt)) {
            echo "Command failed: the reply carries no aged createdAt\n";

            return ExitCode::ERROR;
        }

        echo "Aged backup {$id} createdAt={$agedAt}\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Turns exactly one of --at / --days into the payload key the agent reads it under.
     *
     * The operator's own input is checked HERE, in the process the operator is standing in front
     * of, even though the agent checks the payload again on arrival. The two are not a duplicate:
     * this one keeps a typo from costing a round-trip and lets the answer name the option that was
     * typed, while the agent's guards a wire that has callers other than this command and can only
     * speak in payload keys.
     *
     * The scope option is left out on purpose: it is optional, while the instant is what the
     * request is FOR, so its absence is a usage error rather than a default.
     *
     * @param array<string, mixed> $options Parsed options
     * @return ?array<string, string|int> Single-key payload fragment, or null when neither/both/malformed
     */
    private static function resolveInstantPayload(array $options): ?array
    {
        $atGiven = array_key_exists(BackupConstants::AT_OPTION, $options);
        $daysGiven = array_key_exists(BackupConstants::DAYS_OPTION, $options);
        if ($atGiven === $daysGiven) {
            return null;
        }

        if ($atGiven) {
            $at = $options[BackupConstants::AT_OPTION];
            if (!is_string($at)) {
                return null;
            }

            // Parsed and re-formatted rather than forwarded as typed: a string the agent cannot
            // read has to be refused while the operator can still see which option they typed.
            try {
                $instant = new DateTimeImmutable($at);
            } catch (Exception) {
                return null;
            }

            return [BackupConstants::FIELD_AGE_AT => $instant->format(DateTimeInterface::ATOM)];
        }

        $days = $options[BackupConstants::DAYS_OPTION];
        if (!is_string($days) || !ctype_digit($days)) {
            return null;
        }

        return [BackupConstants::FIELD_AGE_DAYS => (int)$days];
    }
}
