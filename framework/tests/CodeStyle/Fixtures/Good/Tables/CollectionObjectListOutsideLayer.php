<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Tables;

use stdClass as ChoiceDTO;

interface CollectionObjectListOutsideLayer
{
    /**
     * @param list<ChoiceDTO> $choices Other layers are outside this detector
     * @return list<ChoiceDTO> Same signature as a judged collection
     */
    public function choices(array $choices): array;
}
