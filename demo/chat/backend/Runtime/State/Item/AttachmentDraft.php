<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime row for an uploaded, not-yet-sent chat attachment.
 *
 * The draft starts after the binary upload completes and expires if the user
 * never includes it in a message submit.
 */
final class AttachmentDraft extends RtState
{
    public const string draftId = 'draftId';
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string quarantineBasename = 'quarantineBasename';
    public const string originalFilename = 'originalFilename';
    public const string mimeType = 'mimeType';
    public const string size = 'size';
    public const string normalizedFilename = 'normalizedFilename';
    public const string uploadedAt = 'uploadedAt';

    /** Draft id returned to the client and used by message submit. */
    public private(set) string $draftId = '';

    /** WebSocket connection that owns this draft. */
    public private(set) string $acceptKey = '';

    /** User that owns this draft. */
    public private(set) int $userId = 0;

    /** Quarantine filename, including extension. */
    public string $quarantineBasename = '';

    /** Original client filename. */
    public string $originalFilename = '';

    /** Client-declared MIME type. */
    public string $mimeType = '';

    /** File size in bytes. */
    public int $size = 0;

    /** Normalized basename used for duplicate checks. */
    public string $normalizedFilename = '';

    /** Unix timestamp when upload completed. */
    public int $uploadedAt = 0;

    /**
     * @param string $draftId Client-visible draft id
     * @param string $acceptKey Owning WebSocket connection id
     * @param int $userId Owning user id
     * @param string $quarantineBasename Quarantine filename
     * @param string $originalFilename Original client filename
     * @param string $mimeType Client-declared MIME type
     * @param int $size File size in bytes
     * @param string $normalizedFilename Normalized filename for duplicate checks
     * @param int $uploadedAt Upload completion unix timestamp
     */
    public static function create(
        string $draftId,
        string $acceptKey,
        int $userId,
        string $quarantineBasename,
        string $originalFilename,
        string $mimeType,
        int $size,
        string $normalizedFilename,
        int $uploadedAt,
    ): static {
        $instance = new static();
        $instance->draftId = $draftId;
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->quarantineBasename = $quarantineBasename;
        $instance->originalFilename = $originalFilename;
        $instance->mimeType = $mimeType;
        $instance->size = $size;
        $instance->normalizedFilename = $normalizedFilename;
        $instance->uploadedAt = $uploadedAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->draftId = (string)($row[self::draftId] ?? '');
        $instance->acceptKey = (string)($row[self::acceptKey] ?? '');
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->quarantineBasename = (string)($row[self::quarantineBasename] ?? '');
        $instance->originalFilename = (string)($row[self::originalFilename] ?? '');
        $instance->mimeType = (string)($row[self::mimeType] ?? '');
        $instance->size = (int)($row[self::size] ?? 0);
        $instance->normalizedFilename = (string)($row[self::normalizedFilename] ?? '');
        $instance->uploadedAt = (int)($row[self::uploadedAt] ?? 0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for attachment draft rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::attachmentDrafts;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::quarantineBasename])) {
            $this->quarantineBasename = (string)$diff[self::quarantineBasename];
        }
        if (isset($diff[self::originalFilename])) {
            $this->originalFilename = (string)$diff[self::originalFilename];
        }
        if (isset($diff[self::mimeType])) {
            $this->mimeType = (string)$diff[self::mimeType];
        }
        if (isset($diff[self::size])) {
            $this->size = (int)$diff[self::size];
        }
        if (isset($diff[self::normalizedFilename])) {
            $this->normalizedFilename = (string)$diff[self::normalizedFilename];
        }
        if (isset($diff[self::uploadedAt])) {
            $this->uploadedAt = (int)$diff[self::uploadedAt];
        }
    }

    /**
     * @return string Runtime collection key
     */
    public function getId(): string
    {
        return $this->draftId;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::draftId => $this->draftId,
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::quarantineBasename => $this->quarantineBasename,
            self::originalFilename => $this->originalFilename,
            self::mimeType => $this->mimeType,
            self::size => $this->size,
            self::normalizedFilename => $this->normalizedFilename,
            self::uploadedAt => $this->uploadedAt,
        ];
    }
}
