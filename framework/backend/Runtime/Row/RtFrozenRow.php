<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtPreparedIdentity;
use Hilos\Runtime\Snapshot\RtPreparedRow;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * Captured immutable values and baseline, with no mutable RtState retained.
 * SCAFFOLD: prepared records are consumed by the isolated version store until
 * all collection readers and write bridges can be mounted together.
 */
final readonly class RtFrozenRow
{
    /**
     * @param RtRowDescriptor $values Captured values with independent snapshot holds
     * @param RtRowDescriptor $baseline Captured baseline with independent snapshot holds
     * @param int $incarnation Original handle lifetime token
     */
    public function __construct(private RtRowDescriptor $values, private RtRowDescriptor $baseline, public int $incarnation)
    {
    }

    /**
     * @param RtRowWriter $out Unpublished output
     * @return Generator<int, RtWork, int, void> Export of this captured version only
     * @throws InvalidFormatException When stored pages are malformed
     * @throws LogicException When a backing lease or the output closed
     */
    public function export(RtRowWriter $out): Generator
    {
        return $this->values->export($out);
    }

    /**
     * @param RtRowWriter $out Unpublished output
     * @return Generator<int, RtWork, int, void> Export of the captured baseline only
     * @throws InvalidFormatException When stored pages are malformed
     * @throws LogicException When a backing lease or the output closed
     */
    public function exportBaseline(RtRowWriter $out): Generator
    {
        return $this->baseline->export($out);
    }

    /**
     * Identity is supplied already segmented, never assembled through getId().
     *
     * @param RtPreparedIdentity $identity Prepared id and set in the destination arena
     * @return Generator<int, RtWork, int, RtPreparedRow> Record accepted by the version store
     * @throws InvalidFormatException When stored pages are malformed
     * @throws LogicException When preparation violates a lease lifetime
     */
    public function prepare(RtPreparedIdentity $identity): Generator
    {
        $values = new RtRowWriter($identity->arena);
        yield from $this->export($values);
        $baseline = new RtRowWriter($identity->arena);
        yield from $this->exportBaseline($baseline);

        return yield from RtPreparedRow::prepare(
            $identity, yield from $values->finish(), yield from $baseline->finish(), $this->incarnation,
        );
    }

    /**
     * @param RtRowArena $arena Destination storage owner
     * @return Generator<int, RtWork, int, RtRowChanges> Changed fields and their precise previous values
     * @throws InvalidFormatException When stored pages are malformed
     * @throws LogicException When preparation violates a lease lifetime
     */
    public function changes(RtRowArena $arena): Generator
    {
        $currentWriter = new RtRowWriter($arena);
        yield from $this->export($currentWriter);
        $current = yield from $currentWriter->finish();
        $baselineWriter = new RtRowWriter($arena);
        yield from $this->exportBaseline($baselineWriter);
        $baseline = yield from $baselineWriter->finish();
        $diff = new RtRowWriter($arena);
        $previous = new RtRowWriter($arena);
        $cursor = $current->reader()->entries();
        // The schema has a fixed number of bounded application field names.
        foreach ($this->values->defaults as $key => $default) {
            yield new RtWork();
            $entry = yield from $cursor->advance();
            $old = yield from $baseline->reader()->field($key);
            $same = $old === null
                ? $entry->value->reader()->kind() === RtRowKind::Scalar && (yield from $entry->value->reader()->scalar()) === null
                : yield from RtRowComparison::equal($entry->value->reader(), $old->reader());
            if (!$same) {
                yield from $diff->writeEntry($entry->key, $entry->value);
                if ($old !== null) {
                    yield from $previous->writeEntry($entry->key, $old);
                }
            }
        }

        return new RtRowChanges(yield from $diff->finish(), yield from $previous->finish());
    }
}
