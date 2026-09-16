<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/**
 * Seeds the empty inherited object-collection declaration that must stop resolution,
 * under a tag naming a generic placeholder that must not answer before the constant.
 *
 * @property-read TObjectCollection $objectCollection
 */
abstract class CollectionBase
{
    public const string OBJECT_COLLECTION_CLASS = '';
}
