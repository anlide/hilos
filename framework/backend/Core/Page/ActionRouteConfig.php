<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/**
 * ActionRouteConfig - Maps action names to page names.
 */
class ActionRouteConfig
{
    /**
     * @param array<string,string> $routes Action name to page name mapping.
     */
    public function __construct(private array $routes = [])
    {
    }

    /**
     * Resolve page name by action.
     *
     * @param string $action
     * @return ?string
     */
    public function getPageForAction(string $action): ?string
    {
        $page = $this->routes[$action] ?? null;
        return $page === '' ? null : $page;
    }
}
