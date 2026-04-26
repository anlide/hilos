<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatContextAnalyzerConstants;
use Demo\Chat\Constants\ChatTopicConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\DTO\ChatContextUpdateData;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Utils\Env;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\JsonHelper;
use Hilos\Utils\Logger;

/**
 * ChatContextAnalyzerAgent - Monopolistic agent for chat context analysis.
 *
 * Runs in monopolistic worker process. Single instance maintains shared chat context
 * for all bots. Listens to DbSync events to build and update context. Sends LLM requests to summarize/analyze chat state.
 * Writes results to Runtime (chatContext collection) for BotAgents to consume.
 */
class ChatContextAnalyzerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CHAT_CONTEXT_ANALYZER;

    /** @var AsyncChatLLMInterface LLM chat client for context analysis */
    private AsyncChatLLMInterface $chatClient;

    /** @var bool Whether summarize request is pending */
    private bool $pendingSummarize = false;

    /**
     * Create ChatContextAnalyzerAgent with LLM client from Env config.
     */
    public function __construct()
    {
        $this->chatClient = Env::isExternalProvider(EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER)
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: Env::getNormalizedLlmUrl(
                    EnvConstants::CHAT_CONTEXT_ANALYZER_URL,
                    EnvConstants::LLM_LOCAL_URL,
                    LLMConstants::DEFAULT_LOCAL_URL,
                ),
                model: Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL, ChatLLMConstants::MODEL_CONTEXT_ANALYZER),
                apiKey: null,
            );
    }

    /**
     * Called when agent starts. Registers truth source and initializes chat context if empty.
     */
    public function onStart(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::chatContexts, true, $this->getId());

        $existing = Hilos::$rt->chatContexts->getStateCollection()->get(StateChatContext::ID_MAIN);
        if ($existing === null) {
            Hilos::$rt->chatContexts->actions->init();
        }
    }

    /**
     * Called when agent stops. Unregisters truth source.
     */
    public function onStop(): void
    {
        RtTruthSourceRegistry::unregister(RtChatContext::chatContexts, $this->getId());
    }

    /**
     * Periodic tick. Processes LLM results and handles sync signals.
     */
    public function onTick(): void
    {
        $this->chatClient->tick(microtime(true) * 1000);

        if (!$this->chatClient->hasResult()) {
            if ($this->pendingSummarize && !$this->chatClient->isBusy()) {
                $this->startSummarize();
            }
            return;
        }

        $text = $this->chatClient->getResult();
        $this->pendingSummarize = false;

        if ($text === null) {
            Logger::logAgentInfo($this->getType(), '[llm_done] Summarize request finished (no response)');
            return;
        }

        $parsed = $this->parseAnalyzerOutput($text);
        if ($parsed !== null) {
            $topic = $parsed->topic;
            $confidence = $parsed->topicConfidence;
            $summary = $parsed->summary;

            Hilos::$rt->chatContexts->actions->update($parsed);

            $topicStatus = $topic === null ? 'null' : 'valid';
            Logger::logAgentInfo(
                $this->getType(),
                '[llm_done] topic=' . json_encode($topic ?? 'null', JSON_UNESCAPED_UNICODE) . ' (' . $topicStatus . ')'
                . ', confidence=' . round($confidence, 2)
                . ', summary=' . json_encode(mb_substr($summary, 0, 300), JSON_UNESCAPED_UNICODE)
            );
        } else {
            Logger::logAgentInfo($this->getType(), '[llm_done] Parse failed, raw=' . json_encode(mb_substr($text, 0, 200), JSON_UNESCAPED_UNICODE));
        }

        if ($this->pendingSummarize && !$this->chatClient->isBusy()) {
            $this->startSummarize();
        }
    }

    /**
     * Handle DB sync created signal.
     *
     * @param DbSyncCreatedSignalData $data Sync data with created row
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        $this->handleDbSyncChange($data->collectionKey, $data->row);
    }

    /**
     * Handle DB sync updated signal.
     *
     * @param DbSyncUpdatedSignalData $data Sync data with updated row
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        $this->handleDbSyncChange($data->collectionKey, $data->row);
    }

    /**
     * Process DB sync change (events).
     *
     * @param string $collectionKey Collection key (events)
     * @param array<string, mixed> $row Row data
     */
    private function handleDbSyncChange(string $collectionKey, array $row): void
    {
        if ($collectionKey === DbChatContext::events) {
            $eventType = $row['type'] ?? '';
            if ($eventType === ChatEventType::CHAT_CLEARED->value) {
                $this->pendingSummarize = false;
                Hilos::$rt->chatContexts->actions->update(new ChatContextUpdateData(null, 0.0, ''));
            } elseif ($eventType === ChatEventType::MESSAGE_SENT->value) {
                $this->pendingSummarize = true;
            }
        }
    }

    /**
     * Starts LLM summarization request if not busy.
     *
     * Builds recent events context, sends to LLM, and updates pending flag.
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

        $eventCount = substr_count($eventsText, "\n") + (strlen($eventsText) > 0 ? 1 : 0);
        $topicsList = ChatTopicConstants::getTopicsForPrompt();
        $systemPrompt = <<<PROMPT
You analyze a chat transcript and extract structured insights.

CRITICAL: Your response must be ONLY a single valid JSON object. No markdown, no code blocks, no explanation, no surrounding text. Just the raw JSON.

Required JSON schema:
{"topic": "exactly one from allowed list below OR null if unclear", "topicConfidence": 0.0-1.0, "summary": "detailed 3-5 sentence summary"}

Allowed topics (copy exactly, character-for-character): {$topicsList}

Rules:
- topic: exactly one value from the allowed list above, or null if no match. No variations, no new topics.
- topicConfidence: 0.0 to 1.0; use 0 when topic is null or unclear
- summary: comprehensive but concise, capture context, decisions, sentiment

Output only the JSON object, nothing else.
PROMPT;

        $messages = [
            new Message(Message::ROLE_SYSTEM, $systemPrompt),
            new Message(Message::ROLE_USER, $eventsText),
        ];

        $timeoutSec = Env::getFloat(EnvConstants::CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC);
        $options = new ChatGenerateOptions(
            model: Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL, ChatLLMConstants::MODEL_CONTEXT_ANALYZER),
            temperature: 0.0,
            timeoutSec: $timeoutSec > 0 ? $timeoutSec : LLMConstants::DEFAULT_TIMEOUT_SEC,
            maxTokens: ChatContextAnalyzerConstants::MAX_RESPONSE_TOKENS,
            responseFormat: Env::isExternalProvider(EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER)
                ? ['type' => 'json_object']
                : ['format' => 'json'],
        );

        Logger::logAgentInfo(
            $this->getType(),
            '[llm_start] Sending summarize request, events=' . $eventCount . ', contextLen=' . strlen($eventsText)
        );

        if (!$this->chatClient->startGenerate($messages, $options)) {
            $this->pendingSummarize = true;
        }
    }

    /**
     * Build recent chat events as formatted string for LLM context.
     *
     * @return string Recent messages as "Author: message" lines
     */
    private function buildRecentEventsContext(): string
    {
        $events = [];
        foreach (Hilos::$db->events as $event) {
            if ($event->type === ChatEventType::MESSAGE_SENT->value) {
                $author = $event->userId !== null ? "User#{$event->userId}" : "Bot#{$event->botId}";
                $data = $event->data !== null ? json_decode($event->data, true) : null;
                $msg = is_array($data) && isset($data['message']) ? (string)$data['message'] : '(no text)';
                $events[] = ['id' => $event->id, 'author' => $author, 'msg' => $msg];
            }
            if ($event->type === ChatEventType::CHAT_CLEARED->value) {
                $events[] = ['id' => $event->id, 'author' => 'System', 'msg' => '[chat cleared]'];
            }
        }

        usort($events, static fn (array $a, array $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        $recent = array_slice($events, -ChatContextAnalyzerConstants::MAX_RECENT_EVENTS);

        $lines = [];
        foreach ($recent as $e) {
            $lines[] = $e['author'] . ': ' . $e['msg'];
        }

        return implode("\n", $lines);
    }

    /**
     * Parses LLM JSON output into structured topic, confidence and summary.
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

        $topicRaw = isset($decoded['topic']) && $decoded['topic'] !== null && $decoded['topic'] !== ''
            ? trim((string)$decoded['topic'])
            : null;
        $topic = $topicRaw !== null && $topicRaw !== '' && $this->isTopicAllowed($topicRaw) ? $topicRaw : null;
        if ($topicRaw !== null && $topicRaw !== '' && $topic === null) {
            Logger::logAgentInfo(
                $this->getType(),
                '[llm_done] topic rejected (not in allowed list): ' . json_encode($topicRaw, JSON_UNESCAPED_UNICODE)
            );
        }
        $confidence = isset($decoded['topicConfidence'])
            ? (float)$decoded['topicConfidence']
            : 0.0;
        $summary = isset($decoded['summary']) ? (string)$decoded['summary'] : '';

        return new ChatContextUpdateData(
            topic: $topic,
            topicConfidence: max(0, min(1, $confidence)),
            summary: $summary,
        );
    }

    /**
     * Checks if topic is in the allowed list.
     *
     * @param string $topic Topic to validate
     * @return bool True if allowed
     */
    private function isTopicAllowed(string $topic): bool
    {
        return in_array($topic, ChatTopicConstants::TOPICS, true);
    }
}
