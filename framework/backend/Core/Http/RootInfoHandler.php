<?php

declare(strict_types=1);

namespace Hilos\Core\Http;

use Hilos\Constants\ApiEndpoint;
use Hilos\Constants\HttpConstants;

/**
 * Invokable handler for the daemon HTTP status server root (GET /).
 *
 * Returns a short, static JSON hint that names the service and points to the live
 * {@see StatusHandler} endpoint, so an operator or health check that opens the root by
 * hand gets a self-describing 200 instead of a bare 404. Registered by
 * {@see DaemonManager::boot()} as the default GET / route before the per-daemon routes,
 * so an explicit demo route on / overrides it. Stateless: no worker server, DB, or RT.
 */
final class RootInfoHandler
{
    /** @var string Service identifier reported in the hint body */
    private const string SERVICE_NAME = 'hilos-daemon';

    /** @var string Service liveness marker reported in the hint body */
    private const string SERVICE_STATUS = 'ok';

    /** @var string Hint body key for the service identifier */
    private const string KEY_SERVICE = 'service';

    /** @var string Hint body key for the service liveness marker */
    private const string KEY_STATUS = 'status';

    /** @var string Hint body key for the endpoint map */
    private const string KEY_ENDPOINTS = 'endpoints';

    /** @var string Endpoint-map key for the status endpoint */
    private const string KEY_STATUS_ENDPOINT = 'status';

    /**
     * Builds the static JSON hint response pointing to the status endpoint.
     *
     * @param array<string, mixed> $args Route handler arguments (request, params); unused
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     */
    public function __invoke(array $args): array
    {
        $hint = [
            self::KEY_SERVICE => self::SERVICE_NAME,
            self::KEY_STATUS => self::SERVICE_STATUS,
            self::KEY_ENDPOINTS => [
                self::KEY_STATUS_ENDPOINT => ApiEndpoint::STATUS->value,
            ],
        ];

        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => json_encode($hint, JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }
}
