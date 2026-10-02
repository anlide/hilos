<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\File;
use Hilos\Database\View\Item\Session;
use Hilos\Files\Library\AbstractFilesLibraryAgent;

/**
 * The chat demo's files library: the framework's, letting the readers of the chat see what is
 * attached to its messages (HIL-336, HIL-144).
 *
 * The framework owns the table, the frames and the janitor ({@see AbstractFilesLibraryAgent}).
 * The chat publishes its attachments for anyone signed in, and widens that by one rule: a file
 * attached to a message is served to any session at all - a guest's, or one that has expired -
 * because a guest reads the chat's feed and sees its pictures, as before the registry held them
 * (HIL-138 left this decision to the chat). A request presenting no session is still refused.
 *
 * Registered under {@see HilosAgentType::HILOS_FILES_LIBRARY} by this demo's own topology, which
 * {@see HilosFeature::FILES} requires of every project declaring it.
 */
final class FilesLibraryAgent extends AbstractFilesLibraryAgent
{
    /** @var list<string> The attachments, which decide whether a guest is let in */
    public const array READS_DB = [...parent::READS_DB, ChatDbContext::eventAttachments];

    /**
     * Lets any session read a file attached to a message of the feed.
     *
     * @param File $file Registry row of the file asked for
     * @param ?Session $session Session the request presented, or null when it presented none
     * @return bool True when a session asks for a file some message carries
     * @throws DatabaseException When the attachments cannot be read
     */
    protected function grantsRead(File $file, ?Session $session): bool
    {
        return $session !== null && $file->id !== null && Hilos::$db->eventAttachments->forFileId($file->id) !== null;
    }
}
