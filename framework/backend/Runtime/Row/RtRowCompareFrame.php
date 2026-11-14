<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Hilos\Runtime\RtRowReader;

/** @internal One bounded equality frame; the arena detaches abandoned chains. */
final class RtRowCompareFrame
{
    public const int NEXT_ENTRY = 0;
    public const int COMPARE_VALUE = 1;

    public int $phase = self::NEXT_ENTRY;
    public ?RtRowCursor $leftCursor = null;
    public ?RtRowCursor $rightCursor = null;
    public ?RtRowEntry $leftEntry = null;
    public ?RtRowEntry $rightEntry = null;

    /**
     * @param RtRowReader $left Current subvalue
     * @param RtRowReader $right Baseline subvalue
     * @param ?self $previous Parent traversal frame
     */
    public function __construct(public readonly RtRowReader $left, public readonly RtRowReader $right, public ?self $previous)
    {
    }
}
