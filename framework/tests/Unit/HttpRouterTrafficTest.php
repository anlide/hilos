<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Router accounting uses the registered path, including a template, for each handler outcome. */
final class HttpRouterTrafficTest extends TestCase
{
    /** @throws EnvException When the router's test env cannot be loaded */
    protected function setUp(): void
    {
        parent::setUp();
        if (Hilos::$env === null) {
            Hilos::initEnv(dirname(__DIR__, 2));
        }
    }

    /** @throws EnvException When the router cannot read the session cookie name */
    public function testHandlerTemplateAndMissingAddress(): void
    {
        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_GET, '/status', static fn (): array => ['status' => 'ok']);
        $router->addRoute(HttpConstants::METHOD_GET, '/user/{id}', static fn (): array => ['user' => true]);

        $router->route(self::request('/status'));
        $router->route(self::request('/user/1'));
        $router->route(self::request('/user/2'));
        $router->route(self::request('/invented'));

        $now = time();
        self::assertSame(1, $router->traffic()->tally(HttpConstants::METHOD_GET, '/status', $now)->requests);
        self::assertSame(2, $router->traffic()->tally(HttpConstants::METHOD_GET, '/user/{id}', $now)->requests);
        self::assertEquals(new HttpRouteTally(0, 0, 0, null), $router->traffic()->tally(HttpConstants::METHOD_GET, '/user/1', $now));
        self::assertEquals(new HttpRouteTally(1, 0, 0, 0), $router->traffic()->unroutedTally($now));
    }

    /** @throws EnvException When the router cannot read the session cookie name */
    public function testHandlerExceptionAndAgentAddressOwnership(): void
    {
        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_GET, '/broken', static function (): never {
            throw new RuntimeException('broken');
        });
        $router->addAgentRoute(HttpConstants::METHOD_GET, '/agent', 'fixture_agent');
        self::assertSame('fixture_agent', $router->agentTypeAt(HttpConstants::METHOD_GET, '/agent'));
        $router->addRoute(HttpConstants::METHOD_GET, '/agent', static fn (): array => ['ok' => true]);
        self::assertNull($router->agentTypeAt(HttpConstants::METHOD_GET, '/agent'));

        $response = $router->route(self::request('/broken'));
        self::assertIsArray($response);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $response[HttpConstants::RESPONSE_KEY_STATUS]);
        self::assertSame(1, $router->traffic()->tally(HttpConstants::METHOD_GET, '/broken', time())->serverErrors);
    }

    /**
     * @param string $path Raw HTTP request path
     * @return array<string, mixed> Request shape for the router
     */
    private static function request(string $path): array
    {
        return [
            HttpConstants::REQUEST_KEY_METHOD => HttpConstants::METHOD_GET,
            HttpConstants::REQUEST_KEY_PATH => $path,
            HttpConstants::REQUEST_KEY_HEADERS => [],
        ];
    }
}
