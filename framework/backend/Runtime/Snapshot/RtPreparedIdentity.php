<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal Immutable segmented id and optional set identity, with streamed hashes.
 * SCAFFOLD: production rows do not use this identity binding yet.
 */
final readonly class RtPreparedIdentity
{
    /**
     * @internal The descriptor is already immutable and independently leased.
     *
     * @param RtRowArena $arena Process-local owner
     * @param RtRowValues $descriptor Identity page lease
     */
    public function __construct(public RtRowArena $arena, public RtRowValues $descriptor)
    {
    }

    /**
     * Hashing and text checks finish before an index/cut can retain the identity.
     *
     * @param RtRowValues $id Segmented textual row key
     * @param ?RtRowValues $setKey Segmented textual SET_VIA key, or no set
     * @return Generator<int, RtWork, int, self> Prepared immutable identity
     * @throws InvalidFormatException When a key is not text
     * @throws LogicException When input leases closed or their arenas differ
     */
    public static function prepare(RtRowValues $id, ?RtRowValues $setKey = null): Generator
    {
        $arena = $id->reader()->arena();
        $set = null;
        if ($setKey !== null) {
            $setKey->assertArena($arena);
            $set = yield from RtVersionPages::make(
                $arena,
                RtRowKind::Identity,
                yield from self::hashText($setKey),
                $setKey->rootIn($arena),
            );
        }
        $descriptor = yield from RtVersionPages::make(
            $arena,
            RtRowKind::Identity,
            yield from self::hashText($id),
            $id->rootIn($arena),
            $set?->rootIn($arena),
        );

        return new self($arena, $descriptor);
    }

    /**
     * @return string Fixed-size binary hash
     * @throws LogicException When the descriptor lease closed
     */
    public function hash(): string
    {
        return $this->descriptor->rootIn($this->arena)->scalar;
    }

    /**
     * @return RtRowValues Independent id lease
     * @throws LogicException When the descriptor lease closed
     */
    public function id(): RtRowValues
    {
        return new RtRowValues($this->arena, $this->descriptor->rootIn($this->arena)->first, RtRowRole::Transient);
    }

    /**
     * @return ?self Prepared set identity, or no set
     * @throws LogicException When the descriptor lease closed
     */
    public function set(): ?self
    {
        $page = $this->descriptor->rootIn($this->arena)->second;

        return $page === null ? null : new self($this->arena, new RtRowValues($this->arena, $page, RtRowRole::Transient));
    }

    /**
     * Compares aligned byte prefixes even when the two texts use different leaves.
     *
     * @param RtRowPage $other Prepared identity page held by the caller
     * @return Generator<int, RtWork, int, bool> Exact segmented key equality
     * @throws InvalidFormatException When an identity's text is malformed
     * @throws LogicException When the descriptor lease closed
     */
    public function matches(RtRowPage $other): Generator
    {
        $left = $this->id()->reader()->text();
        $rightValue = new RtRowValues($this->arena, $other->first, RtRowRole::Transient);
        $right = $rightValue->reader()->text();
        $leftPart = '';
        $rightPart = '';
        $leftOffset = 0;
        $rightOffset = 0;
        while (true) {
            if ($leftOffset === strlen($leftPart)) {
                $leftPart = yield from $left->advance();
                $leftOffset = 0;
            }
            if ($rightOffset === strlen($rightPart)) {
                $rightPart = yield from $right->advance();
                $rightOffset = 0;
            }
            if ($leftPart === null || $rightPart === null) {
                return $leftPart === $rightPart;
            }
            $bytes = yield new RtWork(min(strlen($leftPart) - $leftOffset, strlen($rightPart) - $rightOffset));
            if (substr($leftPart, $leftOffset, $bytes) !== substr($rightPart, $rightOffset, $bytes)) {
                return false;
            }
            $leftOffset += $bytes;
            $rightOffset += $bytes;
        }
    }

    /**
     * @param RtRowValues $text Segmented key
     * @return Generator<int, RtWork, int, string> Fixed-size binary SHA-256
     * @throws InvalidFormatException When the key is not text
     * @throws LogicException When the key lease closed
     */
    private static function hashText(RtRowValues $text): Generator
    {
        yield new RtWork();
        $hash = hash_init('sha256');
        $cursor = $text->reader()->text();
        while (($part = yield from $cursor->advance()) !== null) {
            hash_update($hash, $part);
        }
        yield new RtWork();

        return hash_final($hash, true);
    }
}
