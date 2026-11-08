<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Router\PageSubscription;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;

/** Exact router and immutable subscription selected before an old-owner continuation can yield. */
final readonly class PageSubscriptionContext
{
    /**
     * @param SignalRouter $router Original routing incarnation
     * @param string $acceptKey Original connection key
     * @param ?PageSubscription $subscription Original subscription, absent before its first subscribe
     */
    public function __construct(public SignalRouter $router, public string $acceptKey, public ?PageSubscription $subscription)
    {
    }

    /** @return bool Whether this exact original record still belongs to the active routing incarnation */
    public function isCurrent(): bool
    {
        return Hilos::$sr === $this->router && $this->router->pageSubscription($this->acceptKey) === $this->subscription;
    }
}
