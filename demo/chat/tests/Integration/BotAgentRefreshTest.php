<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMResultUnavailableException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/**
 * Request boundaries on one running bot agent, and the test-only answer-now that brings its next request to now.
 */
final class BotAgentRefreshTest extends IntegrationTestCase
{
    public function testChangedModelFailsThenRecoversOnLaterRequest(): void
    {
        $setting = EntitySetting::get([EntitySetting::key => ChatSettingsConstants::DEFAULT_BOT_MODEL])->first();
        $this->assertNotNull($setting);
        $original = $setting->value;
        $bot = Hilos::$db->bots->actions->create('Refresh Bot');
        $this->assertNotNull($bot->id);
        $bot->getObject()->reactionDelayMin = 0;
        $bot->getObject()->reactionDelayMax = 0;
        $bot->getObject()->reactionChance = 100;
        $bot->getObject()->cooldownAfterMessage = 0;
        $bot->getObject()->sync();

        $agentId = 'bot:' . $bot->id . ':refresh-test';
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, TruthSourceKeys::listed((string)$bot->id), $agentId);
        ExecutionContext::setCurrentAgentId($agentId);

        try {
            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new BotAgent((string)$bot->id);
            $firstClient = new CompletedBotChatClient();
            (new ReflectionProperty($agent, 'chatClient'))->setValue($agent, $firstClient);
            $agent->onStart();
            $agent->onTick();
            $this->assertSame($original, $firstClient->lastOptions?->model);

            $setting->value = '';
            $this->saveAndApplySetting($setting);
            $agent->onTick();
            $this->assertSame($original, (new ReflectionProperty($agent, 'profile'))->getValue($agent)->model);
            $this->assertSame(1, $firstClient->startGenerateCalls);

            $this->scheduleAgain($agent);
            $agent->onTick();
            $this->assertSame(1, $firstClient->startGenerateCalls);
            $this->assertSame('', (new ReflectionProperty($agent, 'profile'))->getValue($agent)->model);

            $setting->value = 'recovered-model';
            $this->saveAndApplySetting($setting);
            $this->scheduleAgain($agent);
            $agent->onTick();
            $this->assertSame('recovered-model', (new ReflectionProperty($agent, 'profile'))->getValue($agent)->model);
            $this->assertNotSame($firstClient, (new ReflectionProperty($agent, 'chatClient'))->getValue($agent));
        } finally {
            $setting->value = $original;
            $this->saveAndApplySetting($setting);
            if (isset($agent)) {
                $agent->onStop();
            }
            RtTruthSourceRegistry::unregisterAgent($agentId);
            ExecutionContext::setCurrentAgentId(null);
            $bot->actions->delete();
        }
    }

    public function testAnswerNowCommandSignalsAnActiveBotAndRefusesAStoppedOrUnknownOne(): void
    {
        $bot = Hilos::$db->bots->actions->create('Answering Bot');
        $this->assertNotNull($bot->id);
        $botId = $bot->id;

        try {
            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->drainSignals();
            $this->assertSame([
                ['signal', ChatSignalConstants::BOT_ANSWER_NOW, $botId],
                ['reply', true, [ChatCommandConstants::botId => $botId]],
            ], $this->askLibraryToAnswerNow($botId), 'Accepted at once, without waiting for the model');

            $bot->actions->update(active: false);
            $this->drainSignals();
            $this->assertSame([
                ['reply', false, [CommandConstants::FIELD_MESSAGE => "Bot {$botId} is not active"]],
            ], $this->askLibraryToAnswerNow($botId), 'A signal would start the agent of a stopped bot');

            $unknownId = $botId + 1000;
            $this->assertArrayNotHasKey($unknownId, Hilos::$db->bots);
            $this->assertSame([
                ['reply', false, [CommandConstants::FIELD_MESSAGE => "Bot {$unknownId} is not active"]],
            ], $this->askLibraryToAnswerNow($unknownId));
        } finally {
            $bot->actions->delete();
        }
    }

    public function testAnswerNowTakesAQuietBotToTheModelOnTheNextTickAndOnlyOnce(): void
    {
        $bot = Hilos::$db->bots->actions->create('Quiet Bot');
        $this->assertNotNull($bot->id);
        $bot->getObject()->reactionDelayMin = 3600;
        $bot->getObject()->reactionDelayMax = 3600;
        $bot->getObject()->reactionChance = 0;
        $bot->getObject()->cooldownAfterMessage = 0;
        $bot->getObject()->sync();

        $agentId = 'bot:' . $bot->id . ':answer-now-test';
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, TruthSourceKeys::listed((string)$bot->id), $agentId);
        ExecutionContext::setCurrentAgentId($agentId);

        try {
            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new BotAgent((string)$bot->id);
            $client = new CompletedBotChatClient();
            (new ReflectionProperty($agent, 'chatClient'))->setValue($agent, $client);
            $agent->onStart();
            $agent->onTick();
            $this->scheduleAgain($agent);
            $agent->onTick();
            $this->assertSame(0, $client->startGenerateCalls, 'No chance and an hour of delay: without the signal the bot keeps quiet');

            $this->answerNow($agent, $bot->id + 1);
            $agent->onTick();
            $this->assertSame(0, $client->startGenerateCalls, 'Another bot is asked, not this one');

            $this->answerNow($agent, $bot->id);
            $this->scheduleAgain($agent);
            $agent->onTick();
            $this->assertSame(1, $client->startGenerateCalls, 'A chat change after the signal does not push the answer back by the delay');

            $this->answerNow($agent, $bot->id);
            $agent->onTick();
            $this->assertSame(2, $client->startGenerateCalls, 'Asked while a request runs: the answer is the next request after it');

            $agent->onTick();
            $this->scheduleAgain($agent);
            $agent->onTick();
            $this->assertSame(2, $client->startGenerateCalls, 'The answer spent, the ordinary reaction is back');
        } finally {
            if (isset($agent)) {
                $agent->onStop();
            }
            RtTruthSourceRegistry::unregisterAgent($agentId);
            ExecutionContext::setCurrentAgentId(null);
            $bot->actions->delete();
        }
    }

    /** @param EntitySetting $setting Peer row whose persisted value is delivered to the bot's context */
    private function saveAndApplySetting(EntitySetting $setting): void
    {
        $setting->save();
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
            HilosDbContext::settings,
            (string) $setting->id,
            [EntitySetting::value => $setting->value],
        ), originNodeId: 'peer');
    }

    private function scheduleAgain(BotAgent $agent): void
    {
        $agent->onSignalRtSyncUpdated(
            new RtSyncUpdatedSignalData(ChatRtContext::chatContext, 'main', []),
            'rt',
            'rt_sync_updated',
        );
    }

    /**
     * @param BotAgent $agent Agent the library's signal reaches
     * @param int $botId Bot the signal names
     */
    private function answerNow(BotAgent $agent, int $botId): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(new BotAgentSignalData(botId: $botId)),
            'agent:' . LibraryAgent::AGENT_TYPE,
            ChatSignalConstants::BOT_ANSWER_NOW,
        );
    }

    /**
     * @param int $botId Bot the command names
     * @return list<array{string, mixed, mixed}> Every frame the library queued, in order: a signal with its name and bot, a reply
     *     with its outcome and payload
     */
    private function askLibraryToAnswerNow(int $botId): array
    {
        $request = new CommandRequestDTO('answer-now', ChatCommandConstants::BOT_TEST_ANSWER_NOW, ['botId' => $botId]);
        new LibraryAgent()->onSignalCommand(Hilos::$sr->createCommandPayloadDTO($request->command, $request), 'command', $request->command);
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $this->assertSame('answer-now', $signal->data->correlationId);
                $frames[] = ['reply', $signal->data->isOk(), $signal->data->payload];
                continue;
            }
            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $this->assertInstanceOf(BotAgentSignalData::class, $signal->data->data);
            $frames[] = ['signal', $signal->signalName->getName(), $signal->data->data->botId];
        }

        return $frames;
    }
}

final class CompletedBotChatClient implements AsyncChatLLMInterface
{
    public int $startGenerateCalls = 0;
    public ?ChatGenerateOptions $lastOptions = null;
    private bool $busy = false;
    private bool $hasResult = false;

    /** @param list<Message> $messages Prompt messages */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->startGenerateCalls++;
        $this->lastOptions = $options;
        $this->busy = true;
    }

    public function tick(float $currentTimeMs): void
    {
        if ($this->busy) {
            $this->busy = false;
            $this->hasResult = true;
        }
    }

    public function hasResult(): bool
    {
        return $this->hasResult;
    }

    public function consumeResult(): string
    {
        if (!$this->hasResult) {
            throw new LLMResultUnavailableException('No bot result');
        }
        $this->hasResult = false;

        return 'A bot reply';
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function reset(): void
    {
        $this->busy = false;
        $this->hasResult = false;
    }
}
