<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use LogicException;

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
 * {@see exportOwn()}, {@see applyOwnDiff()}. Inbound RT updates arrive through
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
    public const string outboundModerationPicture = 'outboundModerationPicture';

    protected const array OWN_FIELD_TYPES = [
        self::outboundModerationPhase => 'text',
        self::outboundModerationMessage => '?text',
        self::outboundModerationReason => '?text',
        self::outboundModerationUpdatedAt => 'int',
        self::outboundModerationAttachments => 'strings',
        self::outboundModerationPicture => '?text',
    ];

    private const array OWN_FIELD_DEFAULTS = [
        self::outboundModerationPhase => ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
        self::outboundModerationMessage => null,
        self::outboundModerationReason => null,
        self::outboundModerationUpdatedAt => 0,
        self::outboundModerationAttachments => [],
        self::outboundModerationPicture => null,
    ];

    /** Moderation phase: checking, rejected, unavailable, or none while the socket is clear. */
    public string $outboundModerationPhase {
        get => isset($this->rowValues()[self::outboundModerationPhase])
            ? $this->rowValues()[self::outboundModerationPhase]->toNative()
            : self::OWN_FIELD_DEFAULTS[self::outboundModerationPhase];
        set {
            $this->setRowValue(self::outboundModerationPhase, RtRowValues::fromNative($value));
        }
    }

    /**
     * Submitted message text associated with the moderation state — empty when
     * the submit carried attachments and no text — or null while none is.
     */
    public ?string $outboundModerationMessage {
        get => ($this->rowValues()[self::outboundModerationMessage] ?? null)?->toNative();
        set {
            $this->setRowValue(self::outboundModerationMessage, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Moderation rejection or unavailable reason, or null while the verdict names none. */
    public ?string $outboundModerationReason {
        get => ($this->rowValues()[self::outboundModerationReason] ?? null)?->toNative();
        set {
            $this->setRowValue(self::outboundModerationReason, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Unix time of last moderation state update. */
    public int $outboundModerationUpdatedAt {
        get => $this->rowValues()[self::outboundModerationUpdatedAt] ?? self::OWN_FIELD_DEFAULTS[self::outboundModerationUpdatedAt];
        set {
            $this->setRowValue(self::outboundModerationUpdatedAt, $value);
        }
    }

    /**
     * Client ids of the complete uploads the submitted message carries, in the order the
     * person attached them; empty while no message is moderated or it carries no file.
     *
     * @var list<string>
     */
    public array $outboundModerationAttachments {
        get => isset($this->rowValues()[self::outboundModerationAttachments])
            ? $this->rowValues()[self::outboundModerationAttachments]->toNative()
            : self::OWN_FIELD_DEFAULTS[self::outboundModerationAttachments];
        set {
            $this->setRowValue(self::outboundModerationAttachments, RtRowValues::fromNative($value));
        }
    }

    /**
     * Client id of the picture a refusal is about, or null when the refusal is about
     * the text or nothing is refused.
     */
    public ?string $outboundModerationPicture {
        get => ($this->rowValues()[self::outboundModerationPicture] ?? null)?->toNative();
        set {
            $this->setRowValue(self::outboundModerationPicture, $value === null ? null : RtRowValues::fromNative($value));
        }
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

    /** @return array<string, mixed> Framework defaults followed by chat's moderation fields */
    protected function rowFieldDefaults(): array
    {
        return parent::rowFieldDefaults() + self::OWN_FIELD_DEFAULTS;
    }

    /** @return Generator<int, RtWork, int, void> Property defaults seed the project stage */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Segmented input
     * @return Generator<int, RtWork, int, void> Hydrates chat moderation fields
     * @throws InvalidFormatException When a project field violates its existing contract
     * @throws LogicException When page preparation violates writer lifetime
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield from $this->readFields($row, self::OWN_FIELD_TYPES);
    }

    /**
     * @param RtRowReader $diff Segmented input
     * @return Generator<int, RtWork, int, void> Patches only supplied chat moderation fields
     * @throws InvalidFormatException When a project field violates its existing contract
     * @throws LogicException When page preparation violates writer lifetime
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield from $this->readFields($diff, self::OWN_FIELD_TYPES, true);
    }

    /**
     * @param RtRowWriter $out Segmented destination
     * @return Generator<int, RtWork, int, void> Exports chat moderation fields
     * @throws InvalidFormatException When a project field violates its existing contract
     * @throws LogicException When page preparation violates writer lifetime
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield from $this->exportFields($out, self::OWN_FIELD_DEFAULTS);
    }
}
