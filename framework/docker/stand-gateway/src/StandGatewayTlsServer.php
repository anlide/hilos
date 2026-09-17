<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Closure;
use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Server\AbstractTlsServer;
use Socket;

/**
 * StandGatewayTlsServer - the stand's one fake operator, assembled from its channels and served over TLS.
 *
 * One container answers for every non-mail channel rather than one per channel
 * (HIL-653): a channel is a route prefix and a mail domain here, so adding the next
 * one is a class beside {@see TelegramRoutes} and {@see SmsRoutes} plus an endpoint in
 * the stack's compose - not a new service, a new port and a new way to read it.
 *
 * Only the housekeeping routes stay unprefixed, because they are about the gateway
 * rather than about any channel: a reset that wipes the whole store, and a health
 * probe the compose healthcheck waits on.
 *
 * The gateway speaks real TLS and does it through the framework's own server (HIL-921):
 * the daemon verifies the peer on the stand exactly as it does in production, and the
 * encrypted read that behaves differently from a bare one (HIL-732) is the read the
 * daemon's client meets here. HTTP is parsed by the framework's {@see HttpClient} and
 * routed by its {@see HttpRouter}; what stays the gateway's own is turning a raw body
 * into fields, because the core hands a route the body undecoded.
 *
 * What was here before and is not any more: a route that listed delivered messages.
 * Everything the gateway catches is forwarded to the stand's Mailpit, so what arrived
 * is read where mail is read - by the runner and by a person, out of the same inbox.
 *
 * @extends AbstractTlsServer<HttpClient>
 */
final class StandGatewayTlsServer extends AbstractTlsServer
{
    /** @var HttpRouter Router every connection of this gateway routes through */
    private HttpRouter $router;

    /**
     * Assembles the channels' routes and the gateway's own two.
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $certificateFile PEM file holding the certificate and its private key
     * @throws EnvException When the router cannot read the session cookie name
     */
    public function __construct(string $host, int $port, string $certificateFile)
    {
        parent::__construct($host, $port, $certificateFile);

        $this->router = new HttpRouter();

        new TelegramRoutes()->register($this->router);
        new SmsRoutes()->register($this->router);

        // Gateway-wide, and therefore unprefixed: neither belongs to a channel.
        $this->router->addRoute(HttpConstants::METHOD_GET, '/test/health', self::handler($this->testHealth(...)));
        $this->router->addRoute(HttpConstants::METHOD_POST, '/test/reset', self::handler($this->testReset(...)));
    }

    /**
     * Wraps a gateway handler into the shape the router calls.
     *
     * The framework client posts a form; the test routes are called from Playwright,
     * which posts JSON. Accepting both keeps one handler shape for every route, and the
     * query string is merged in beneath the body.
     *
     * The handler gets the request headers as its second argument; one that does not
     * read them may leave the parameter out. What it returns reaches the router as is:
     * a payload is answered as JSON with 200, and a response built by {@see json()}
     * keeps its own status.
     *
     * @param callable(array<string, mixed>, array<string, string>): array<string, mixed> $handler Gateway handler
     * @return Closure(array{request: array<string, mixed>, params: array<string, string>}): array<string, mixed> Route handler
     */
    public static function handler(callable $handler): Closure
    {
        return static function (array $args) use ($handler): array {
            $request = $args['request'];
            $raw = $request[HttpConstants::REQUEST_KEY_BODY];
            $fields = [];

            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $fields = $decoded;
                } else {
                    parse_str($raw, $fields);
                }
            }

            parse_str($request[HttpConstants::REQUEST_KEY_QUERY], $query);

            return $handler($fields + $query, $request[HttpConstants::REQUEST_KEY_HEADERS]);
        };
    }

    /**
     * Builds a JSON answer with a status other than 200.
     *
     * @param array<string, mixed> $payload Response payload
     * @param int $status HTTP status
     * @return array{status: int, headers: array<string, string>, body: string} Response the router sends as is
     */
    public static function json(array $payload, int $status): array
    {
        return [
            HttpConstants::RESPONSE_KEY_STATUS => $status,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => (string)json_encode($payload),
        ];
    }

    /**
     * Name the framework's connection-failure log line carries.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return 'Stand Gateway';
    }

    /**
     * Gives the accepted connection an HTTP client that speaks TLS and routes through this gateway.
     *
     * The router is set here because nothing else would set it: in the daemon the manager
     * hands its router to every HTTP client it accepts, and this process has no manager.
     *
     * @param Socket $socket Accepted client socket
     * @return HttpClient Client instance
     * @throws EnvException When the client cannot read its buffer or keep-alive env values
     */
    protected function onCreateClient($socket): HttpClient
    {
        $client = new HttpClient($socket, $this->createTransport($socket));
        $client->setRouter($this->router);

        return $client;
    }

    /**
     * Start hook; the routes are assembled in the constructor and nothing is left to do.
     */
    protected function onStart(): void
    {
    }

    /**
     * Test route: whether the gateway is up and serving.
     *
     * The healthcheck the stack waits on before it starts the daemon. It exists as its
     * own route because the probe must not depend on any channel's state - and because
     * the message list it used to probe is gone.
     *
     * @param array<string, mixed> $fields Request fields (unused)
     * @return array<string, mixed> Acknowledgement
     */
    private function testHealth(array $fields): array
    {
        return ['ok' => true];
    }

    /**
     * Test route: forget every declared number.
     *
     * @param array<string, mixed> $fields Request fields (unused)
     * @return array<string, mixed> Acknowledgement
     */
    private function testReset(array $fields): array
    {
        Store::reset();

        return ['ok' => true];
    }
}
