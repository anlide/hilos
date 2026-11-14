<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowCursor;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Row\RtRowTextCursor;
use Hilos\Runtime\Snapshot\RtWork;

/** Reads immutable pages; no field/list/string is implicitly materialized. */
final class RtRowReader
{
    private readonly RtRowValues $lease;

    /**
     * @internal Open readers through RtRowValues::reader().
     *
     * @param RtRowArena $arena Storage owner
     * @param RtRowPage $root Immutable value root
     */
    public function __construct(private readonly RtRowArena $arena, private readonly RtRowPage $root)
    {
        $this->lease = new RtRowValues($arena, $root, RtRowRole::Transient);
    }

    /** @return RtRowKind Segmented value kind */
    public function kind(): RtRowKind
    {
        return $this->root->kind;
    }

    /** @return RtRowArena Storage owner for shared immutable subvalues */
    public function arena(): RtRowArena
    {
        return $this->arena;
    }

    /**
     * @param RtRowRole $role Holder role
     * @return RtRowValues Independent lease, without walking the value
     */
    public function retain(RtRowRole $role): RtRowValues
    {
        return new RtRowValues($this->arena, $this->root, $role);
    }

    /**
     * @return RtRowCursor Bounded map/list index
     * @throws InvalidFormatException When the value is not an array
     */
    public function entries(): RtRowCursor
    {
        if ($this->root->kind !== RtRowKind::Map) {
            throw new InvalidFormatException('Runtime value must be an array');
        }

        return new RtRowCursor($this->arena, $this->root);
    }

    /**
     * @return RtRowTextCursor Cursor over byte leaves
     * @throws InvalidFormatException When the value is not text
     */
    public function text(): RtRowTextCursor
    {
        if ($this->root->kind !== RtRowKind::Text) {
            throw new InvalidFormatException('Runtime value must be a string');
        }

        return new RtRowTextCursor($this->arena, $this->root);
    }

    /**
     * @return Generator<int, RtWork, int, null|bool|int|float> Native fixed-size scalar
     * @throws InvalidFormatException When the value is text or an array
     */
    public function scalar(): Generator
    {
        yield new RtWork();
        if ($this->root->kind !== RtRowKind::Scalar) {
            throw new InvalidFormatException('Runtime value must be a scalar');
        }
        $remaining = $this->root->payloadBytes;
        while ($remaining > 0) {
            $remaining -= yield new RtWork($remaining);
        }

        return $this->root->scalar;
    }

    /**
     * @param string $key Application field name
     * @return Generator<int, RtWork, int, ?RtRowValues> Field lease, or null when absent
     * @throws InvalidFormatException When the enclosing value is not an array
     */
    public function field(string $key): Generator
    {
        $cursor = $this->entries();
        while (($entry = yield from $cursor->advance()) !== null) {
            if (yield from $entry->key->reader()->equals($key)) {
                return $entry->value;
            }
        }

        return null;
    }

    /**
     * @param string $text Bounded expected field/key
     * @return Generator<int, RtWork, int, bool> Whether this value is that exact string
     * @throws InvalidFormatException When a text page is malformed
     */
    public function equals(string $text): Generator
    {
        yield new RtWork();
        if ($this->kind() !== RtRowKind::Text) {
            return false;
        }
        $cursor = $this->text();
        $offset = 0;
        while (($part = yield from $cursor->advance()) !== null) {
            $length = strlen($part);
            if (substr($text, $offset, $length) !== $part) {
                return false;
            }
            $offset += $length;
        }

        return $offset === strlen($text);
    }

    /**
     * Only intrinsically bounded domains, such as an ISO moment, may use this door.
     *
     * @param int $maxBytes Domain's maximum text length
     * @return Generator<int, RtWork, int, string> Bounded native string
     * @throws InvalidFormatException When the value is not text or exceeds the domain bound
     */
    public function boundedText(int $maxBytes): Generator
    {
        $cursor = $this->text();
        $text = '';
        while (($part = yield from $cursor->advance()) !== null) {
            if (strlen($text) + strlen($part) > $maxBytes) {
                throw new InvalidFormatException('Runtime text exceeds its domain length');
            }
            $text .= $part;
        }

        return $text;
    }

    /**
     * Explicit application/legacy materialization; never used by the codec path.
     *
     * @return mixed Native value, preserving map keys and byte order
     */
    public function toNative(): mixed
    {
        return self::materialize($this->root);
    }

    /**
     * @internal Explicit native door; the caller already holds this root for the call.
     *
     * @param RtRowPage $root Immutable root
     * @return mixed Materialized application value
     */
    public static function materialize(RtRowPage $root): mixed
    {
        if ($root->kind === RtRowKind::Scalar) {
            return $root->scalar;
        }
        if ($root->kind === RtRowKind::Text) {
            $text = '';
            for ($leaf = $root->first; $leaf !== null; $leaf = $leaf->next) {
                $text .= $leaf->scalar;
            }

            return $text;
        }
        $values = [];
        for ($entry = $root->first; $entry !== null; $entry = $entry->next) {
            $values[self::materialize($entry->first)] = self::materialize($entry->second);
        }

        return $values;
    }
}
