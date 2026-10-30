<?php

declare(strict_types=1);

namespace Demo\Chat\Files;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Files\FileVisibility;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\Files\Upload\Check\DuplicateContentCheck;
use Hilos\HilosException;

/**
 * The chat's one upload target: a file attached to a message (HIL-144).
 *
 * The composer sends each file through the framework's browser client under this name; the
 * main page publishes the complete uploads into the files registry once moderation approves
 * the message they ride. Only a signed-in person sends one - a guest reads the chat and may not
 * write in it. The type is read from the content, so a file is judged by what it is rather than
 * by what the browser called it, against the same list the composer's file picker offers.
 */
final class ChatAttachmentUploadTarget extends AbstractUploadTarget
{
    /** Name the browser declares a chat attachment under. */
    public const string NAME = 'chat_attachment';

    /** Image copy the feed shows in place of an attached picture. */
    public const string THUMB_VARIANT = 'chat_thumb';

    /** Types the composer's file picker offers; content of any other type is refused. */
    private const array ACCEPTED_MIME_TYPES = ['image/*', 'application/pdf', 'text/plain'];

    private const string MESSAGE_HELD = 'This attachment cannot be changed while its message is being checked';

    /**
     * Reads the chat's own per-file limit on every declaration, so an administrator's change applies at once.
     *
     * @return int Largest attachment in bytes
     * @throws DatabaseException When the persisted setting cannot be read
     * @throws SettingException When the setting is missing from the catalog or not an integer
     */
    public function maxBytes(): int
    {
        return Hilos::$setting[ChatSettingsConstants::CHAT_ATTACHMENT_MAX_FILE_BYTES]->int();
    }

    /**
     * @return bool Always true: a guest reads the chat but sends nothing into it
     */
    public function requiresSignIn(): bool
    {
        return true;
    }

    /** @return bool Publication requires final owner approval of the whole original attachment group */
    public function requiresUploadHoldForPublication(): bool
    {
        return true;
    }

    /** @return FileVisibility Chat attachments require an authenticated reader */
    public function visibility(): FileVisibility
    {
        return FileVisibility::AUTHENTICATED;
    }

    /**
     * @return list<string> Pictures, PDF documents and plain text
     */
    public function acceptedMimeTypes(): array
    {
        return self::ACCEPTED_MIME_TYPES;
    }

    /**
     * @return bool Always true: the browser's word on the type is not trusted
     */
    public function sniffsContent(): bool
    {
        return true;
    }

    /**
     * Protects every upload in the held composer, including earlier approvals and publication wait.
     *
     * The connections collection is held process-wide by the runtime context. The composer,
     * rather than the current image check, covers the whole attempt and survives a chat restart.
     *
     * @param string $acceptKey Connection whose held composer supplies the policy
     * @param string $clientUploadId Attachment the browser would create, remove or replace
     * @throws ValidationException When the composer holds the attachment or its source is stale
     * @throws HilosException When the connection runtime cannot be read
     */
    public function assertBrowserMutationAllowed(string $acceptKey, string $clientUploadId): void
    {
        if (Hilos::$rt->connections[$acceptKey] === null) {
            if (Hilos::$rt->connections->staleSince() !== null) {
                throw new ValidationException(self::MESSAGE_HELD);
            }

            return;
        }

        if (
            Hilos::$rt->connections[$acceptKey]->staleSince() !== null
            || (
                Hilos::$rt->connections[$acceptKey]->outboundModerationPhase
                    === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
                && in_array($clientUploadId, Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments, true)
            )
        ) {
            throw new ValidationException(self::MESSAGE_HELD);
        }
    }

    /**
     * @return list<DuplicateContentCheck> The same person does not attach the same file twice
     * @throws FeatureNotDeclaredException When the chat stopped declaring the files registry the check reads
     */
    public function extraChecks(): array
    {
        return [new DuplicateContentCheck()];
    }
}
