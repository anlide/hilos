<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Hilos\Runtime\RtRowValues;

/** Completed segmented diff and the baseline values of precisely its changed fields. */
final readonly class RtRowChanges
{
    /**
     * @param RtRowValues $diff Changed fields
     * @param RtRowValues $previous Previous values of changed fields present in the baseline
     */
    public function __construct(public RtRowValues $diff, public RtRowValues $previous)
    {
    }
}
