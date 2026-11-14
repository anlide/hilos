<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;

/** @internal One bounded traversal frame, never a native array of the entire stack. */
final class RtRowCopyFrame
{
    public const int NEXT_ENTRY = 0;
    public const int COPY_KEY = 1;
    public const int COPY_VALUE = 2;
    public const int WRITE_ENTRY = 3;

    public int $phase = self::NEXT_ENTRY;
    public ?RtRowCursor $cursor = null;
    public ?RtRowWriter $writer = null;
    public ?RtRowEntry $entry = null;
    public ?RtRowValues $key = null;
    public ?RtRowValues $value = null;

    /**
     * @param RtRowReader $source Independently retained input
     * @param RtRowRole $role Completed value's role
     * @param ?self $previous Parent traversal frame
     */
    public function __construct(public readonly RtRowReader $source, public readonly RtRowRole $role, public ?self $previous)
    {
    }
}
