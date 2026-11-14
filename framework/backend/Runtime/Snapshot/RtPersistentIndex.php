<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal Persistent binary Patricia index over fixed-size hashes or ordinals.
 * Paths are arena pages too: cancel never drops a native traversal graph.
 * SCAFFOLD: used by the isolated committed version store, not live collections yet.
 */
final readonly class RtPersistentIndex
{
    private const int BITS_PER_BYTE = 8;

    /**
     * @param RtRowArena $arena Shared descriptor/value owner
     */
    public function __construct(private RtRowArena $arena)
    {
    }

    /**
     * The caller retains root throughout traversal and while using the returned page.
     *
     * @param ?RtRowPage $root Retained immutable index root
     * @param string $key Fixed-size prepared hash/ordinal
     * @return Generator<int, RtWork, int, ?RtRowPage> Indexed payload or absence
     * @throws LogicException When an index contains incompatible key widths
     */
    public function find(?RtRowPage $root, string $key): Generator
    {
        yield new RtWork();
        $page = $root;
        while ($page !== null && $page->kind === RtRowKind::IndexBranch) {
            yield new RtWork();
            $page = self::bit($key, $page->scalar) === 0 ? $page->first : $page->second;
        }
        if ($page === null || (yield from self::difference($key, $page->scalar)) !== null) {
            return null;
        }

        return $page->first;
    }

    /**
     * Path-copy visits bounded nodes; only a leaf's bucket is replaced.
     * Inputs remain retained by the caller until the resulting root is ready.
     *
     * @param ?RtRowPage $root Current retained root
     * @param string $key Fixed-size prepared key
     * @param ?RtRowPage $payload New retained payload, or null to remove the key
     * @return Generator<int, RtWork, int, ?RtRowValues> Independent prepared index lease
     * @throws LogicException When the index contains incompatible key widths or a closed path
     */
    public function replace(?RtRowPage $root, string $key, ?RtRowPage $payload): Generator
    {
        $leaf = $root;
        while ($leaf !== null && $leaf->kind === RtRowKind::IndexBranch) {
            yield new RtWork();
            $leaf = self::bit($key, $leaf->scalar) === 0 ? $leaf->first : $leaf->second;
        }
        $difference = $leaf === null ? null : yield from self::difference($key, $leaf->scalar);
        if ($payload === null && ($leaf === null || $difference !== null)) {
            yield new RtWork();

            return $root === null ? null : new RtRowValues($this->arena, $root, RtRowRole::Transient);
        }

        $path = null;
        $page = $root;
        while ($page !== null && $page->kind === RtRowKind::IndexBranch
            && ($difference === null || $page->scalar < $difference)) {
            yield new RtWork();
            $direction = self::bit($key, $page->scalar);
            $path = yield from RtVersionPages::make(
                $this->arena,
                RtRowKind::Path,
                $direction,
                $page,
                next: $path?->rootIn($this->arena),
            );
            $page = $direction === 0 ? $page->first : $page->second;
        }

        $prepared = $payload === null ? null : yield from RtVersionPages::make(
            $this->arena,
            RtRowKind::IndexLeaf,
            $key,
            $payload,
        );
        if ($difference !== null) {
            $prepared = yield from RtVersionPages::make(
                $this->arena,
                RtRowKind::IndexBranch,
                $difference,
                self::bit($key, $difference) === 0 ? $prepared->rootIn($this->arena) : $page,
                self::bit($key, $difference) === 0 ? $page : $prepared->rootIn($this->arena),
            );
        }
        while ($path !== null) {
            yield new RtWork();
            $frame = $path->rootIn($this->arena);
            $branch = $frame->first;
            $sibling = $frame->scalar === 0 ? $branch->second : $branch->first;
            if ($prepared === null) {
                $prepared = new RtRowValues($this->arena, $sibling, RtRowRole::Transient);
            } else {
                $prepared = yield from RtVersionPages::make(
                    $this->arena,
                    RtRowKind::IndexBranch,
                    $branch->scalar,
                    $frame->scalar === 0 ? $prepared->rootIn($this->arena) : $sibling,
                    $frame->scalar === 0 ? $sibling : $prepared->rootIn($this->arena),
                );
            }
            $path = $frame->next === null ? null : new RtRowValues($this->arena, $frame->next, RtRowRole::Transient);
        }

        return $prepared;
    }

    /**
     * @param int $ordinal Positive insertion ordinal or committed revision
     * @return string Fixed-size big-endian key preserving numeric order
     */
    public static function ordinal(int $ordinal): string
    {
        return pack('J', $ordinal);
    }

    /**
     * @param string $key Fixed-size key
     * @param int $position Bit position from the most significant bit
     * @return int Branch direction
     */
    private static function bit(string $key, int $position): int
    {
        return (ord($key[intdiv($position, self::BITS_PER_BYTE)]) >> (self::BITS_PER_BYTE - 1 - $position % self::BITS_PER_BYTE)) & 1;
    }

    /**
     * @param string $left Prepared fixed-size key
     * @param string $right Prepared fixed-size key of the same index
     * @return Generator<int, RtWork, int, ?int> First differing bit, or equality
     * @throws LogicException When an index mixes key widths
     */
    private static function difference(string $left, string $right): Generator
    {
        yield new RtWork();
        if (strlen($left) !== strlen($right)) {
            throw new LogicException('A persistent RT index cannot mix key widths');
        }
        for ($byte = 0; $byte < strlen($left); ++$byte) {
            yield new RtWork(1);
            $difference = ord($left[$byte]) ^ ord($right[$byte]);
            if ($difference === 0) {
                continue;
            }
            for ($bit = 0; $bit < self::BITS_PER_BYTE; ++$bit) {
                yield new RtWork();
                if (($difference & (1 << (self::BITS_PER_BYTE - 1 - $bit))) !== 0) {
                    return $byte * self::BITS_PER_BYTE + $bit;
                }
            }
        }

        return null;
    }
}
