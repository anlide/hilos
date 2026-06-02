<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

final class RuntimeBridgePropertiesTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'runtime-bridge-test-agent';

    public function testBotAndAgentStatusExposeBridgeDirections(): void
    {
        $bot = Hilos::$db->bots->actions->create('Bridge Bot ' . RandomHelper::hex(4), active: true);
        $this->assertIsInt($bot->id);

        $agentId = 'bot:' . $bot->id . ':bridge-test';
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, [(string)$bot->id], $agentId);
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

    public function testAttachmentDraftExposesUserAndConnectionBridges(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('draft-bridge-ak', $user->id);
            $draft = Hilos::$rt->attachmentDrafts->actions->create(
                'draft-bridge',
                'draft-bridge-ak',
                $user->id,
                '',
                'bridge.txt',
                'text/plain',
                1,
                'bridge.txt',
                time(),
            );

            $this->assertSame($user->id, $draft->user?->id);
            $this->assertSame('draft-bridge-ak', $draft->connection?->acceptKey);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
            RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        }
    }
}
