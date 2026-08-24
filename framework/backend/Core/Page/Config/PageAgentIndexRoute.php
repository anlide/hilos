<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Config;

use Hilos\Core\Topology\PageAgentIndexRouteRegistry;

/**
 * Per-instance route one page declares: where its agent index comes from, and who
 * serves the subscription when no index can be determined.
 *
 * The parsed form of {@see PageAgentIndexKey}, built once per topology read
 * ({@see PageAgentIndexRouteRegistry}) so the master resolves an address off a typed
 * record instead of re-checking a raw config array on every subscribe.
 */
final readonly class PageAgentIndexRoute
{
    /**
     * @param PageAgentIndexSource $source Where the instance index is read from
     * @param ?string $param Subscription param carrying the index; null unless the source is a param
     * @param string $fallbackAgentType Agent type serving the subscription when no index can be determined
     */
    public function __construct(
        public PageAgentIndexSource $source,
        public ?string $param,
        public string $fallbackAgentType,
    ) {
    }
}
