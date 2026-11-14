<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

/**
 * Width of one snapshot: the whole source, the named rows, or the rows of the named sets.
 *
 * An explicit scope says exactly what it covers, so an explicit empty one covers nothing and
 * removes nothing. The peer frame spells the whole source as two empty lists; that spelling is
 * translated where the frame is built or read, never here.
 */
final readonly class RtSnapshotScope
{
    /**
     * @param ?array<string, true> $rowKeys Named rows, null for another width
     * @param ?array<string, true> $setKeys Named sets, null for another width
     */
    private function __construct(private ?array $rowKeys, private ?array $setKeys)
    {
    }

    /** @return self Every row of the source */
    public static function whole(): self
    {
        return new self(null, null);
    }

    /**
     * @param iterable<string> $stateIds Row ids the snapshot covers
     * @return self Exactly those rows
     */
    public static function rows(iterable $stateIds): self
    {
        $keys = [];
        foreach ($stateIds as $stateId) {
            $keys[$stateId] = true;
        }

        return new self($keys, null);
    }

    /**
     * @param iterable<string> $setKeys Set keys, read on the field the row class names as its SET_VIA
     * @return self Exactly the rows standing in those sets
     */
    public static function sets(iterable $setKeys): self
    {
        $keys = [];
        foreach ($setKeys as $setKey) {
            $keys[$setKey] = true;
        }

        return new self(null, $keys);
    }

    /** @return bool Whether the scope is the whole source */
    public function isWhole(): bool
    {
        return $this->rowKeys === null && $this->setKeys === null;
    }

    /** @return bool Whether the scope is explicit and names nothing, so it covers and removes nothing */
    public function isEmpty(): bool
    {
        return $this->rowKeys === [] || $this->setKeys === [];
    }

    /** @return ?list<string> Named rows, null for another width */
    public function rowKeys(): ?array
    {
        return $this->rowKeys === null ? null : array_map(strval(...), array_keys($this->rowKeys));
    }

    /** @return ?list<string> Named sets, null for another width */
    public function setKeys(): ?array
    {
        return $this->setKeys === null ? null : array_map(strval(...), array_keys($this->setKeys));
    }

    /**
     * @param string $stateId Row id
     * @param ?string $setKey Set the row stands in, null for nobody's set
     * @return bool Whether the row lies within this scope
     */
    public function admits(string $stateId, ?string $setKey): bool
    {
        if ($this->rowKeys !== null) {
            return isset($this->rowKeys[$stateId]);
        }
        if ($this->setKeys !== null) {
            return $setKey !== null && isset($this->setKeys[$setKey]);
        }

        return true;
    }
}
