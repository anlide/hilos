<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Hilos;
use WeakReference;

/** Post-preparation authority of one original operation, carried unchanged through dispatch and holds. */
final readonly class GroupOperationAuthority
{
    /**
     * @param WeakReference<SignalRouter> $router Original routing lifetime
     * @param list<GroupOperationBasis> $bases Relevant original name observations, at most two
     */
    public function __construct(private WeakReference $router, private array $bases)
    {
    }

    /** @return bool Whether the original router and every relevant membership observation still hold */
    public function isCurrent(): bool
    {
        if ($this->router->get() !== Hilos::$sr || Hilos::$sr === null) {
            return false;
        }
        foreach ($this->bases as $basis) {
            if (!$basis->isCurrent()) {
                return false;
            }
        }
        return true;
    }
}
