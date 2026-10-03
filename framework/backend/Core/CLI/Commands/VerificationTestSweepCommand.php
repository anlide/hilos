<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\Verification\VerificationSweepCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/** Ages one address and sweeps through the live users library (test-only, database-free). */
final class VerificationTestSweepCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::VERIFICATION_TEST_SWEEP;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Age and sweep verification codes on one address (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:verification:sweep <identifier>

Description:
  Age every verification code of <identifier> past the retention and run one
  sweep now through the running users library; a live code stays.
  Refuses on a production-like environment.

Arguments:
  <identifier>  Address whose verification rows are aged

Usage:
  php cli.php test:verification:sweep person@example.test
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] identifier
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $identifier = $args[0] ?? null;
        if (!is_string($identifier) || trim($identifier) === '') {
            echo "Usage: test:verification:sweep <identifier>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand(
                $this->getName(),
                [VerificationSweepCommandConstants::FIELD_IDENTIFIER => $identifier],
            );
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

        $removed = $result->reply->payload[VerificationSweepCommandConstants::FIELD_REMOVED] ?? null;
        $kept = $result->reply->payload[VerificationSweepCommandConstants::FIELD_KEPT] ?? null;
        if (!is_int($removed) || !is_int($kept)) {
            echo "Command failed: the reply carries no sweep counts\n";

            return ExitCode::ERROR;
        }

        echo "Swept {$removed} verification rows; {$kept} rows of {$identifier} kept\n";

        return ExitCode::SUCCESS;
    }
}
