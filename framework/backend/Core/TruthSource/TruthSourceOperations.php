<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

/**
 * TruthSourceOperations - what one truth-source claim may do to the rows it covers.
 *
 * The second axis of a right, beside the width {@see TruthSourceKeys} names. A set rather than a
 * list: an operation named twice is the same right, so the constructor keeps the first of each
 * and the order a caller wrote them in is the order they are read back.
 *
 * The constructor is public and variadic where {@see TruthSourceKeys} hides its own, and PHP is
 * the one asking rather than us: only a `new` expression is accepted in the default value of a
 * parameter, and unpacking a constant there is refused outright, so the registration default
 * ({@see AbstractTruthSourceRegistry::register()}) has no other form to take. The variadic is
 * also the check this class is here for - a value that is not an operation is a TypeError at the
 * call that wrote the claim, not a refusal an hour later at the write it allowed.
 */
final readonly class TruthSourceOperations
{
    /** @var list<TruthSourceOperation> Operations this set allows, each named once */
    private array $operations;

    /**
     * @param TruthSourceOperation ...$operations Operations the claim allows
     */
    public function __construct(TruthSourceOperation ...$operations)
    {
        $named = [];
        foreach ($operations as $operation) {
            if (!in_array($operation, $named, true)) {
                $named[] = $operation;
            }
        }

        $this->operations = $named;
    }

    /**
     * @return self Every operation - the right a claim gets when its registration names none
     */
    public static function all(): self
    {
        return new self(...TruthSourceOperation::ALL);
    }

    /**
     * @param TruthSourceOperation ...$operations Operations the claim allows
     * @return self A claim allowing those operations alone
     */
    public static function of(TruthSourceOperation ...$operations): self
    {
        return new self(...$operations);
    }

    /**
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @return bool True when this set allows the operation
     */
    public function allows(TruthSourceOperation $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * @return bool True when the set holds every operation there is
     */
    public function isComplete(): bool
    {
        foreach (TruthSourceOperation::ALL as $operation) {
            if (!$this->allows($operation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool True when the set allows nothing at all
     */
    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    /**
     * @param TruthSourceOperation $operation Operation to add
     * @return self The same set with that operation in it
     */
    public function with(TruthSourceOperation $operation): self
    {
        return new self(...[...$this->operations, $operation]);
    }

    /**
     * @param TruthSourceOperation $operation Operation to take away
     * @return self The same set without that operation
     */
    public function without(TruthSourceOperation $operation): self
    {
        return new self(...array_filter(
            $this->operations,
            static fn (TruthSourceOperation $held): bool => $held !== $operation,
        ));
    }

    /**
     * @param self $other Set to fold into this one
     * @return self Every operation either set allows
     */
    public function merge(self $other): self
    {
        return new self(...[...$this->operations, ...$other->operations]);
    }

    /**
     * @return list<TruthSourceOperation> Operations this set allows, each named once
     */
    public function asList(): array
    {
        return $this->operations;
    }

    /**
     * @return string Operation values separated by a comma, as a refusal message spells them
     */
    public function asText(): string
    {
        return implode(', ', array_map(
            static fn (TruthSourceOperation $operation): string => $operation->value,
            $this->operations,
        ));
    }
}
