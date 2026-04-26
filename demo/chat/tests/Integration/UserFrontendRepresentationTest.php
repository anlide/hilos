<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Tables\HilosUser\HilosUserTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for user frontend representation.
 */
final class UserFrontendRepresentationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testFrontendPayloadIncludesComputedOnlineSessionCount(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        $user = Hilos::$db->users->actions->register(bin2hex(random_bytes(16)));

        try {
            $offlinePayload = $user->toArray(toFrontend: true);
            $this->assertArrayNotHasKey(ObjectUser::sessionToken, $offlinePayload);
            $this->assertSame(0, $offlinePayload['onlineSessionCount']);
            $this->assertSame('offline', $offlinePayload['presence']);

            Hilos::$rt->connections->actions->register('test-accept-key-1', $user->id);
            Hilos::$rt->connections->actions->register('test-accept-key-2', $user->id);

            $onlinePayload = $user->toArray(toFrontend: true);
            $this->assertSame(2, $onlinePayload['onlineSessionCount']);
            $this->assertSame('online', $onlinePayload['presence']);

            $row = HilosUserTableRow::fromArray($onlinePayload)->toArray();
            $this->assertSame(2, $row[HilosUserTableRow::onlineSessionCount]);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }
}
