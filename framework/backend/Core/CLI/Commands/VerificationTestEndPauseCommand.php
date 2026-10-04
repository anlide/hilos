<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\Verification\VerificationEndPauseCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/** Asks the live users library to end one browser's resend pause (test-only). */
final class VerificationTestEndPauseCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::VERIFICATION_TEST_END_PAUSE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return "End the resend pause on one address and move the Send again moment of that browser's line to now (test-only)";
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:verification:end-pause <address> <sessionToken>

Description:
  End the resend pause on one address and move the Send again moment of that
  browser's line to now. Refuses on a production-like environment.

Arguments:
  <address>       Email address or phone number whose pause ends
  <sessionToken>  Cookie token of the browser whose line moves

Usage:
  php cli.php test:verification:end-pause person@example.test cookie-token
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Address and session cookie token
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $address = $args[0] ?? null;
        $sessionToken = $args[1] ?? null;
        if (!is_string($address) || trim($address) === '' || !is_string($sessionToken) || trim($sessionToken) === '') {
            echo "Usage: test:verification:end-pause <address> <sessionToken>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), [
                VerificationEndPauseCommandConstants::FIELD_ADDRESS => $address,
                VerificationEndPauseCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
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

        $aged = $result->reply->payload[VerificationEndPauseCommandConstants::FIELD_AGED] ?? null;
        if (!is_int($aged)) {
            echo "Command failed: the reply carries no aged-row count\n";

            return ExitCode::ERROR;
        }

        echo "Ended resend pause for {$address}; aged {$aged} verification rows\n";

        return ExitCode::SUCCESS;
    }
}
