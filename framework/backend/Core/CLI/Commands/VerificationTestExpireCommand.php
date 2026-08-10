<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;

/**
 * VerificationTestExpireCommand - age a live auth verification challenge into the past.
 *
 * Test-only (extends {@see TestOnlyCommand}, so it refuses on a production-like env):
 * an e2e author (HIL-167) needs an expired reset / verify / sms challenge to assert
 * the "invalid or expired code" path without waiting out the TTL or mocking the clock.
 * It takes the challenge type and identifier, finds the newest active challenge for
 * that pair (attempts ignored), and rewrites its `expires_at` into the past through
 * the verification layer's expire primitive; the existing
 * {@see ObjectUserVerification::isActive()} gate then reads it
 * as expired on the next verify. Registered framework-wide (all projects, non-prod),
 * mirroring {@see ClusterTestInspectCommand}.
 */
class VerificationTestExpireCommand extends TestOnlyCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (verification:test:expire)
     */
    public function getName(): string
    {
        return CliCommands::VERIFICATION_TEST_EXPIRE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Expire an active auth verification challenge (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: verification:test:expire <type> <identifier>

Description:
  Age the newest active verification challenge for <type> and <identifier> into the
  past so the next verify reads it as expired. Drives the "invalid or expired code"
  e2e path without waiting out the TTL. Refuses on a production-like environment.

Arguments:
  <type>        Verification type: register_confirm | password_reset | email_change | sms_login | magic_link
  <identifier>  Normalized identifier (lowercased email, or E.164 phone for sms_login)

Usage:
  php cli.php verification:test:expire password_reset user@example.com
HELP;
    }

    /**
     * Expires the newest active challenge for the given (type, identifier).
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] type, [1] identifier
     * @return int Exit code (0 on success)
     * @throws DatabaseException When the lookup or expiry query fails
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, both checked on the very next line
        $type = $args[0] ?? '';
        // external-boundary: the operator's command line, both checked on the very next line
        $identifier = $args[1] ?? '';
        if (!VerificationType::isValid($type) || $identifier === '') {
            echo "Usage: verification:test:expire <type> <identifier>\n";
            return ExitCode::INVALID_ARGUMENT;
        }

        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);
        if (!$collection instanceof ObjectUserVerifications) {
            echo "Error: verifications collection is not available\n";
            return ExitCode::CONFIG_ERROR;
        }

        $challenge = $collection->expireActive($type, $identifier);
        if ($challenge === null) {
            echo "No active {$type} challenge for {$identifier}\n";
            return ExitCode::ERROR;
        }

        echo "Expired {$type} challenge for {$identifier} (id={$challenge->id}, expires_at={$challenge->expiresAt})\n";
        return ExitCode::SUCCESS;
    }
}
