<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\Session\SessionRebindConstants;
use Hilos\Constants\CliCommands;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Users\AdminCommandConstants;

/**
 * Stops impersonating on a session through the daemon command channel.
 *
 * Parameter-free beyond the session token — the admin to restore comes from the
 * impersonator marker the session recorded on impersonate:start.
 */
final class ImpersonateStopCommand extends AbstractImpersonateCommand
{
    public function getName(): string
    {
        return CliCommands::IMPERSONATE_STOP;
    }

    public function getDescription(): string
    {
        return 'Revert an impersonating session back to its admin through the daemon command channel';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: impersonate:stop

Description:
  Revert an impersonating session back to its admin. Sends the command over the
  daemon command channel; the daemon restores the recorded admin (every tab of
  that session updates back to the admin) and clears the impersonator marker.
  The session must currently be impersonating.

Usage:
  php cli.php impersonate:stop <sessionToken>

Examples:
  php cli.php impersonate:stop 0123456789abcdef0123456789abcdef
HELP;
    }

    protected function buildPayload(array $args): ?array
    {
        // external-boundary: a positional argument the operator may omit; the usage hint below rejects it
        $sessionToken = (string) ($args[0] ?? '');

        if ($sessionToken === '') {
            echo "A session token is required (e.g. {$this->getName()} <sessionToken>)\n";

            return null;
        }

        return [
            AdminCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
        ];
    }

    /**
     * The reply is written by the sessions library that carried the rebind out, and its keys
     * are the frame's own field names (HIL-710): it reports the state read back off the row
     * rather than the state that was asked for.
     *
     * @param CommandReplyDTO $reply Successful reply from the daemon command channel
     * @return string Line shown to the operator
     */
    protected function describeSuccess(CommandReplyDTO $reply): string
    {
        // external-boundary: the daemon's reply payload is untyped on this side
        $effective = (int) ($reply->payload[SessionRebindConstants::FIELD_USER_ID] ?? 0);

        return "Reply (ok): impersonation stopped; session now acts as admin #{$effective}";
    }
}
