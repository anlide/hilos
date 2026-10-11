<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** One place in an analytics window's chosen order. */
final readonly class AnalyticsWindowAnchor
{
    /**
     * @param ?int $moment Source moment in milliseconds; nullable for row-number order
     * @param int $id Analytics row number
     */
    public function __construct(public ?int $moment, public int $id)
    {
    }
}
