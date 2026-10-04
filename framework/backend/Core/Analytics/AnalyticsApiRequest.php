<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** The request description held in the master until it can write one journal record. */
final readonly class AnalyticsApiRequest
{
    /**
     * @param string $key Random key naming the request
     * @param ?string $sessionToken Browser session token, or null
     * @param string $method HTTP method
     * @param string $path Request path
     * @param ?array<string, mixed> $params Route parameters
     * @param ?string $userAgent User-Agent header
     * @param ?string $acceptLanguage Accept-Language header
     * @param int $startedTs Request start time in milliseconds
     */
    public function __construct(
        public string $key,
        public ?string $sessionToken,
        public string $method,
        public string $path,
        public ?array $params,
        public ?string $userAgent,
        public ?string $acceptLanguage,
        public int $startedTs,
    ) {
    }
}
