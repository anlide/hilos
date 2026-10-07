<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** One action in a bounded browser-session window. */
final readonly class AnalyticsSectionAction
{
    /**
     * @param int $id Analytics action number
     * @param int $createdTs Source moment in milliseconds
     * @param ?string $pageName Page name when the action has a page session
     * @param string $actionName Recorded action name
     */
    public function __construct(
        public int $id,
        public int $createdTs,
        public ?string $pageName,
        public string $actionName,
    ) {
    }
}
