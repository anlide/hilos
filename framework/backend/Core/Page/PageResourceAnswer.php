<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Closure;
use Hilos\Core\Page\DTO\PagePayload;

/** A page's payload and resource, prepared together for a single subscription answer. */
final readonly class PageResourceAnswer
{
    /**
     * @param PageResource $resource Row the answer describes
     * @param PagePayload $payload Page's own part of the answer
     * @param (Closure(): void)|null $onQueued Local bookkeeping to run once the full answer has been queued
     */
    public function __construct(
        public PageResource $resource,
        public PagePayload $payload,
        public ?Closure $onQueued = null,
    ) {
    }
}
