<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\TruthSource\RtTruthSourceRegistry;

final class BotAgentStatusesActionsTest extends IntegrationTestCase
{
    public function testEnsureAllowsStateSpecificBotTruthSource(): void
    {
        $agent = new BotAgent('901');
        $agentId = $agent->getId();
        $consumer = SourceConsumer::agent($agentId);
        SourceInterestRegistry::register(SourceChange::KIND_RT, ChatRtContext::botAgentStatuses, $consumer);
        SourceInterestRegistry::register(SourceChange::KIND_DB, ChatDbContext::bots, $consumer);
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, ChatDbContext::bots);
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, TruthSourceKeys::listed('901'), $agentId);
        SourceInterestRegistry::readsWhatIsDelivered();
        ExecutionContext::setCurrentAgentId($agentId);

        try {
            $agent->onStart();
            self::assertSame(StateBotAgentStatus::STATUS_JOINED, Hilos::$rt->botAgentStatuses[901]?->status);
            $agent->onStop();
            self::assertSame(StateBotAgentStatus::STATUS_LEFT, Hilos::$rt->botAgentStatuses[901]?->status);
            self::assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::botAgentStatuses));

            $this->expectException(RtTruthSourceWriteNotAllowedException::class);
            Hilos::$rt->botAgentStatuses->actions->ensure(902);
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            SourceInterestRegistry::releaseConsumer($consumer);
            RtTruthSourceRegistry::unregisterAgent($agentId);
            ExecutionContext::setCurrentAgentId(null);
        }
    }
}
