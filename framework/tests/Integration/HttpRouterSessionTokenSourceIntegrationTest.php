<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosHttpHeaders;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Integration coverage for where the HTTP router accepts a session token from (HIL-580).
 *
 * Two carriers are legitimate and the url is not one of them, which is the whole point of
 * this test: a query parameter that used to be honoured must now be ignored, and no test
 * that only looks at the accepted carriers would notice if it came back.
 *
 * The token the router resolves is not returned anywhere - its only observable effect is the
 * browser session the api_request row ends up pointing at - so the router is driven here with
 * a live collector behind it and read back from the table.
 */
final class HttpRouterSessionTokenSourceIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    private const string OTHER_TOKEN = 'fedcba9876543210fedcba9876543210';

    private const string PATH = '/hil-580';

    /**
     * @throws DatabaseException When the stub schema cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$ac = new AnalyticsCollector();
    }

    /**
     * @throws DatabaseException When the stub schema cannot be dropped
     */
    protected function tearDown(): void
    {
        Hilos::$ac = null;

        parent::tearDown();
    }

    /**
     * @throws DatabaseException When reading back the recorded request fails
     * @throws EnvException When the router cannot read the session cookie name
     */
    public function testTheHeaderNamesTheSessionForANonBrowserClient(): void
    {
        $this->route([HilosHttpHeaders::HILOS_SESSION_TOKEN => self::TOKEN]);

        $this->assertSame(self::TOKEN, $this->tokenOfLastRequest());
    }

    /**
     * A browser cannot set a header on its own requests, so the cookie the daemon issued on
     * the handshake is what it has. Before this leaf the router never looked at it, and every
     * request a browser made was recorded as belonging to nobody.
     *
     * @throws DatabaseException When reading back the recorded request fails
     * @throws EnvException When the router cannot read the session cookie name
     */
    public function testTheCookieNamesTheSessionForABrowser(): void
    {
        $this->route([HttpConstants::HEADER_COOKIE => $this->sessionCookie(self::TOKEN)]);

        $this->assertSame(self::TOKEN, $this->tokenOfLastRequest());
    }

    /**
     * @throws DatabaseException When reading back the recorded request fails
     * @throws EnvException When the router cannot read the session cookie name
     */
    public function testTheHeaderWinsOverTheCookie(): void
    {
        $this->route([
            HilosHttpHeaders::HILOS_SESSION_TOKEN => self::TOKEN,
            HttpConstants::HEADER_COOKIE => $this->sessionCookie(self::OTHER_TOKEN),
        ]);

        $this->assertSame(self::TOKEN, $this->tokenOfLastRequest());
    }

    /**
     * The url is no longer a carrier. A secret placed there settles in proxy and server logs,
     * stays in browser history and leaves in the Referer of the next request.
     *
     * @throws DatabaseException When reading back the recorded request fails
     * @throws EnvException When the router cannot read the session cookie name
     */
    public function testATokenInTheUrlIsNotAccepted(): void
    {
        $this->route([], [HilosHttpHeaders::HILOS_SESSION_TOKEN => self::TOKEN]);

        $this->assertNull($this->tokenOfLastRequest());
        $this->assertSame([], $this->recordedTokens());
    }

    /**
     * A value that could not have been minted here is not a token, and must open no session:
     * accepting one would let any caller fill the table with names of their own choosing.
     *
     * @throws DatabaseException When reading back the recorded request fails
     * @throws EnvException When the router cannot read the session cookie name
     */
    public function testAValueThatWasNeverMintedIsNotAToken(): void
    {
        $this->route([HilosHttpHeaders::HILOS_SESSION_TOKEN => 'not-a-token']);

        $this->assertNull($this->tokenOfLastRequest());
        $this->assertSame([], $this->recordedTokens());
    }

    /**
     * @throws DatabaseException When reading back the recorded request fails
     * @throws EnvException When the router cannot read the session cookie name
     */
    public function testARequestCarryingNeitherStaysAnonymous(): void
    {
        $this->route([]);

        $this->assertNull($this->tokenOfLastRequest());
        $this->assertSame([], $this->recordedTokens());
    }

    /**
     * Routes one GET through a router carrying a single trivial route.
     *
     * @param array<string, string> $headers Request headers
     * @param array<string, string> $queryParams Query params of the request url
     * @throws EnvException When the router cannot read the session cookie name
     */
    private function route(array $headers, array $queryParams = []): void
    {
        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_GET, self::PATH, static fn(): array => ['ok' => true]);

        $router->route([
            HttpConstants::REQUEST_KEY_METHOD => HttpConstants::METHOD_GET,
            HttpConstants::REQUEST_KEY_PATH => self::PATH,
            HttpConstants::REQUEST_KEY_HEADERS => $headers,
            HttpConstants::REQUEST_KEY_QUERY_PARAMS => $queryParams,
        ]);
    }

    /**
     * @param string $token Token to place in the cookie the daemon issues
     * @return string Cookie header value carrying that token among others
     * @throws EnvException When the cookie name cannot be read
     */
    private function sessionCookie(string $token): string
    {
        $name = Hilos::$env->string(EnvConstants::HILOS_SESSION_COOKIE_NAME);

        return "theme=dark; {$name}={$token}";
    }

    /**
     * @return ?string Token of the session the recorded request belongs to, or null when anonymous
     * @throws DatabaseException When the query fails
     */
    private function tokenOfLastRequest(): ?string
    {
        Database::sql(
            'SELECT `bs`.`session_token` AS `session_token`
             FROM `hilos_analytics_api_request` AS `ar`
             LEFT JOIN `hilos_analytics_browser_session` AS `bs` ON `bs`.`id` = `ar`.`browser_session_id`
             WHERE `ar`.`path` = ?
             ORDER BY `ar`.`id` DESC
             LIMIT 1',
            [self::PATH],
        );
        $row = Database::row();
        $this->assertNotNull($row, 'the request was not recorded at all');

        return isset($row['session_token']) ? (string)$row['session_token'] : null;
    }

    /**
     * @return list<string> Tokens of every browser session the run opened
     * @throws DatabaseException When the query fails
     */
    private function recordedTokens(): array
    {
        Database::sql('SELECT `session_token` FROM `hilos_analytics_browser_session` ORDER BY `id`');

        $tokens = [];
        while (($row = Database::row()) !== null) {
            $tokens[] = (string)$row['session_token'];
        }

        return $tokens;
    }
}
