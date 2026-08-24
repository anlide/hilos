<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexRoute;
use Hilos\Core\Page\Config\PageAgentIndexSource;

/**
 * Computes per-instance page route records from registered page classes.
 */
final class PageAgentIndexRouteRegistry
{
    /**
     * Returns per-instance routes declared by page classes, keyed by page name.
     *
     * Pages that declare nothing are absent from the result rather than present with a
     * null: absence is what the master reads as "route by agent type, as before".
     *
     * Malformed entries are skipped silently, the same way the other computed registries
     * skip theirs ({@see AgentSignalRouteRegistry::indexFields}). Naming what is wrong
     * with a declaration belongs to {@see TopologyValidator}, which reports every error
     * of a project at once; a registry that threw would report the first one and hide
     * the rest.
     *
     * @param array $pages Page registry
     * @return array<string, PageAgentIndexRoute> Per-instance route keyed by page name
     */
    public static function routes(array $pages): array
    {
        $indexRoutes = [];
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            $route = self::route($pageClass::SUBSCRIPTION_AGENT_INDEX);
            if ($route !== null) {
                $indexRoutes[$page] = $route;
            }
        }

        return $indexRoutes;
    }

    /**
     * Parses one page's per-instance declaration.
     *
     * @param array $declaration Raw SUBSCRIPTION_AGENT_INDEX declaration
     * @return ?PageAgentIndexRoute Parsed route, or null when the page declares nothing usable
     */
    private static function route(array $declaration): ?PageAgentIndexRoute
    {
        $source = $declaration[PageAgentIndexKey::SOURCE] ?? null;
        if (!$source instanceof PageAgentIndexSource) {
            return null;
        }

        $fallbackAgentType = $declaration[PageAgentIndexKey::FALLBACK_AGENT_TYPE] ?? null;
        if (!is_string($fallbackAgentType) || $fallbackAgentType === '') {
            return null;
        }

        $param = $declaration[PageAgentIndexKey::PARAM] ?? null;
        if (!is_string($param) || $param === '') {
            $param = null;
        }

        if ($source === PageAgentIndexSource::PARAM && $param === null) {
            return null;
        }

        return new PageAgentIndexRoute($source, $param, $fallbackAgentType);
    }
}
