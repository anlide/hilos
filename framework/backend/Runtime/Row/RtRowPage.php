<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

/**
 * @internal One bounded page. The arena independently retains EVERY page, so dropping
 * a root cannot run a recursive native destructor. Retire severs its edges first.
 */
final class RtRowPage
{
    /** Deterministic bookkeeping charge, not an estimate of Zend allocator overhead. */
    public const int OVERHEAD_BYTES = 64;
    public const int LEAF_BYTES = 4096;

    public int $references = 0;
    public int $logicalBytes;
    public ?self $older = null;
    public ?self $newer = null;
    public ?self $first = null;
    public ?self $second = null;
    public ?self $next = null;

    /**
     * @param RtRowKind $kind Page shape
     * @param null|bool|int|float|string $scalar Bounded payload
     * @param int $payloadBytes Logical payload size
     */
    public function __construct(
        public readonly RtRowKind $kind,
        public null|bool|int|float|string $scalar = null,
        public readonly int $payloadBytes = 0,
    ) {
        $this->logicalBytes = self::OVERHEAD_BYTES + $payloadBytes;
    }
}
