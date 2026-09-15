<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/** Seeds the empty inherited object-collection declaration that must stop resolution. */
abstract class CollectionBase
{
    public const string OBJECT_COLLECTION_CLASS = '';
}
