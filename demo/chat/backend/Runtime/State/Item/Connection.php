<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\HilosSessionConnection;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Stands on the framework {@see HilosSessionConnection} base — the session stage,
 * because chat carries browser sessions — which owns the session triple
 * (acceptKey / sessionToken / userId) and the whole create/hydrate/serialize/diff
 * template. This subclass adds chat's own in-memory moderation state for this
 * socket only - the files a submitted message carries are the framework uploads
 * agent's, named here by their client ids (HIL-144) - and reaches it through
 * the four hooks the base leaves it: {@see initOwn()}, {@see hydrateOwn()},
 * {@see ownToArray()}, {@see applyOwnDiff()}. Inbound RT updates arrive through
 * the base `applyDiff()`; local writes from item actions use typed properties and
 * `sync()`. Public string constants name row keys.
 */
final class Connection extends HilosSessionConnection
{
    public const string outboundModerationPhase = 'outboundModerationPhase';
    public const string outboundModerationMessage = 'outboundModerationMessage';
    public const string outboundModerationReason = 'outboundModerationReason';
    public const string outboundModerationUpdatedAt = 'outboundModerationUpdatedAt';
    public const string outboundModerationAttachments = 'outboundModerationAttachments';

    public const string renameModerationPhase = 'renameModerationPhase';
    public const string renameModerationName = 'renameModerationName';
    public const string renameModerationReason = 'renameModerationReason';
    public const string renameModerationUpdatedAt = 'renameModerationUpdatedAt';





    /** Moderation phase: checking, rejected, unavailable, or none while the socket is clear. */
    public string $outboundModerationPhase = ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE;

    /**
     * Submitted message text associated with the moderation state — empty when
     * the submit carried attachments and no text — or null while none is.
     */
    public ?string $outboundModerationMessage = null;

    /** Moderation rejection or unavailable reason, or null while the verdict names none. */
    public ?string $outboundModerationReason = null;

    /** Unix time of last moderation state update. */
    public int $outboundModerationUpdatedAt = 0;

    /**
     * Client ids of the complete uploads the submitted message carries, in the order the
     * person attached them; empty while no message is moderated or it carries no file.
     *
     * @var list<string>
     */
    public array $outboundModerationAttachments = [];

    /** Rename moderation phase: checking, rejected, unavailable, or none while the socket is clear. */
    public string $renameModerationPhase = ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_NONE;

    /** Requested display name associated with rename moderation, or null while none is. */
    public ?string $renameModerationName = null;

    /** Rename moderation rejection or unavailable reason, or null while the verdict names none. */
    public ?string $renameModerationReason = null;

    /** Unix time of last rename moderation state update. */
    public int $renameModerationUpdatedAt = 0;

    protected function initOwn(): void
    {
    }

    /**
     * The row is written by {@see ownToArray()} on another worker, so every key it
     * declares is required here and a phase field arrives as the empty string only
     * when that is the phase — the value its own `*_NONE` constant names.
     *
     * An empty string is a value in every optional field below, never a spelling of
     * absence: absence is `null`, and only this class writes these keys. A message
     * submitted with attachments and no text is the field that makes the difference
     * visible — read as "no message", it would leave the moderator's phase checking
     * forever and every later submit refused as one already under moderation.
     *
     * @param array<string, mixed> $row Serialized runtime row (string keys match this class field constants)
     * @throws InvalidFormatException When the row is missing a field of this socket's chat state
     */
    protected function hydrateOwn(array $row): void
    {
        $this->outboundModerationPhase = self::requireString($row, self::outboundModerationPhase);
        $this->outboundModerationMessage = self::optionalString($row, self::outboundModerationMessage);
        $this->outboundModerationReason = self::optionalString($row, self::outboundModerationReason);
        $this->outboundModerationUpdatedAt = self::requireInt($row, self::outboundModerationUpdatedAt);
        $this->outboundModerationAttachments = self::requireStringList($row, self::outboundModerationAttachments);
        $this->renameModerationPhase = self::requireString($row, self::renameModerationPhase);
        $this->renameModerationName = self::optionalString($row, self::renameModerationName);
        $this->renameModerationReason = self::optionalString($row, self::renameModerationReason);
        $this->renameModerationUpdatedAt = self::requireInt($row, self::renameModerationUpdatedAt);
    }

    /**
     * Runtime collection key for connection rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::connections;
    }

    /**
     * A diff carries only the fields that changed, so an absent key means the
     * field was not touched; a key that is present is read at its declared type.
     *
     * @param array<string, mixed> $diff Partial update; keys are public `* = 'fieldName'` constants on this class
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    protected function applyOwnDiff(array $diff): void
    {
        $this->outboundModerationPhase = self::patchString($diff, self::outboundModerationPhase, $this->outboundModerationPhase);
        $this->outboundModerationMessage = self::patchOptionalString($diff, self::outboundModerationMessage, $this->outboundModerationMessage);
        $this->outboundModerationReason = self::patchOptionalString($diff, self::outboundModerationReason, $this->outboundModerationReason);
        $this->outboundModerationUpdatedAt = self::patchInt($diff, self::outboundModerationUpdatedAt, $this->outboundModerationUpdatedAt);
        $this->outboundModerationAttachments = self::patchStringList(
            $diff,
            self::outboundModerationAttachments,
            $this->outboundModerationAttachments,
        );
        $this->renameModerationPhase = self::patchString($diff, self::renameModerationPhase, $this->renameModerationPhase);
        $this->renameModerationName = self::patchOptionalString($diff, self::renameModerationName, $this->renameModerationName);
        $this->renameModerationReason = self::patchOptionalString($diff, self::renameModerationReason, $this->renameModerationReason);
        $this->renameModerationUpdatedAt = self::patchInt($diff, self::renameModerationUpdatedAt, $this->renameModerationUpdatedAt);
    }

    /**
     * @return array<string, mixed> Chat's own fields of the row
     */
    protected function ownToArray(): array
    {
        return [
            self::outboundModerationPhase => $this->outboundModerationPhase,
            self::outboundModerationMessage => $this->outboundModerationMessage,
            self::outboundModerationReason => $this->outboundModerationReason,
            self::outboundModerationUpdatedAt => $this->outboundModerationUpdatedAt,
            self::outboundModerationAttachments => $this->outboundModerationAttachments,
            self::renameModerationPhase => $this->renameModerationPhase,
            self::renameModerationName => $this->renameModerationName,
            self::renameModerationReason => $this->renameModerationReason,
            self::renameModerationUpdatedAt => $this->renameModerationUpdatedAt,
        ];
    }
}
