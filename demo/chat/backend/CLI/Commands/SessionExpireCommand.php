<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\HilosException;

/**
 * SessionExpireCommand - age a live session's expiry into the past (test-only).
 *
 * Test-only (extends {@see TestOnlyCommand}, so it refuses on a production-like env):
 * an e2e author (HIL-167) needs an expired session to drive the session-expiry logout
 * without waiting out the session TTL. Selects the session by the cookie token the e2e
 * holds and rewrites its `expires_at` into the past; the drop to anonymous happens on
 * the next resolution (HIL-398), not here. The CLI has no agent, so the command
 * registers itself as the sessions truth source before the write, mirroring the
 * test:orphan:* commands.
 */
final class SessionExpireCommand extends TestOnlyCommand
{
    /** @var string Truth-source id this CLI writer registers under (no agent runs in the CLI) */
    private const string TRUTH_SOURCE_ID = 'test-cli';

    public function getName(): string
    {
        return ChatCliCommands::SESSION_EXPIRE;
    }

    public function getDescription(): string
    {
        return 'Test-only: expire a live session by its cookie token';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Ages the session identified by <token> so its expiry is in the past, letting an e2e
drive the session-expiry logout without waiting out the session TTL. It only writes the
column; the live connection is dropped to anonymous by the next resolution (HIL-398).

Usage:
  php cli.php test:session:expire <token>

  <token> is the 32 hex character session cookie the e2e holds.
HELP;
    }

    /**
     * Expires the session holding the given cookie token.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] token
     * @return int Exit code (0 on success)
     * @throws HilosException When the lookup or expiry write fails
     */
    protected function run(array $options, array $args): int
    {
        $token = $args[0] ?? '';
        if ($token === '') {
            echo "Usage: {$this->getName()} <token>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        if (Hilos::$db === null) {
            echo "Error: sessions collection is not available\n";

            return ExitCode::CONFIG_ERROR;
        }

        // The CLI has no agent-writer for sessions, so the command registers itself as the truth
        // source before mutating (only reachable on the test-only path, same as test:orphan:*).
        TruthSourceRegistry::register(ChatDbContext::sessions, true, self::TRUTH_SOURCE_ID);
        $session = Hilos::$db->sessions->actions->expireByToken($token);
        if ($session === null) {
            echo "No session for token {$token}\n";

            return ExitCode::ERROR;
        }

        echo "Expired session (id={$session->id}, token={$session->token}, expires_at={$session->expiresAt})\n";

        return ExitCode::SUCCESS;
    }
}
