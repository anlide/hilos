<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\Users\AdminCommandConstants;

/**
 * AdminCreateCommand - make one browser session an administrator, minting its user when
 * the session carries none.
 *
 * The command {@see AdminGrantCommand} needs and cannot be: the grant flags a user row that
 * exists, and on a fresh installation none does - the admin pages are shut, so there is no
 * surface to register through and no id to name. This addresses a SESSION by its cookie
 * token, which is the one thing a browser always has.
 *
 * Not a subclass of {@see AbstractSetAdminCommand}: that base is built around a target user
 * id and a flag to set, and this command carries neither. The exit ladder is the same one
 * all the same, so an operator reads both commands' failures alike.
 *
 * Database-free ({@see DatabaseFreeCommand}) for the same reason its neighbours are: every
 * row it causes is written by the agent that answers it ({@see AbstractSessionsLibraryAgent}), which is
 * also the only process holding the session's live sockets.
 */
class AdminCreateCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Makes the named session an administrator through the daemon command channel.
     *
     * The argument is checked here only for being present; its format and the session it
     * names are the agent's to judge, and come back as an error reply. That is the split
     * {@see AbstractSetAdminCommand} makes for a positive user id.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the session cookie token
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $sessionToken = trim((string)($args[0] ?? ''));
        if ($sessionToken === '') {
            echo "A session token is required (e.g. {$this->getName()} 3f2a...)\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        echo "Making the session an administrator...\n";

        try {
            $result = $this->sendCommand($this->getName(), [
                AdminCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
            ]);
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

        $userId = (int)($reply->payload[AdminCommandConstants::FIELD_USER_ID] ?? 0);
        if ((bool)($reply->payload[AdminCommandConstants::FIELD_EXPIRED] ?? false)) {
            // Said on its own line and before the outcome, because the outcome names a user
            // the operator has never seen and reads as a mistake until this explains it.
            echo "The session had expired: its previous user was unbound by the expiry rule.\n";
        }
        $outcome = ((bool)($reply->payload[AdminCommandConstants::FIELD_CREATED] ?? false))
            ? "created user #{$userId} as an administrator"
            : "made existing user #{$userId} an administrator";
        echo "Reply (ok): {$outcome}\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (admin:create)
     */
    public function getName(): string
    {
        return CliCommands::ADMIN_CREATE;
    }

    /**
     * Declares the rule: the daemon does the work and this process only initiates it and prints.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Make a browser session an administrator';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * Names where the token is read from, because the operator path is unwalkable without
     * it: the cookie is HttpOnly and the page shows no token anywhere.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $cookieEnv = EnvConstants::HILOS_SESSION_COOKIE_NAME->name;

        return <<<HELP
Command: {$this->getName()} <sessionToken>

Description:
  Make the browser session named by the token an administrator. A session that already
  carries a user has that user flagged; one that carries none has a user minted for it.
  A session whose expiry has passed loses the user it carried, exactly as it would on a
  handshake, so the administrator is a new user and the command says so.
  The running daemon writes the row, binds the session and re-sends the handshake
  response to that session's open tabs, so the admin entry appears without a reload.
  Granting a session that is already an admin is not an error.

Arguments:
  <sessionToken>   Value of the session cookie (default name hilos_session_token,
                   renamed through {$cookieEnv}). Read it in the browser's DevTools under
                   Application - Cookies: the cookie is HttpOnly, so no page shows it.

Exit codes:
  0  the session is now an administrator
  1  the daemon did not answer, refused the command, or knows no such session
  2  the session token argument is missing
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} 4f9c1b8e2d7a6053c4e1f8b90a2d3c56
HELP;
    }
}
