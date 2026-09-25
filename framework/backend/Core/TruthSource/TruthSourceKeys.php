<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;

/**
 * TruthSourceKeys - the width of one truth-source claim: every row of a collection, the rows
 * named one by one, or the rows of one set.
 *
 * Three named states and no fourth. What it replaces was `list<string>|true`, where the absence of
 * a list said both "the whole collection" and "the list was never collected", and a reader had no
 * way to tell the two apart. Every value here comes from a factory that says which state it is,
 * so a claim cannot be written without naming its width out loud - which is why the constructor
 * is private and why the parameter it fills has no default anywhere.
 *
 * A width of no rows at all is not the absence of a claim: it is what the right to create is
 * ({@see TruthSourceRegistry::registerCreate()}), because minting a record is not owning one.
 * That is also why the set is kept in a field of its own rather than as a claim listing no row:
 * an empty list is already taken by the right to create, and a set laid into one would be read
 * as the right to mint rows anywhere.
 */
final readonly class TruthSourceKeys
{
    /**
     * @param bool $everyKey Whether the claim runs over the whole collection
     * @param list<string> $keys Rows the claim names, empty when it runs over the whole collection or over a set
     * @param ?string $setKey Set the claim runs over, null when it runs over the whole collection or over named rows
     */
    private function __construct(
        private bool $everyKey,
        private array $keys,
        private ?string $setKey,
    ) {
    }

    /**
     * @return self A claim over every row of the collection
     */
    public static function all(): self
    {
        return new self(true, [], null);
    }

    /**
     * @param string ...$keys Rows the claim covers; name none of them for a claim that owns no row
     * @return self A claim over the named rows alone
     */
    public static function listed(string ...$keys): self
    {
        return new self(false, $keys, null);
    }

    /**
     * @param string $setKey Value of the table's set column that names the owner's set
     * @return self A claim over the rows of that one set
     * @throws InvalidArgumentException When the set key is empty and so names nobody's set
     */
    public static function set(string $setKey): self
    {
        if ($setKey === '') {
            throw new InvalidArgumentException('A set key names the owner of a set; an empty one names nobody');
        }

        return new self(false, [], $setKey);
    }

    /**
     * @return bool True when the claim runs over the whole collection
     */
    public function coversEveryKey(): bool
    {
        return $this->everyKey;
    }

    /**
     * @return bool True when the claim owns no row at all - the width the right to create has
     */
    public function coversNoKey(): bool
    {
        return !$this->everyKey && $this->keys === [] && $this->setKey === null;
    }

    /**
     * @return bool True when the claim runs over the rows of one set
     */
    public function coversSet(): bool
    {
        return $this->setKey !== null;
    }

    /**
     * @return string Value of the set column that names the set this claim runs over
     * @throws LogicException When the claim does not run over a set
     */
    public function setKey(): string
    {
        return $this->setKey ?? throw new LogicException('Only a claim over a set has a set key');
    }

    /**
     * Answers by the row's key alone, so a claim over a set answers false: the key of a row does
     * not say whose set it is in. The write door asks {@see coversRow()} instead.
     *
     * @param string $key Row key to ask about
     * @return bool True when this claim covers that row
     */
    public function covers(string $key): bool
    {
        return $this->everyKey || in_array($key, $this->keys, true);
    }

    /**
     * Whether the claim covers a write of one row.
     *
     * The whole collection covers every write and named rows cover the rows they name, whatever
     * set those rows are in. A set covers a write only when every set key the write touches is its
     * own: a row stored in another set is not its to edit, a row moved out of or into another set
     * is written into that set too, and a write that touches no set is a row in nobody's set.
     *
     * @param string $key Row key the write is about to touch
     * @param list<string> $setKeys Set keys the write touches, empty for a row outside every set
     * @return bool True when this claim covers that write
     */
    public function coversRow(string $key, array $setKeys): bool
    {
        if ($this->setKey === null) {
            return $this->covers($key);
        }

        if ($setKeys === []) {
            return false;
        }

        foreach ($setKeys as $setKey) {
            if ($setKey !== $this->setKey) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the claim covers one statement over every row of one set.
     *
     * The whole collection covers every set, the empty key of nobody's set included, and a set
     * covers its own. Named rows cover no set: the statement touches rows the claim does not name,
     * among them rows born after the claim was laid, and nothing short of a query says which.
     *
     * @param string $setKey Value of the set column the statement cuts the table by, empty for nobody's set
     * @return bool True when this claim covers every row of that set
     */
    public function coversEveryRowOfSet(string $setKey): bool
    {
        return $this->everyKey || $this->setKey === $setKey;
    }

    /**
     * @return list<string> Rows named one by one, empty when the claim runs over the whole collection or over a set
     */
    public function listedKeys(): array
    {
        return $this->keys;
    }
}
