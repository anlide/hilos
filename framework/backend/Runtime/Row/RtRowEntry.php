<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Hilos\Runtime\RtRowValues;

/** One map/list entry; keys can themselves be segmented text. */
final readonly class RtRowEntry
{
    /**
     * @param RtRowValues $key Segmented key
     * @param RtRowValues $value Segmented value
     */
    public function __construct(public RtRowValues $key, public RtRowValues $value)
    {
    }
}
