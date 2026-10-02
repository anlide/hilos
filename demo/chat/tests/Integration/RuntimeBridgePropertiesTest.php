<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

final class RuntimeBridgePropertiesTest extends IntegrationTestCase
{
    public function testBotAndAgentStatusExposeBridgeDirections(): void
    {
        $bot = Hilos::$db->bots->actions->create('Bridge Bot ' . RandomHelper::hex(4), active: true);
        $this->assertIsInt($bot->id);

        $agentId = 'bot:' . $bot->id . ':bridge-test';
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, TruthSourceKeys::listed((string)$bot->id), $agentId);
        ExecutionContext::setCurrentAgentId($agentId);

        try {
            $status = Hilos::$rt->botAgentStatuses->actions->ensure($bot->id);

            $this->assertSame($bot->id, $status->bot?->id);
            $this->assertSame($status->botId, $bot->agentStatus?->botId);
            $this->assertSame(StateBotAgentStatus::STATUS_LEFT, $bot->agentStatus?->status);
        } finally {
            RtTruthSourceRegistry::unregisterAgent($agentId);
            ExecutionContext::setCurrentAgentId(null);
        }
    }
}
