<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use WeakReference;

/** A shared local name observation; the registry holds it weakly until its last observer retires. */
final readonly class GroupOperationBasis
{
    /**
     * @param WeakReference<SubscriptionRegistry> $registry Original subscription owner
     * @param string $acceptKey Original connection
     * @param string $group Exact name or absent lookup prefix
     * @param bool $prefix Whether descendant names also invalidate this observation
     */
    public function __construct(
        private WeakReference $registry,
        public string $acceptKey,
        public string $group,
        public bool $prefix,
    ) {
    }

    /** Releases this observation's weak index slot without retaining its original owner. */
    public function __destruct()
    {
        $this->registry->get()?->releaseGroupOperationBasis($this);
    }

    /** @return bool Whether no relevant membership mutation has replaced this observation */
    public function isCurrent(): bool
    {
        return $this->registry->get()?->isCurrentGroupOperationBasis($this) ?? false;
    }
}
