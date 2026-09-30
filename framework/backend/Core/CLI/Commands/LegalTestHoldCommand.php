<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalHoldCommandConstants;
use Hilos\Legal\LegalStanding;

/**
 * Make a person hold an exact revision of a legal document through the live daemon (test-only).
 *
 * Forgets acceptances declared later than the named revision and records it if missing,
 * so that tests can place a user into covered, window or lapsed standing.
 * It is database-free because every row is written by the answering users library agent.
 */
final class LegalTestHoldCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * @return string Command name (test:legal:hold)
     */
    public function getName(): string
    {
        return CliCommands::LEGAL_TEST_HOLD;
    }

    /**
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Make a person hold an exact revision of a legal document (test-only)';
    }

    /**
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:legal:hold <userId> <document> <revisionId>

Description:
  Make a person hold an exact revision of a legal document through the live
  daemon. Refuses on a production-like environment.

Arguments:
  <userId>      User whose stored acceptances are modified
  <document>    Declared legal document (terms|privacy)
  <revisionId>  Exact revision the person should hold

Usage:
  php cli.php test:legal:hold 42 terms 2026-09-17
HELP;
    }

    /**
     * Sends the legal hold request and prints the resulting document and account standing.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] user id, [1] document, [2] revision id
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $userIdArgument = $args[0] ?? null;
        $documentArgument = $args[1] ?? null;
        $revisionId = $args[2] ?? null;

        $docList = implode('|', array_map(static fn(LegalDocument $d): string => $d->value, LegalDocument::cases()));
        if (
            !is_string($userIdArgument) || !ctype_digit($userIdArgument) || (int)$userIdArgument <= 0
            || !is_string($documentArgument) || LegalDocument::tryFrom($documentArgument) === null
            || !is_string($revisionId) || $revisionId === ''
        ) {
            echo "Usage: test:legal:hold <userId> <{$docList}> <revisionId>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $userId = (int)$userIdArgument;
        $document = $documentArgument;

        try {
            $result = $this->sendCommand(
                $this->getName(),
                [
                    LegalHoldCommandConstants::FIELD_USER_ID => $userId,
                    LegalHoldCommandConstants::FIELD_DOCUMENT => $document,
                    LegalHoldCommandConstants::FIELD_REVISION_ID => $revisionId,
                ],
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

        $standingRaw = $result->reply->payload[LegalHoldCommandConstants::FIELD_STANDING] ?? null;
        $standing = is_string($standingRaw) ? LegalStanding::tryFrom($standingRaw) : null;
        $frozen = $result->reply->payload[LegalHoldCommandConstants::FIELD_FROZEN] ?? null;

        if ($standing === null || !is_bool($frozen)) {
            echo "Command failed: the reply carries no standing\n";

            return ExitCode::ERROR;
        }

        $deadline = $result->reply->payload[LegalHoldCommandConstants::FIELD_DEADLINE] ?? null;
        $state = match ($standing) {
            LegalStanding::COVERED => 'covered',
            LegalStanding::WINDOW => "window until {$deadline}",
            LegalStanding::LAPSED => "lapsed since {$deadline}",
            LegalStanding::NONE => 'none',
        };

        $frozenText = $frozen ? 'frozen' : 'not frozen';
        echo "User {$userId} holds {$document} {$revisionId}: {$state}, account {$frozenText}\n";

        return ExitCode::SUCCESS;
    }
}
