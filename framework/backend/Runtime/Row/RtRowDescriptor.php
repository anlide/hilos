<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\Upload\UploadSnapshotCollection;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/** Immutable fixed-schema descriptor; variable graphs are always arena pages. */
final readonly class RtRowDescriptor
{
    /**
     * @param array<string, RtRowValues|UploadSnapshotCollection|int|float|bool|null> $fields Fixed codec backing
     * @param array<string, mixed> $defaults Fixed property defaults in export order, never incoming fallbacks
     */
    public function __construct(public array $fields, public array $defaults)
    {
    }

    /**
     * Only the fixed schema is copied; each variable value acquires one root lease.
     *
     * @param RtRowRole $role New descriptor's holder role
     * @return self Independent holds on the same immutable pages
     * @throws InvalidFormatException When retained upload identities are malformed
     * @throws LogicException When a backing lease has closed
     */
    public function retain(RtRowRole $role): self
    {
        $fields = $this->fields;
        foreach ($fields as $key => $value) {
            if ($value instanceof RtRowValues || $value instanceof UploadSnapshotCollection) {
                $fields[$key] = $value->retain($role);
            }
        }

        return new self($fields, $this->defaults);
    }

    /**
     * @internal Moves this descriptor's own leases to another memory role in constant work per
     * field; the pages and every other holder stay as they are.
     *
     * @param RtRowRole $role New holder role
     */
    public function reclassify(RtRowRole $role): void
    {
        foreach ($this->fields as $value) {
            if ($value instanceof RtRowValues || $value instanceof UploadSnapshotCollection) {
                $value->reclassify($role);
            }
        }
    }

    /**
     * @param RtRowWriter $out Unpublished output in any arena
     * @return Generator<int, RtWork, int, void> Bounded immutable field export
     * @throws InvalidFormatException When a stored value is malformed
     * @throws LogicException When the output or a backing lease has closed
     */
    public function export(RtRowWriter $out): Generator
    {
        yield from $this->writeFields($out, false);
    }

    /**
     * The row as it stands, for a carrier of a write: a segmented field held in the output's arena
     * is referenced at its root, never copied - the values are immutable - so the work grows with
     * the number of fields rather than with the bytes the row holds. A field held in another arena
     * is copied, and a fixed value is encoded, as {@see self::export()} does.
     *
     * @param RtRowWriter $out Unpublished output, normally in the arena the row is held in
     * @return Generator<int, RtWork, int, void> Bounded field references
     * @throws InvalidFormatException When a stored value is malformed
     * @throws LogicException When the output or a backing lease has closed
     */
    public function carry(RtRowWriter $out): Generator
    {
        yield from $this->writeFields($out, true);
    }

    /**
     * @param RtRowWriter $out Unpublished output
     * @param bool $byReference Whether a segmented field of the output's arena is referenced rather than copied
     * @return Generator<int, RtWork, int, void> Bounded field writes in export order
     * @throws InvalidFormatException When a stored value is malformed
     * @throws LogicException When the output or a backing lease has closed
     */
    private function writeFields(RtRowWriter $out, bool $byReference): Generator
    {
        foreach ($this->defaults as $key => $default) {
            yield new RtWork();
            // A field set to null stays null; only a field the descriptor does not hold takes its default.
            $value = array_key_exists($key, $this->fields) ? $this->fields[$key] : $default;
            if ($value instanceof UploadSnapshotCollection) {
                $encoded = $byReference
                    ? yield from $value->carriedValues($out->arena())
                    : yield from $value->exportValues($out->arena());
            } elseif ($value instanceof RtRowValues) {
                $encoded = $byReference && $value->isHeldIn($out->arena())
                    ? $value
                    : yield from RtRowWriter::copy($value->reader(), $out->arena());
            } else {
                $encoded = yield from RtRowWriter::encode($value, $out->arena());
            }
            yield from $out->write($key, $encoded);
        }
    }
}
