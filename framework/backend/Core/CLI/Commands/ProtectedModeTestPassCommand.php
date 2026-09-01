<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;

/**
 * ProtectedModeTestPassCommand - mint one pass into a driven verification window (test-only).
 *
 * Until this existed no test could mint a code at all: the driven freeze is entered by the
 * hilos_index agent while {@see CliCommands::PROTECTED_MODE_PASS} routes to the agent that runs
 * real operations, which refuses a freeze it did not start. That only meant an e2e asserted a
 * field it could never fill - and once the field stops appearing before a code exists (HIL-616),
 * it would mean the test path loses the surface entirely.
 *
 * It cannot be {@see CliCommands::PROTECTED_MODE_PASS}, for the same reason
 * {@see ProtectedModeTestOpenCommand} cannot be the operator's open: a command routes to exactly
 * one agent type per project, and the freeze may only be driven by the agent the row records as
 * its initiator. Both names reach the same handler ({@see ProtectedModeOperatorTrait}) and mint
 * through the same request, so what a pass is does not differ between the two.
 *
 * Prints the clear key exactly as the operator's command does, and for the same reason: the row
 * keeps only its hash, so this line of output is the only place the key ever exists. Test-only
 * ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}) and database-free: this
 * process only writes to a socket.
 */
class ProtectedModeTestPassCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:protected-mode:pass)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_TEST_PASS;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Mint one pass into a driven protected-mode verification window and print it (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $leave = CliCommands::PROTECTED_MODE_TEST_LEAVE;
        $pass = CliCommands::PROTECTED_MODE_PASS;

        return <<<HELP
Command: {$this->getName()}

Description:
  Ask the initiator agent of a driven freeze to mint one pass, and print the clear key.
  The counterpart of {$pass}, which belongs to the agent that runs
  real operations and refuses a freeze it did not start. The mint itself is the same one:
  same request, same refusals, same single hash left on the row.

  Run it after {$leave} has opened the verification
  window; run it again for every further verifier. Only the hash is stored, so a lost key
  is replaced by minting another.

  Refuses on a production-like environment, where the operator command is the way in.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the agent for a pass and prints the clear key it minted.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        try {
            $result = $this->sendCommand($this->getName(), []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // external-boundary: a reply off the command channel, checked on the very next line
        $pass = $reply->payload[ProtectedModeCommandConstants::FIELD_PASS] ?? null;
        if (!is_string($pass) || $pass === '') {
            // The agent answers ok only once the hash is on the row, so a missing key here is a
            // broken reply rather than a refusal - and printing nothing would read as success.
            echo "The agent recorded a pass but returned no key\n";

            return ExitCode::ERROR;
        }

        echo "Pass: {$pass}\n";

        return ExitCode::SUCCESS;
    }
}
