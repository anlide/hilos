<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * StandGatewayServer - the stand's one fake operator, assembled from its channels.
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
 * What was here before and is not any more: a route that listed delivered messages.
 * Everything the gateway catches is forwarded to the stand's Mailpit, so what arrived
 * is read where mail is read - by the runner and by a person, out of the same inbox.
 */
final class StandGatewayServer
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();

        new TelegramRoutes()->register($this->router);
        new SmsRoutes()->register($this->router);

        // Gateway-wide, and therefore unprefixed: neither belongs to a channel.
        $this->router->add('GET', '/test/health', $this->testHealth(...));
        $this->router->add('POST', '/test/reset', $this->testReset(...));
    }

    /**
     * Serves the current request.
     */
    public function run(): void
    {
        $this->router->dispatch();
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
