<?php

declare(strict_types=1);

namespace Hilos\API\DTO;

/**
 * Completed async HTTP response.
 */
final readonly class AsyncHttpResponse
{
    /**
     * Creates completed HTTP response DTO.
     *
     * @param int $statusCode HTTP status code
     * @param string $headersRaw Raw HTTP headers
     * @param string $body Response body
     */
    public function __construct(
        public int $statusCode,
        public string $headersRaw,
        public string $body,
    ) {
    }
}
