<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

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
 * Only the house's own routes stay unprefixed, because they are about the gateway
 * rather than about any channel: a reset that wipes the whole store, a health probe the
 * compose healthcheck waits on, and the behavior handle. The levers a spec dictates -
 * a status, a delay, a cut, a hold - are the house's too (HIL-922): they work the same on
 * every provider route of every resident, so a spec learns one arrangement.
 *
 * A resident's routes are assembled per connection rather than once: a declared
 * behavior is played out on the connection that carries the call, and each connection
 * routes through its own {@see GatewayRoutes} bound to its own client.
 *
 * Not every resident is called by the daemon. {@see OAuthRoutes} is opened by a BROWSER, which
 * the product sends to the provider for real, and answers a page a person acts on there; that
 * person is the one half of a resident that cannot live in this house at all, and lives in the
 * spec's own set instead.
 *
 * The gateway speaks real TLS and does it through the framework's own server (HIL-921):
 * the daemon verifies the peer on the stand exactly as it does in production, and the
 * encrypted read that behaves differently from a bare one (HIL-732) is the read the
 * daemon's client meets here. HTTP is parsed by the framework's {@see HttpClient} and
 * routed by its {@see HttpRouter}; what stays the gateway's own is turning a raw body
 * into fields, because the core hands a route the body undecoded, and sending an answer
 * the way a spec dictated ({@see StandGatewayHttpClient}).
 *
 * What was here before and is not any more: a route that listed delivered messages.
 * Everything the gateway catches is forwarded to the stand's Mailpit, so what arrived
 * is read where mail is read - by the runner and by a person, out of the same inbox.
 *
 * @extends AbstractTlsServer<StandGatewayHttpClient>
 */
final class StandGatewayTlsServer extends AbstractTlsServer
{
    /** @var list<GatewayResident> Channels living in the gateway, registered on every connection */
    private array $residents;

    /**
     * Assembles the channels living in the gateway.
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $certificateFile PEM file holding the certificate and its private key
     */
    public function __construct(string $host, int $port, string $certificateFile)
    {
        parent::__construct($host, $port, $certificateFile);

        $this->residents = [new TelegramRoutes(), new SmsRoutes(), new OAuthRoutes()];
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
     * Gives the accepted connection a client that speaks TLS, with its own routes of every resident and of the gateway.
     *
     * The router is set here because nothing else would set it: in the daemon the manager
     * hands its router to every HTTP client it accepts, and this process has no manager.
     * It is a router per connection because its routes are bound to this client: a
     * behavior declared for a call is played out on the connection the call came over.
     *
     * @param Socket $socket Accepted client socket
     * @return StandGatewayHttpClient Client instance
     * @throws EnvException When the client cannot read its buffer or keep-alive env values, or the router its session cookie name
     */
    protected function onCreateClient($socket): StandGatewayHttpClient
    {
        $client = new StandGatewayHttpClient($socket, $this->createTransport($socket));
        $router = new HttpRouter();
        $routes = new GatewayRoutes($router, $client);

        foreach ($this->residents as $resident) {
            $resident->register($routes);
        }

        // Gateway-wide, and therefore unprefixed: none of them belongs to a channel.
        $routes->test(HttpConstants::METHOD_GET, '/test/health', $this->testHealth(...));
        $routes->test(HttpConstants::METHOD_POST, '/test/reset', $this->testReset(...));
        $routes->test(HttpConstants::METHOD_POST, '/test/behavior', fn(array $fields): array => $this->testBehavior($routes, $fields));

        $client->setRouter($router);

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
     * Test route: forget every declared number and every declared behavior.
     *
     * @param array<string, mixed> $fields Request fields (unused)
     * @return array<string, mixed> Acknowledgement
     */
    private function testReset(array $fields): array
    {
        Store::reset();

        return ['ok' => true];
    }

    /**
     * Test route: declare how a provider route answers its next call carrying a key.
     *
     * The declaration is checked in the order its contract lists - an unknown key, the path,
     * the key, then the levers - and a refusal is a 400 with the code, so the spec helper
     * fails where the declaration was made rather than later on a provider that answered as
     * usual. The path is checked against this connection's routes, which is where the
     * residents registered their provider halves.
     *
     * @param GatewayRoutes $routes Routes of the connection the declaration came over
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Acknowledgement, or a 400 naming the refusal
     */
    private function testBehavior(GatewayRoutes $routes, array $fields): array
    {
        try {
            Behavior::refuseUnknownField($fields);

            $path = $fields[Behavior::FIELD_PATH] ?? null;
            if (!is_string($path) || !$routes->isProvider($path)) {
                throw new InvalidBehaviorException(InvalidBehaviorException::PATH_NOT_PROVIDER);
            }

            $key = $fields[Behavior::FIELD_KEY] ?? null;
            if (!is_string($key) || $key === '') {
                throw new InvalidBehaviorException(InvalidBehaviorException::KEY_REQUIRED);
            }

            Store::pushBehavior($path, $key, Behavior::fromFields($fields));
        } catch (InvalidBehaviorException $refusal) {
            return self::json(['ok' => false, 'error' => $refusal->error], HttpConstants::HTTP_BAD_REQUEST);
        }

        return ['ok' => true];
    }
}
