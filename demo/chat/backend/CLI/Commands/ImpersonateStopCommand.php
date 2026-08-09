<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\Socket\Command\DTO\CommandReplyDTO;

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
        return ChatCliCommands::IMPERSONATE_STOP;
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
  Revert an impersonating session back to its admin. Sends an impersonateStop
  command over the daemon command channel; the daemon routes it to the chat
  agent, which restores the recorded admin (every tab of that session updates
  back to the admin) and clears the impersonator marker. The session must
  currently be impersonating.

Usage:
  php cli.php impersonate:stop <sessionToken>

Examples:
  php cli.php impersonate:stop 0123456789abcdef0123456789abcdef
HELP;
    }

    protected function commandName(): string
    {
        return ChatCommandConstants::IMPERSONATE_STOP;
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
            ChatCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
        ];
    }

    protected function describeSuccess(CommandReplyDTO $reply): string
    {
        $effective = (int) ($reply->payload[ChatCommandConstants::FIELD_EFFECTIVE_USER_ID] ?? 0);

        return "Reply (ok): impersonation stopped; session now acts as admin #{$effective}";
    }
}
