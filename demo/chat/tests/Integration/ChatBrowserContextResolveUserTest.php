<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for the chat identity seam behind the access guards.
 *
 * ChatBrowserContext::resolveConnectionIdentity answers who is behind an accept key from
 * the runtime connection registry, where the handshake records the acceptKey -> user
 * mapping. The two answers are read by different machinery and must not be confused: a
 * registered connection is a settled user the guards judge, while an accept key with no row
 * is not a guest but an answer still crossing the RT sync — the input the frame queue waits
 * on instead of refusing 401 (HIL-599).
 */
final class ChatBrowserContextResolveUserTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testResolvesUserIdFromRegisteredConnection(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('ak-1', $user->id);

            $identity = $this->connectionIdentity('ak-1');
            $this->assertFalse($identity->pending);
            $this->assertSame($user->id, $identity->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    public function testAnUnregisteredAcceptKeyIsPendingRatherThanAGuest(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $identity = $this->connectionIdentity('unregistered-ak');

            // The handshake writes a row for every connection it sees, so an absent row
            // means the write has not arrived here - not that nobody is behind the socket.
            $this->assertTrue($identity->pending);
            $this->assertNull($identity->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Asks a fresh chat browser context who is behind one accept key.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ConnectionIdentity Identity the chat registry answers for it
     */
    private function connectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return new ChatBrowserContext()->connectionIdentity($acceptKey);
    }
}
