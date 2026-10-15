<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Database\View\Collection;

use stdClass as ChoiceDTO;
use stdClass as Choices;

/** @template T of object */
interface CollectionObjectListLookAlikes
{
    /** @param list<ChoiceDTO> $choices Explicit conversion input */
    public static function fromArray(array $choices): self;

    /** @return ChoiceDTO[] Explicit conversion output */
    public function toArray(): array;

    /** @return Choices<ChoiceDTO> Typed collection */
    public function choices(): Choices;

    /**
     * @template T
     * @return list<T> Method template shadows the class bound
     */
    public function unbounded(): array;
}

trait CollectionObjectListTraitLookAlikes
{
    /** @return array<int, class-string<ChoiceDTO>> Strings */
    public function names(): array
    {
        return [];
    }
}

interface UnrelatedTemplates
{
    /** @template T of object */
    public function declares(): void;

    /**
     * @template T
     * @return list<T> Another method's bound does not leak
     */
    public function unbounded(): array;
}
