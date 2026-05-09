<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatContextAnalyzerConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatTopicConstants;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\DTO\ChatContextUpdateData;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMException;
use Hilos\Utils\Helpers\JsonHelper;

/**
 * Monopolistic agent that derives shared chat topic and summary runtime context from chat events.
 *
 * It owns the chat context runtime collection and uses async LLM summarization when message events arrive.
 */
final class ChatContextAnalyzerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CHAT_CONTEXT_ANALYZER;

    private AsyncChatLLMInterface $chatClient;

    private bool $pendingSummarize = false;

    /**
     * Creates an analyzer with an LLM client from context-analyzer settings.
     */
    public function __construct()
    {
        $this->chatClient = Hilos::$env[EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER] === LLMConstants::PROVIDER_EXTERNAL
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: Hilos::$env->normalizedLlmUrl(
                    EnvConstants::CHAT_CONTEXT_ANALYZER_URL,
                    EnvConstants::LLM_LOCAL_URL,
                ),
                model: Hilos::$env[EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL],
            );
    }

    /**
     * Registers the chat context runtime truth source and initializes the main context when missing.
     */
    public function onStart(): void
    {
        $this->registerRtTruthSource(RtChatContext::chatContext);
    }

    /**
     * Keeps shared chat context intact; the framework unregisters truth sources after this hook.
     */
    public function onStop(): void
    {
    }

    /**
     * Advances async summarization and applies parsed analyzer results to chat context runtime state.
     *
     * @throws HilosException When runtime context update or event lookup fails
     */
    public function onTick(): void
    {
        try {
            $this->chatClient->tick(microtime(true) * 1000);
        } catch (LLMException $e) {
            $this->logAgentError($e->getMessage());
            $this->chatClient->reset();
            if ($this->pendingSummarize && !$this->chatClient->isBusy()) {
                $this->startSummarize();
            }
            return;
        }

        if (!$this->chatClient->hasResult()) {
            if ($this->pendingSummarize && !$this->chatClient->isBusy()) {
                $this->startSummarize();
            }
            return;
        }

        try {
            $text = $this->chatClient->consumeResult();
        } catch (LLMException $e) {
            $this->logAgentError($e->getMessage());
            if ($this->pendingSummarize && !$this->chatClient->isBusy()) {
                $this->startSummarize();
            }
            return;
        }

        $parsed = $this->parseAnalyzerOutput($text);
        if ($parsed !== null) {
            Hilos::$rt->chatContext->actions->update($parsed);
        }

        if ($this->pendingSummarize && !$this->chatClient->isBusy()) {
            $this->startSummarize();
        }
    }

    /**
     * Routes created chat event rows into analyzer scheduling.
     *
     * @param DbSyncCreatedSignalData $data Created row sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When a chat-clear reset cannot update runtime context
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        $this->handleDbSyncChange($data->collectionKey, $data->row);
    }

    /**
     * Routes updated chat event rows into analyzer scheduling.
     *
     * @param DbSyncUpdatedSignalData $data Updated row sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When a chat-clear reset cannot update runtime context
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        $this->handleDbSyncChange($data->collectionKey, $data->row);
    }

    /**
     * Resets context on chat clear or queues summarization after message events.
     *
     * @param string $collectionKey Sync collection key
     * @param array<string, mixed> $row Synced row data
     * @throws HilosException When a chat-clear reset cannot update runtime context
     */
    private function handleDbSyncChange(string $collectionKey, array $row): void
    {
        if ($collectionKey === DbChatContext::events) {
            $eventType = $row[ObjectEvent::type] ?? '';
            if ($eventType === ChatEventType::CHAT_CLEARED->value) {
                $this->pendingSummarize = false;
                $this->chatClient->reset();
                Hilos::$rt->chatContext->actions->update(new ChatContextUpdateData(null, 0.0, ''));
            } elseif ($eventType === ChatEventType::MESSAGE_SENT->value) {
                $this->pendingSummarize = true;
            }
        }
    }

    /**
     * Starts one async LLM summarization request from recent chat event context.
     *
     * @throws HilosException When recent event lookup fails
     */
    private function startSummarize(): void
    {
        if ($this->chatClient->isBusy()) {
            return;
        }

        $this->pendingSummarize = false;

        $eventsText = $this->buildRecentEventsContext();
        if ($eventsText === '') {
            return;
        }

        $topicsList = ChatTopicConstants::getTopicsForPrompt();
        $topicKey = ChatContextUpdateData::topic;
        $topicConfidenceKey = ChatContextUpdateData::topicConfidence;
        $summaryKey = ChatContextUpdateData::summary;
        $systemPrompt = <<<PROMPT
You analyze a chat transcript and extract structured insights.

CRITICAL: Your response must be ONLY a single valid JSON object. No markdown, no code blocks, no explanation, no surrounding text. Just the raw JSON.

Required JSON schema:
{"{$topicKey}": "exactly one from allowed list below OR null if unclear", "{$topicConfidenceKey}": 0.0-1.0, "{$summaryKey}": "detailed 3-5 sentence summary"}

Allowed topics (copy exactly, character-for-character): {$topicsList}

Rules:
- {$topicKey}: exactly one value from the allowed list above, or null if no match. No variations, no new topics.
- {$topicConfidenceKey}: 0.0 to 1.0; use 0 when {$topicKey} is null or unclear
- {$summaryKey}: comprehensive but concise, capture context, decisions, sentiment

Output only the JSON object, nothing else.
PROMPT;

        $messages = [
            new Message(Message::ROLE_SYSTEM, $systemPrompt),
            new Message(Message::ROLE_USER, $eventsText),
        ];

        $timeoutSec = Hilos::$env->float(EnvConstants::CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC);
        $options = new ChatGenerateOptions(
            model: Hilos::$env[EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL],
            temperature: 0.0,
            timeoutSec: $timeoutSec > 0 ? $timeoutSec : LLMConstants::DEFAULT_TIMEOUT_SEC,
            maxTokens: ChatContextAnalyzerConstants::MAX_RESPONSE_TOKENS,
            responseFormat: Hilos::$env[EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER] === LLMConstants::PROVIDER_EXTERNAL
                ? [ChatContextAnalyzerConstants::RESPONSE_FORMAT_TYPE => ChatContextAnalyzerConstants::RESPONSE_FORMAT_JSON_OBJECT]
                : [ChatContextAnalyzerConstants::RESPONSE_FORMAT_FORMAT => ChatContextAnalyzerConstants::RESPONSE_FORMAT_JSON],
        );

        try {
            $this->chatClient->startGenerate($messages, $options);
        } catch (LLMException $e) {
            $this->logAgentError($e->getMessage());
            $this->pendingSummarize = true;
        }
    }

    /**
     * Builds recent chat events as formatted text for LLM context.
     *
     * @return string Recent message and clear-event lines for LLM context
     */
    private function buildRecentEventsContext(): string
    {
        $linesByEventId = [];
        foreach (Hilos::$db->events as $event) {
            if ($event->type === ChatEventType::MESSAGE_SENT->value) {
                $author = $event->authorUserId !== null ? "User#{$event->authorUserId}" : "Bot#{$event->authorBotId}";
                $message = $event->message !== null && $event->message !== ''
                    ? $event->message
                    : '(no text)';
                $linesByEventId[($event->id ?? 0)] = $author . ': ' . $message;
            }
            if ($event->type === ChatEventType::CHAT_CLEARED->value) {
                $linesByEventId[($event->id ?? 0)] = 'System: [chat cleared]';
            }
        }

        ksort($linesByEventId);

        return implode("\n", array_slice($linesByEventId, -ChatContextAnalyzerConstants::MAX_RECENT_EVENTS));
    }

    /**
     * Parses LLM JSON output into structured topic, confidence, and summary.
     *
     * @param string $text Raw LLM response text
     * @return ?ChatContextUpdateData Parsed context update data, or null on failure
     */
    private function parseAnalyzerOutput(string $text): ?ChatContextUpdateData
    {
        $candidate = JsonHelper::extractJsonObject($text);
        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return null;
        }

        $topicRaw = isset($decoded[ChatContextUpdateData::topic]) && $decoded[ChatContextUpdateData::topic] !== ''
            ? trim((string)$decoded[ChatContextUpdateData::topic])
            : null;
        $topic = $topicRaw !== null && $topicRaw !== '' && in_array($topicRaw, ChatTopicConstants::TOPICS, true)
            ? $topicRaw
            : null;
        $confidence = isset($decoded[ChatContextUpdateData::topicConfidence])
            ? (float)$decoded[ChatContextUpdateData::topicConfidence]
            : 0.0;
        $summary = isset($decoded[ChatContextUpdateData::summary]) ? (string)$decoded[ChatContextUpdateData::summary] : '';

        return new ChatContextUpdateData(
            topic: $topic,
            topicConfidence: max(0, min(1, $confidence)),
            summary: $summary,
        );
    }
}
