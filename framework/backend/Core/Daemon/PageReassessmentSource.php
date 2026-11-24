<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Router\SignalSource;

/** A re-decision carries its exact subscription through this master's queue and agent holds. */
final class PageReassessmentSource extends SignalSource
{
    /**
     * @param string $source Source sent across IPC, where the master-local context is no longer used
     * @param PageSubscriptionContext $context Subscription that authorized this delivery
     * @param bool $identityChanged Whether a confirmed connection fact changed the owner or session
     */
    public function __construct(
        string $source,
        public readonly PageSubscriptionContext $context,
        public readonly bool $identityChanged = false,
    ) {
        parent::__construct($source);
    }
}
