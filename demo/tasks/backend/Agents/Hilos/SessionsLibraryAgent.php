<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents\Hilos;

use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Hilos;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;

/**
 * The tasks demo's sessions library - and the one seam a project can be asked to answer.
 *
 * Everything a session is lives in {@see AbstractSessionsLibraryAgent} (HIL-710). What this
 * demo has to say for itself is the end of {@see CliCommands::ADMIN_CREATE}: it has no login,
 * so nothing in the product can hand out the flag the admin pages ask for, and the operator's
 * command has to be able to mint the first account. The framework resolves the session and
 * binds it; the row is this demo's.
 *
 * Registered under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY} by this demo's own topology,
 * which is also what makes the handshake arrive here rather than in {@see TasksAgent}.
 */
final class SessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
    /**
     * Claims the users table this library mints into, from its OWN process.
     *
     * The truth-source registry is per process, so the claim the project agent makes covers
     * that agent's worker and nothing else: without this the minted administrator would be
     * refused as a write with no truth source behind it.
     *
     * @throws EnvException When the sweep schedule key is missing, outside the catalog, or of the wrong type
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(TasksDbContext::users);
    }

    /**
     * Makes one user an administrator, minting the row when the session carries none.
     *
     * The session bind around this is the framework's; all that happens here is the user
     * table.
     *
     * @param ?int $userId User the session carries, or null when it carries none
     * @return int Id of the user that is now an administrator
     * @throws ItemNotFoundForUpdateException When the id names no user row
     * @throws HilosException On database failure while minting or flagging
     */
    protected function ensureAdminUser(?int $userId): int
    {
        if ($userId === null) {
            return (int)Hilos::$db->users->actions->registerAdmin()->id;
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }

        $user->actions->setAdmin(true);

        return $userId;
    }
}
