<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;

final class BotAgentStatusesActionsTest extends IntegrationTestCase
{
    public function testEnsureAllowsStateSpecificBotTruthSource(): void
    {
        $agentId = 'bot:901';
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, ['901'], $agentId);
        RtTruthSourceRegistry::setCurrentAgentId($agentId);

        try {
            $status = Hilos::$rt->botAgentStatuses->actions->ensure(901);

            $this->assertSame(StateBotAgentStatus::STATUS_LEFT, $status->status);

            $status->actions->markJoined();

            $this->assertSame(
                StateBotAgentStatus::STATUS_JOINED,
                Hilos::$rt->botAgentStatuses[901]?->status,
            );
        } finally {
            RtTruthSourceRegistry::unregisterAgent($agentId);
            RtTruthSourceRegistry::setCurrentAgentId(null);
        }
    }
}
