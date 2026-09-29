<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\Database;
use Hilos\Database\View\Item\UserRename;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\UserNotificationType;
use Hilos\Utils\Logger;

/**
 * Renaming a person: the name, the journal row that records it, and what follows (HIL-1195).
 *
 * The name and the row are one transaction - a rename the journal does not know of did not
 * happen. What follows the commit is news: the renamed person is told when somebody else did
 * it, and the project's hook ({@see AbstractUsersLibraryAgent::afterUserRenamed()}) runs.
 * Neither can take the rename back, so a failure of either is logged and the rename stands.
 */
final class UserRenameCommands extends AbstractLibraryCommands
{
    /**
     * Renames one person and records who did it.
     *
     * A name that is already the person's, once trimmed, writes nothing - no journal row, no
     * notice, no hook - and answers null: nothing happened that anybody should hear of.
     *
     * @param int $userId Person to rename
     * @param string $newName Name to give; trimmed and held to the frame of {@see UserActions::rename()}
     * @param ?int $renamedByUserId Person who did the rename - the renamed person's own id when they renamed
     *     themselves - or null when the author is not a person
     * @return ?UserRename Journal row of this rename, or null when the name was already the person's
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the name is empty, too short or too long
     * @throws HilosException When the name, the journal row or the transaction cannot be written
     */
    public function rename(int $userId, string $newName, ?int $renamedByUserId): ?UserRename
    {
        $user = Hilos::$db->users[$userId];
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }
        $oldName = $user->name;
        if (trim($newName) === $oldName) {
            return null;
        }

        Database::transactionStart();
        try {
            $user->actions->rename($newName);
            $rename = Hilos::$db->userRenames->actions->add($userId, $renamedByUserId, $oldName, $user->name);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            $this->rollBack();

            throw $failure;
        }

        if ($renamedByUserId !== $userId) {
            $this->notifyRenamed($rename);
        }
        try {
            $this->library->afterUserRenamed($rename);
        } catch (HilosException $failure) {
            Logger::logAgentError($this->library->getId(), "Rename hook failed for userId={$userId}: {$failure->getMessage()}");
        }

        return $rename;
    }

    /**
     * Tells the renamed person that somebody else changed their name.
     *
     * Best-effort: the rename and its journal row stand whatever happens to the notice.
     *
     * @param UserRename $rename Journal row of the rename just committed
     */
    private function notifyRenamed(UserRename $rename): void
    {
        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $rename->userId,
                type: UserNotificationType::RENAMED,
                title: 'An administrator renamed your account',
                severity: NotificationSeverity::INFO,
                body: 'Your name is now ' . $rename->newName,
                data: [
                    'oldName' => $rename->oldName,
                    'newName' => $rename->newName,
                    'actorUserId' => $rename->renamedByUserId,
                ],
            ));
        } catch (HilosException $failure) {
            Logger::logAgentError(
                $this->library->getId(),
                "Rename notification failed for userId={$rename->userId}: {$failure->getMessage()}",
            );
        }
    }

    /**
     * Rolls back a failed rename without letting the cleanup replace the failure.
     *
     * The connection under the transaction belongs to the worker and outlives the action, so
     * a transaction left open would take in every later write that worker makes.
     */
    private function rollBack(): void
    {
        try {
            Database::transactionRollback();
        } catch (HilosException) {
            // Reporting the cleanup would replace the failure the caller is owed
        }
    }
}
