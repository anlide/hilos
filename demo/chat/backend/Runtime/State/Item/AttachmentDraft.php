<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\InvalidFormatException;
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
    private(set) string $draftId = '';

    /** WebSocket connection that owns this draft. */
    private(set) string $acceptKey = '';

    /** User that owns this draft. */
    private(set) int $userId = 0;

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
     * @return static Hydrated draft row
     * @throws InvalidFormatException When the row is missing a field the draft is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->draftId = self::requireString($row, self::draftId);
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->userId = self::requireInt($row, self::userId);
        $instance->quarantineBasename = self::requireString($row, self::quarantineBasename);
        $instance->originalFilename = self::requireString($row, self::originalFilename);
        $instance->mimeType = self::requireString($row, self::mimeType);
        $instance->size = self::requireInt($row, self::size);
        $instance->normalizedFilename = self::requireString($row, self::normalizedFilename);
        $instance->uploadedAt = self::requireInt($row, self::uploadedAt);
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
     * A diff carries only the fields that changed, so an absent key means the
     * field was not touched; a key that is present is read at its declared type.
     *
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::quarantineBasename, $diff)) {
            $this->quarantineBasename = self::requireString($diff, self::quarantineBasename);
        }
        if (array_key_exists(self::originalFilename, $diff)) {
            $this->originalFilename = self::requireString($diff, self::originalFilename);
        }
        if (array_key_exists(self::mimeType, $diff)) {
            $this->mimeType = self::requireString($diff, self::mimeType);
        }
        if (array_key_exists(self::size, $diff)) {
            $this->size = self::requireInt($diff, self::size);
        }
        if (array_key_exists(self::normalizedFilename, $diff)) {
            $this->normalizedFilename = self::requireString($diff, self::normalizedFilename);
        }
        if (array_key_exists(self::uploadedAt, $diff)) {
            $this->uploadedAt = self::requireInt($diff, self::uploadedAt);
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

    /**
     * Reads one field of a runtime row or diff that the row cannot be built without.
     *
     * A runtime row is written by {@see toArray()} on another worker, so a key
     * that is absent or holds another type is a row that lost the field on the
     * way, not a row that never had it. A cast would hand the browser a draft
     * with a blank filename or a zero size instead of saying the row is broken.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    private static function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidFormatException('Runtime row carries no string under key ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return int Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-integer
     */
    private static function requireInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidFormatException('Runtime row carries no integer under key ' . $key);
        }

        return $value;
    }
}
