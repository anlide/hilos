<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Database\Object\Collection;

use Deprecated;
use stdClass as ChoiceDTO;
use stdClass as Choices;

/**
 * @template T
 * @method list<ChoiceDTO> documentedOnly()
 * @return list<ChoiceDTO> A class doc is not a method doc
 */
abstract class CollectionObjectListLookAlikes
{
    /** @return list<ChoiceDTO> A property doc is not a method doc */
    private array $choices = [];

    /**
     * @param array<int, ChoiceDTO> $choices Legal direction, case-insensitive
     * @return self Typed result
     */
    #[Deprecated(message: 'An attribute with nested brackets', since: ['1'][0])]
    abstract public static function FrOmArRaY(array $choices): self;

    /** @return list<ChoiceDTO> Legal direction, case-insensitive */
    abstract public function ToArRaY(): array;

    /** @return Choices<ChoiceDTO> Typed collection */
    abstract public function typed(): Choices;

    /** @return list<string|int|bool|float|null|mixed|array-key> Scalar elements */
    abstract public function scalars(): array;

    /** @return array<class-string<ChoiceDTO>> Class names are strings */
    abstract public function classNames(): array;

    /** @return array<ChoiceDTO, string> The key is not the element */
    abstract public function keys(): array;

    /** @return list<key-of<ChoiceDTO>|value-of<ChoiceDTO>> Scalar utility types */
    abstract public function utilityTypes(): array;

    /** @return list<T> Unconstrained class template */
    abstract public function template(): array;

    /**
     * @template U of mixed
     * @return U[] No object bound
     */
    abstract public function unbounded(): array;

    /**
     * @template V of string
     * @return list<V> Scalar bound
     */
    abstract public function scalarBound(): array;

    /** @return list<string> ChoiceDTO in prose is not an element */
    abstract public function description(): array;

    /** @return array{choices: list<ChoiceDTO>} Structured shape is outside the check */
    abstract public function shape(): array;

    /** @return list<list<ChoiceDTO>> Elements are arrays, not direct objects */
    abstract public function nestedArrays(): array;

    /** @return ChoiceDTO[][] Elements are arrays */
    abstract public function nestedSuffix(): array;

    /** @param list<ChoiceDTO> $absent Not a real method parameter */
    abstract public function staleParam(array $choices): void;

    /** @return array No PHPDoc element to judge */
    public function nativeOnly(): array
    {
        return [new ChoiceDTO()];
    }

    public function localDocs(): void
    {
        /** @var list<ChoiceDTO> $choices Local variable */
        $choices = [];
        /** @return list<ChoiceDTO> A closure is not a named method */
        $closure = static function () use ($choices): array {
            return $choices;
        };
        /** @return list<ChoiceDTO> A nested named function is not a method */
        function nested(): array
        {
            return [];
        }
        $text = '/** @return list<ChoiceDTO> */';
        $interpolated = "{$text}";
        // @return list<ChoiceDTO> Ordinary comment
        /* @return list<ChoiceDTO> Ordinary block comment */
    }

    abstract public function afterBody(): array;

    /** @return list<string> A name substring grants nothing and requires nothing */
    abstract public function fromArrayLike(): array;

    /** @param list<string> $choices Scalar substring case */
    abstract public function toArrayLike(array $choices): void;
}

/** @return list<ChoiceDTO> A top-level function is not a method */
function standalone(): array
{
    return [];
}
