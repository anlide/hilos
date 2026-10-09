<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Database\View\Item\UserRename;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\UserNotificationType;
use Hilos\Utils\Logger;

/**
 * What follows a person's rename: the news of it (HIL-1195, HIL-1404).
 *
 * The name and the journal row that records it are written by the person's own agent
 * ({@see AbstractUserAgent::renamePerson()}), in one transaction - a rename the journal does not
 * know of did not happen. What follows the commit is this library's: the renamed person is told
 * when somebody else did it, and the project's hook ({@see AbstractUsersLibraryAgent::afterUserRenamed()})
 * runs. Neither can take the rename back, so a failure of either is logged and the rename stands.
 */
final class UserRenameCommands extends AbstractLibraryCommands
{
    /**
     * Tells and hooks a rename the person's agent has committed.
     *
     * @param UserRename $rename Journal row of the rename just committed
     */
    public function afterRename(UserRename $rename): void
    {
        if ($rename->renamedByUserId !== $rename->userId) {
            $this->notifyRenamed($rename);
        }
        try {
            $this->library->afterUserRenamed($rename);
        } catch (HilosException $failure) {
            Logger::logAgentError(
                $this->library->getId(),
                "Rename hook failed for userId={$rename->userId}: {$failure->getMessage()}",
            );
        }
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
}
