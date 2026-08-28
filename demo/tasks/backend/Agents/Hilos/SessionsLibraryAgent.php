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
 * The tasks demo's sessions library - and the two seams a project can be asked to answer.
 *
 * Everything a session is lives in {@see AbstractSessionsLibraryAgent} (HIL-710). What this
 * demo has to say for itself is the end of the two operator paths to an administrator:
 * {@see CliCommands::ADMIN_CREATE}, which has to be able to mint the first account because
 * this demo has no login of its own, and {@see CliCommands::ADMIN_GRANT}, which names a user
 * that already exists. The framework resolves the session, binds it and tells the tabs; the
 * rows are this demo's.
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

    /**
     * Writes the admin flag of one user - and nothing else.
     *
     * The framework half of the grant ends at this seam: the command is validated and
     * answered there, and what a user row is lives here. Telling the person's open tabs is
     * the library's too since HIL-729 - it states the session and this demo's project agent
     * says it out loud, which is the one path every other identity change already travels.
     *
     * It writes under the same claim {@see self::onStart()} makes for the minting seam above:
     * the truth-source registry is per process, and this library has its own.
     *
     * @param int $userId Target user id, already validated as positive
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When no user carries that id
     * @throws HilosException On database failure while writing the flag
     */
    protected function applyAdminGrant(int $userId, bool $admin): void
    {
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }

        $user->actions->setAdmin($admin);
    }
}
