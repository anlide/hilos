<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Moderation\ModerationKind;
use Hilos\Runtime\State\Collection\HilosModerationChecks;

/**
 * One check a connection is waiting on: what the moderator is handed, of which kind, and who
 * receives the verdict (HIL-1625).
 *
 * The row is keyed by the connection and the kind, so a connection holds one check of a kind at a
 * time. Every opening draws a fresh request id, and a verdict is applied only while it names the
 * request id the row still holds: a changed content is a new attempt under a new id, never an
 * edit of the old one, and the row carries no field a write could rewrite.
 *
 * The collection is cut into sets by the agent that receives the verdict ({@see self::SET_VIA}),
 * taken from the kind and not from the caller: each initiator owns the checks it opened, and the
 * moderator only reads them. Framework-owned runtime state mounted by the moderation feature
 * ({@see ModerationFeature}, {@see HilosModerationChecks}).
 */
final class HilosModerationCheck extends RtState
{
    /** Runtime collection key mounted by the moderation feature and used for RT sync. */
    public const string RT_COLLECTION = 'hilosModerationChecks';

    /** Separator of the two halves of the row id; a kind key never contains it. */
    private const string KEY_SEPARATOR = '|';

    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string kind = 'kind';
    public const string requestId = 'requestId';
    public const string verdictTo = 'verdictTo';
    public const string text = 'text';
    public const string clientUploadId = 'clientUploadId';
    public const string startedAt = 'startedAt';
    public const string deadlineAt = 'deadlineAt';

    /** The set a check is in is the agent its verdict goes to. */
    public const string SET_VIA = self::verdictTo;

    /** Accept key of the connection awaiting the verdict. */
    private(set) string $acceptKey = '';

    /** Person whose content is checked. */
    private(set) int $userId = 0;

    /** Key of the moderation kind ({@see ModerationKind::$key}). */
    private(set) string $kind = '';

    /** Id of this attempt, fresh on every opening; a verdict naming another is stale. */
    private(set) string $requestId = '';

    /** Agent type the verdict is sent to, as the kind names it. */
    private(set) string $verdictTo = '';

    /** Text to check, for a kind that looks at text; null otherwise. */
    private(set) ?string $text = null;

    /** Id of the complete upload on the same connection, for a kind that looks at an image; null otherwise. */
    private(set) ?string $clientUploadId = null;

    /** Moment the check was opened, in epoch milliseconds. */
    private(set) int $startedAt = 0;

    /** Moment the check stops waiting for a verdict, in epoch milliseconds; fixed at the opening. */
    private(set) int $deadlineAt = 0;

    /**
     * Composes the row id from the connection and the kind.
     *
     * @param string $acceptKey Accept key of the connection
     * @param string $kind Moderation kind key
     * @return string Runtime row id `acceptKey|kind`
     */
    public static function keyFor(string $acceptKey, string $kind): string
    {
        return $acceptKey . self::KEY_SEPARATOR . $kind;
    }

    /**
     * Opens a check that has just been asked for.
     *
     * @param string $acceptKey Accept key of the connection awaiting the verdict
     * @param int $userId Person whose content is checked
     * @param string $kind Moderation kind key
     * @param string $requestId Id of this attempt
     * @param string $verdictTo Agent type the verdict is sent to
     * @param ?string $text Text to check, or null for a kind that looks at no text
     * @param ?string $clientUploadId Complete upload to check, or null for a kind that looks at no image
     * @param int $startedAt Opening moment in epoch milliseconds
     * @param int $deadlineAt Moment the wait ends, in epoch milliseconds
     * @return static Fresh check row
     */
    public static function create(
        string $acceptKey,
        int $userId,
        string $kind,
        string $requestId,
        string $verdictTo,
        ?string $text,
        ?string $clientUploadId,
        int $startedAt,
        int $deadlineAt,
    ): static {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->kind = $kind;
        $instance->requestId = $requestId;
        $instance->verdictTo = $verdictTo;
        $instance->text = $text;
        $instance->clientUploadId = $clientUploadId;
        $instance->startedAt = $startedAt;
        $instance->deadlineAt = $deadlineAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Check row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the check is built from, or carries one as the wrong type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->userId = self::requireInt($row, self::userId);
        $instance->kind = self::requireString($row, self::kind);
        $instance->requestId = self::requireString($row, self::requestId);
        $instance->verdictTo = self::requireString($row, self::verdictTo);
        $instance->text = self::optionalString($row, self::text);
        $instance->clientUploadId = self::optionalString($row, self::clientUploadId);
        $instance->startedAt = self::requireInt($row, self::startedAt);
        $instance->deadlineAt = self::requireInt($row, self::deadlineAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for moderation checks
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id `acceptKey|kind`
     */
    public function getId(): string
    {
        return self::keyFor($this->acceptKey, $this->kind);
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::kind => $this->kind,
            self::requestId => $this->requestId,
            self::verdictTo => $this->verdictTo,
            self::text => $this->text,
            self::clientUploadId => $this->clientUploadId,
            self::startedAt => $this->startedAt,
            self::deadlineAt => $this->deadlineAt,
        ];
    }
}
