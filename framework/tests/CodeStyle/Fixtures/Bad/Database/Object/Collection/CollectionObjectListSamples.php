<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Database\Object\Collection;

use Deprecated;
use stdClass as CountryLocaleChoice;

/** @template T of object */
abstract class CollectionObjectListSamples
{
    /**
     * Infrastructure helper: bounded and ordered is deliberately not an exemption.
     *
     * @param list<CountryLocaleChoice> $choices Input DTOs
     * @return non-empty-list<CountryLocaleChoice> Ordered DTOs
     */
    public function choices(array $choices): array
    {
        return $choices;
    }

    /** @return CountryLocaleChoice[] DTO suffix */
    private function suffix(): array
    {
        return [];
    }

    /** @return array<string, CountryLocaleChoice|null> Nullable elements */
    protected static function keyed(): array
    {
        return [];
    }

    /** @return ?list<CountryLocaleChoice> Nullable list */
    abstract public function nullable(): ?array;

    /** @return list<CountryLocaleChoice>|null Union list */
    abstract protected function union(): ?array;

    /**
     * @return array<
     *     int,
     *     CountryLocaleChoice|string|null
     * > Multiline type
     */
    #[Deprecated(message: 'Use a collection', since: '1')]
    abstract public function attributed(): array;

    /** @return (CountryLocaleChoice&object)[] Intersection element */
    abstract public function intersection(): array;

    /** @return array<CountryLocaleChoice> Single generic argument */
    abstract public function genericArray(): array;

    /** @return non-empty-array<int, CountryLocaleChoice> Nonempty keyed array */
    abstract public function nonemptyArray(): array;

    /** @return list<CountryLocaleChoice<int>> Generic object element */
    abstract public function genericObject(): array;

    /** @return list<T> Class-local object bound */
    abstract public function bounded(): array;

    /**
     * @template U of CountryLocaleChoice
     * @return list<U> Method-local class bound
     */
    abstract public function localBound(): array;

    /** @return list<object> Explicit object */
    abstract public function objects(): array;

    /** @return self[] Current class */
    abstract public function selves(): array;

    /** @return list<static> Late static class */
    abstract public function statics(): array;

    /** @return array<$this> Current instance */
    abstract public function instances(): array;

    /** @return list<countryLocaleChoice> Case is not a type discriminator */
    abstract public function lowercase(): array;

    /**
     * @param list<CountryLocaleChoice> $choices Legal input direction
     * @return list<CountryLocaleChoice> Illegal output direction
     */
    abstract public static function fromArray(array $choices): array;

    /**
     * @param list<CountryLocaleChoice> $choices Illegal input direction
     * @return list<CountryLocaleChoice> Legal output direction
     */
    abstract public function toArray(array $choices): array;

    /** @return list<CountryLocaleChoice> Substrings grant no exemption */
    abstract public function toArrayLike(): array;

    /** @param list<CountryLocaleChoice> $choices Substrings grant no exemption */
    abstract public function fromArrayLike(array $choices): void;

    /** @return (list<CountryLocaleChoice> | null) Parenthesized spaced union */
    abstract public function parentheses(): ?array;

    /** @return list<CountryLocaleChoice> & iterable Intersection wrapper */
    abstract public function listIntersection(): array;

    /** @param CountryLocaleChoice[] &$choices Real reference parameter */
    abstract public function reference(array &$choices): void;

    /** @param list<$this> ...$choices Real variadic parameter */
    abstract public function variadic(array ...$choices): void;

    /**
     * @param
     *     array<int, CountryLocaleChoice>
     *     $choices Multiline parameter and type
     * @return
     *     list<CountryLocaleChoice> Multiline return tag
     */
    abstract public function continued(array $choices): array;

    /** @return (list<int>)|(list<CountryLocaleChoice>) Separate parenthesis wrappers */
    abstract public function branches(): array;
}
