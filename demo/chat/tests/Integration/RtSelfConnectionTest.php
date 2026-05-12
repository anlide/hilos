<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for connection-local runtime access.
 */
final class RtSelfConnectionTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testSelfConnectionUsesCurrentExecutionAcceptKey(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('self-ak', $user->id);
            Hilos::$rt->connections->actions->register('other-ak', $user->id);

            $this->assertNull(Hilos::$rt->selfConnection);

            ExecutionContext::run(
                new ExecutionFrame(acceptKey: 'self-ak'),
                function (): void {
                    $this->assertSame('self-ak', Hilos::$rt->selfConnection?->acceptKey);
                },
            );

            $this->assertNull(Hilos::$rt->selfConnection);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }
}
