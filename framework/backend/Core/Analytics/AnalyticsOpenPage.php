<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** An open page of a connection as the master remembers it. */
final readonly class AnalyticsOpenPage
{
    /**
     * @param string $key Page session key, 32 lowercase hex characters
     * @param string $pageName Page name from the subscribing frame
     */
    public function __construct(
        public string $key,
        public string $pageName,
    ) {
    }
}
