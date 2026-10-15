<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Database\View\Collection;

use Attribute;
use stdClass as ChoiceDTO;

interface CollectionObjectListSamples
{
    /** @param array<int, ChoiceDTO> $choices Interface input */
    public function accept(array $choices): void;

    /** @return list<ChoiceDTO> Interface output */
    public function choices(): array;
}

trait CollectionObjectListTrait
{
    /** @return ChoiceDTO[] Private trait method */
    private function choices(): array
    {
        return [];
    }

    /**
     * @param non-empty-array<string, ChoiceDTO> $choices Attributed static input
     * @return (ChoiceDTO|null)[] Nullable suffix element
     */
    #[CollectionSampleAttribute]
    #[CollectionSampleAttribute]
    protected static function collect(#[CollectionSampleAttribute] array $choices): array
    {
        return $choices;
    }
}

#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
final class CollectionSampleAttribute
{
}
