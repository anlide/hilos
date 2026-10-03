<?php

declare(strict_types=1);

namespace Demo\Chat\Files;

use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Files\FileVisibility;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\Files\Upload\Check\DuplicateContentCheck;

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
     * @return list<DuplicateContentCheck> The same person does not attach the same file twice
     * @throws FeatureNotDeclaredException When the chat stopped declaring the files registry the check reads
     */
    public function extraChecks(): array
    {
        return [new DuplicateContentCheck()];
    }
}
