<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\State\Item;

use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\RtState;

/**
 * One note of the set probe: a line of text in the set of the node it belongs to.
 *
 * The collection is cut into sets by the node ({@see self::SET_VIA}), and each node's set probe
 * owns the set of its own node - which is what lets a cluster run show a claim over a set
 * holding across nodes (HIL-1116): a node writes its own set, is refused another node's, and a
 * node cut off while a set was written gets the row when the set is handed over.
 *
 * Only the text is ever rewritten. The node a note belongs to is set when it is created, since
 * moving a row into another set is the right of the owner of the whole collection, and the
 * stand has none.
 */
final class ProbeNote extends RtState
{
    public const string noteId = 'noteId';
    public const string nodeId = 'nodeId';
    public const string text = 'text';

    /** The set a note is in is the node it belongs to. */
    public const string SET_VIA = self::nodeId;

    /** Note id, and its row id. */
    private(set) string $noteId = '';

    /** Node whose set the note is in. */
    private(set) string $nodeId = '';

    /** Text of the note, the one field a write rewrites. */
    public string $text = '';

    /**
     * @param string $noteId Note id, and the row id
     * @param string $nodeId Node whose set the note is born in
     * @param string $text Text of the note
     * @return static Fresh note row
     */
    public static function create(string $noteId, string $nodeId, string $text): static
    {
        $instance = new static();
        $instance->noteId = $noteId;
        $instance->nodeId = $nodeId;
        $instance->text = $text;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated note row
     * @throws InvalidFormatException When the row is missing a field the note is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->noteId = self::requireString($row, self::noteId);
        $instance->nodeId = self::requireString($row, self::nodeId);
        $instance->text = self::requireString($row, self::text);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for the probe notes.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ClusterRtContext::probeNotes;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->text = self::patchString($diff, self::text, $this->text);
    }

    /**
     * @return string Runtime row id, the note id
     */
    public function getId(): string
    {
        return $this->noteId;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::noteId => $this->noteId,
            self::nodeId => $this->nodeId,
            self::text => $this->text,
        ];
    }
}
