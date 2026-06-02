<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/**
 * ActionRouteConfig - Maps action names to page names.
 */
final class ActionRouteConfig
{
    /**
     * Create action route config with action-to-page mapping.
     *
     * @param array<string, string> $routes Action name to page name mapping
     */
    public function __construct(private readonly array $routes = [])
    {
    }

    /**
     * Resolve page name by action.
     *
     * @param string $action Action name
     * @return ?string Page name or null if not found
     */
    public function getPageForAction(string $action): ?string
    {
        $page = $this->routes[$action] ?? null;
        return $page === '' ? null : $page;
    }
}
