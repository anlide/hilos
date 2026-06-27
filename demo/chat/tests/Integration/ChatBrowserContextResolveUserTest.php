<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use ReflectionMethod;

/**
 * Integration tests for the chat ACCESS-guard identity hook.
 *
 * ChatBrowserContext::resolveCurrentUserId resolves the durable user id behind an
 * accept key from the runtime connection registry (where the handshake records
 * the acceptKey -> user mapping), and yields null for an unregistered (guest)
 * accept key — the input the ACCESS browser guard turns into a 403.
 */
final class ChatBrowserContextResolveUserTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testResolvesUserIdFromRegisteredConnection(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('ak-1', $user->id);

            $this->assertSame($user->id, $this->resolveCurrentUserId('ak-1'));
            $this->assertNull($this->resolveCurrentUserId('unregistered-ak'));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Invokes the protected ACCESS-guard identity hook on a fresh chat browser context.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ?int Resolved durable user id, or null when unregistered
     */
    private function resolveCurrentUserId(string $acceptKey): ?int
    {
        return (new ReflectionMethod(ChatBrowserContext::class, 'resolveCurrentUserId'))
            ->invoke(new ChatBrowserContext(), $acceptKey);
    }
}
