<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Http\RootInfoHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the daemon root (GET /) info hint handler and its routing.
 */
final class RootInfoHandlerTest extends TestCase
{
    public function testReturnsJsonOkHintPointingToStatus(): void
    {
        $response = (new RootInfoHandler())([]);

        $this->assertSame(HttpConstants::HTTP_OK, $response[HttpConstants::RESPONSE_KEY_STATUS]);
        $this->assertSame(
            HttpConstants::CONTENT_TYPE_JSON,
            $response[HttpConstants::RESPONSE_KEY_HEADERS][HttpConstants::HEADER_CONTENT_TYPE],
        );

        $body = $response[HttpConstants::RESPONSE_KEY_BODY];
        $this->assertStringContainsString('/status', $body);

        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('/status', $decoded['endpoints']['status']);
    }

    public function testRouterAnswersRootWithoutNotFound(): void
    {
        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_GET, HttpConstants::PATH_ROOT, new RootInfoHandler());

        $response = $router->route([
            HttpConstants::REQUEST_KEY_METHOD => HttpConstants::METHOD_GET,
            HttpConstants::REQUEST_KEY_PATH => HttpConstants::PATH_ROOT,
        ]);

        $this->assertNotSame(HttpConstants::HTTP_NOT_FOUND, $response[HttpConstants::RESPONSE_KEY_STATUS]);
        $this->assertSame(HttpConstants::HTTP_OK, $response[HttpConstants::RESPONSE_KEY_STATUS]);
    }
}
