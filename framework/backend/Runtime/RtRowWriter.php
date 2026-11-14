<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowCopyFrame;
use Hilos\Runtime\Row\RtRowCopyStack;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/** Builds unpublished bounded pages; finish() seals the root before handing it out. */
final class RtRowWriter
{
    private ?RtRowPage $root = null;
    private ?RtRowPage $tail = null;
    private bool $finished = false;

    /**
     * @param RtRowArena $arena Storage owner
     * @param RtRowRole $role Finished value's holder role
     */
    public function __construct(private readonly RtRowArena $arena, private readonly RtRowRole $role = RtRowRole::Candidate)
    {
    }

    /** Unfinished drafts retire through exactly the same queue as completed values. */
    public function __destruct()
    {
        if ($this->root !== null) {
            $this->arena->release($this->root, RtRowRole::Transient);
        }
    }

    /** @return RtRowArena Destination storage owner */
    public function arena(): RtRowArena
    {
        return $this->arena;
    }

    /**
     * Copies segmented input without native materialization, including across arenas.
     *
     * @param RtRowReader $source Immutable input
     * @param RtRowArena $arena Destination owner
     * @param RtRowRole $role Finished value's role
     * @return Generator<int, RtWork, int, RtRowValues> Prepared segmented copy
     * @throws InvalidFormatException When the input has an invalid value shape
     * @throws LogicException When a writer lifetime is violated
     */
    public static function copy(RtRowReader $source, RtRowArena $arena, RtRowRole $role = RtRowRole::Transient): Generator
    {
        $stack = new RtRowCopyStack($arena);
        yield new RtWork();
        $stack->push($source, $role);
        while ($stack->head !== null) {
            yield new RtWork();
            $frame = $stack->head;
            if ($frame->source->kind() !== RtRowKind::Map) {
                $completed = yield from self::copyLeaf($frame->source, $arena, $frame->role);
            } else {
                if ($frame->cursor === null) {
                    $frame->cursor = $frame->source->entries();
                    $frame->writer = new self($arena, $frame->role);
                }
                if ($frame->phase === RtRowCopyFrame::NEXT_ENTRY) {
                    $frame->entry = yield from $frame->cursor->advance();
                    if ($frame->entry !== null) {
                        $frame->phase = RtRowCopyFrame::COPY_KEY;
                        continue;
                    }
                    $completed = yield from $frame->writer->finish();
                } elseif ($frame->phase === RtRowCopyFrame::COPY_KEY) {
                    $stack->push($frame->entry->key->reader(), RtRowRole::Transient);
                    continue;
                } elseif ($frame->phase === RtRowCopyFrame::COPY_VALUE) {
                    $stack->push($frame->entry->value->reader(), RtRowRole::Transient);
                    continue;
                } else {
                    yield from $frame->writer->writeEntry($frame->key, $frame->value);
                    $frame->key = null;
                    $frame->value = null;
                    $frame->entry = null;
                    $frame->phase = RtRowCopyFrame::NEXT_ENTRY;
                    continue;
                }
            }
            $stack->pop();
            if ($stack->head === null) {
                return $completed;
            }
            if ($stack->head->phase === RtRowCopyFrame::COPY_KEY) {
                $stack->head->key = $completed;
                $stack->head->phase = RtRowCopyFrame::COPY_VALUE;
            } else {
                $stack->head->value = $completed;
                $stack->head->phase = RtRowCopyFrame::WRITE_ENTRY;
            }
        }

        throw new LogicException('RT copy must complete its root');
    }

    /**
     * @param int|string $key Native bounded application field or sequential list index
     * @param RtRowValues $value Completed immutable value in this arena
     * @return Generator<int, RtWork, int, void> Bounded field/index preparation
     * @throws LogicException When the writer closed or the value belongs to another arena
     * @throws InvalidFormatException When the native key is unsupported
     */
    public function write(int|string $key, RtRowValues $value): Generator
    {
        $keyValue = yield from self::encode($key, $this->arena, RtRowRole::Transient);
        yield from $this->writeEntry($keyValue, $value);
    }

    /**
     * @param RtRowValues $key Segmented map key
     * @param RtRowValues $value Completed value
     * @return Generator<int, RtWork, int, void> Bounded entry preparation
     * @throws LogicException When closed or when an arena differs
     */
    public function writeEntry(RtRowValues $key, RtRowValues $value): Generator
    {
        yield new RtWork();
        $key->assertArena($this->arena);
        $key = $key->retain(RtRowRole::Transient);
        yield new RtWork();
        $value->assertArena($this->arena);
        $value = $value->retain(RtRowRole::Transient);
        yield from $this->start(RtRowKind::Map);
        yield new RtWork();
        $entry = $this->arena->allocate(RtRowKind::Entry);
        $key->attach($this->arena, $entry, true);
        $value->attach($this->arena, $entry, false);
        ++$entry->references;
        $this->appendPage($entry);
        $this->arena->grow($this->root, $entry->logicalBytes);
    }

    /**
     * @return Generator<int, RtWork, int, RtRowValues> Completed immutable map
     * @throws LogicException When the writer is already finished
     */
    public function finish(): Generator
    {
        yield from $this->start(RtRowKind::Map);
        yield new RtWork();
        $value = new RtRowValues($this->arena, $this->root, $this->role);
        $this->arena->release($this->root, RtRowRole::Transient);
        $this->root = null;
        $this->tail = null;
        $this->finished = true;

        return $value;
    }

    /**
     * Appends the next bytes of a text whose whole is never held natively; finish() seals it. An
     * empty text is started by appending nothing.
     *
     * @param string $bytes Next bytes of the text, in order
     * @return Generator<int, RtWork, int, void> Bounded leaf preparation
     * @throws LogicException When the writer is closed or already builds a map
     */
    public function appendText(string $bytes): Generator
    {
        yield from $this->start(RtRowKind::Text);
        if ($this->root->kind !== RtRowKind::Text) {
            throw new LogicException('An RT map writer takes no text');
        }
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $count = yield new RtWork(min(RtRowPage::LEAF_BYTES, $length - $offset));
            $leaf = $this->arena->allocate(RtRowKind::Bytes, substr($bytes, $offset, $count), $count);
            ++$leaf->references;
            $this->appendPage($leaf);
            $this->arena->grow($this->root, $leaf->logicalBytes);
            $offset += $count;
        }
    }

    /**
     * Native ingress is an explicit compatibility boundary. New replication readers
     * already hold segmented values and never call this with a materialized row.
     *
     * @param mixed $value Native boundary value
     * @param RtRowArena $arena Storage owner
     * @param RtRowRole $role Finished value's role
     * @return Generator<int, RtWork, int, RtRowValues> Segmented value
     * @throws InvalidFormatException When a native object/resource or a float that is not finite is passed
     * @throws LogicException When page preparation violates writer lifetime
     */
    public static function encode(mixed $value, RtRowArena $arena, RtRowRole $role = RtRowRole::Transient): Generator
    {
        if (is_array($value)) {
            $writer = new self($arena, $role);
            foreach ($value as $key => $element) {
                yield new RtWork();
                $child = yield from self::encode($element, $arena);
                yield from $writer->write($key, $child);
            }

            return yield from $writer->finish();
        }
        if (is_string($value)) {
            $writer = new self($arena, $role);
            yield from $writer->start(RtRowKind::Text);
            $offset = 0;
            $length = strlen($value);
            while ($offset < $length) {
                $bytes = yield new RtWork(min(RtRowPage::LEAF_BYTES, $length - $offset));
                $leaf = $arena->allocate(RtRowKind::Bytes, substr($value, $offset, $bytes), $bytes);
                ++$leaf->references;
                $writer->appendPage($leaf);
                $arena->grow($writer->root, $leaf->logicalBytes);
                $offset += $bytes;
            }
            yield new RtWork();
            $text = new RtRowValues($arena, $writer->root, $role);
            $arena->release($writer->root, RtRowRole::Transient);
            $writer->root = null;
            $writer->tail = null;
            $writer->finished = true;

            return $text;
        }
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidFormatException('Runtime values must be scalar, null or arrays');
        }
        // A row is streamed as JSON, which has no form for NAN or an infinity: refused here, at the
        // door, before the write changes anything, rather than by the sender's encoder afterwards.
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidFormatException('A runtime float must be finite');
        }
        $remaining = is_bool($value) ? 1 : ($value === null ? 0 : PHP_INT_SIZE);
        while ($remaining > 0) {
            $remaining -= yield new RtWork($remaining);
        }
        yield new RtWork();

        return new RtRowValues(
            $arena,
            $arena->allocate(RtRowKind::Scalar, $value, is_bool($value) ? 1 : ($value === null ? 0 : PHP_INT_SIZE)),
            $role,
        );
    }

    /**
     * @param RtRowReader $source Text or fixed-size scalar
     * @param RtRowArena $arena Destination owner
     * @param RtRowRole $role Completed holder's role
     * @return Generator<int, RtWork, int, RtRowValues> Bounded leaf copy
     * @throws InvalidFormatException When a leaf has an invalid kind
     * @throws LogicException When preparation violates writer lifetime
     */
    private static function copyLeaf(RtRowReader $source, RtRowArena $arena, RtRowRole $role): Generator
    {
        yield new RtWork();
        if ($source->kind() === RtRowKind::Scalar) {
            return yield from self::encode(yield from $source->scalar(), $arena, $role);
        }
        $writer = new self($arena, $role);
        yield from $writer->start(RtRowKind::Text);
        $cursor = $source->text();
        while (($part = yield from $cursor->advance()) !== null) {
            yield new RtWork();
            $leaf = $arena->allocate(RtRowKind::Bytes, $part, strlen($part));
            ++$leaf->references;
            $writer->appendPage($leaf);
            $arena->grow($writer->root, $leaf->logicalBytes);
        }
        yield new RtWork();
        $text = new RtRowValues($arena, $writer->root, $role);
        $arena->release($writer->root, RtRowRole::Transient);
        $writer->root = null;
        $writer->tail = null;
        $writer->finished = true;

        return $text;
    }

    /**
     * @param RtRowKind $kind Root kind
     * @return Generator<int, RtWork, int, void> Root allocation, if needed
     * @throws LogicException When the writer has already finished
     */
    private function start(RtRowKind $kind): Generator
    {
        if ($this->finished) {
            throw new LogicException('The RT row writer is closed');
        }
        if ($this->root === null) {
            yield new RtWork();
            $this->root = $this->arena->allocate($kind);
            $this->arena->hold($this->root, RtRowRole::Transient);
        }
    }

    /**
     * @param RtRowPage $page One newly allocated page whose reference belongs to this chain
     */
    private function appendPage(RtRowPage $page): void
    {
        if ($this->tail === null) {
            $this->root->first = $page;
        } else {
            $this->tail->next = $page;
        }
        $this->tail = $page;
    }
}
