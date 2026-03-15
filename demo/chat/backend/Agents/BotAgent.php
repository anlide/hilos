<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatContextAnalyzerConstants;
use Demo\Chat\Constants\ChatTopicConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\ModerationBotRequestSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\View\Item\Bot as ViewBot;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Utils\BotEnv;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Utils\Logger;

/**
 * BotAgent - Regular agent for bot management.
 *
 * Runs in regular worker process. One agent per bot (agentIndex = bot.id).
 * Manages bot interactions: reacts to chat context updates (RtSync from ChatContextAnalyzerAgent)
 * and generates messages via async LLM.
 */
class BotAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::BOT;

    /** @var int Maximum tokens for LLM response */
    private const int MAX_RESPONSE_TOKENS = 256;

    /** @var AsyncChatLLMInterface LLM chat client */
    private AsyncChatLLMInterface $chatClient;

    /** @var float Unix timestamp (seconds) when reaction is scheduled, or 0 if not scheduled */
    private float $scheduledReactAt = 0.0;

    /** @var float Unix timestamp (seconds) when last message was sent, for cooldown */
    private float $lastMessageSentAt = 0.0;

    /** @var bool Whether LLM generation is in flight (result will be sent to ModeratorAgent) */
    private bool $generationInFlight = false;

    /**
     * Create BotAgent for the given bot ID.
     *
     * @param string $agentIndex Bot ID (must be non-empty)
     * @throws AgentIndexRequiredException If agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('BotAgent requires non-empty agentIndex (bot id)');
        }
        $this->agentIndex = $agentIndex;

        $this->chatClient = BotEnv::useExternalProvider()
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: BotEnv::getUrl(),
                model: BotEnv::getModel(),
                apiKey: null,
            );
    }

    /**
     * Called when agent is started. Announces bot join and schedules initial reaction.
     */
    public function onStart(): void
    {
        Logger::logAgentStart($this->getId(), $this->getType());
        $botId = (int) $this->agentIndex;
        $this->sendToAgent(ChatSignalConstants::BOT_JOINED, new BotAgentSignalData(botId: $botId));

        // Bots without topic restriction (leaders) should start conversation when chat is empty.
        // RtSync from init() may arrive before BotAgents exist, so schedule on join as fallback.
        $this->scheduleReaction();
    }

    /**
     * Called when agent is stopped. Announces bot left.
     */
    public function onStop(): void
    {
        $botId = (int) $this->agentIndex;
        $this->sendToAgent(ChatSignalConstants::BOT_LEFT, new BotAgentSignalData(botId: $botId));
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Handle DB sync updated signal for the bot.
     *
     * If the bot is marked as inactive, stop the agent.
     *
     * @param DbSyncUpdatedSignalData $data Sync data with updated row
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        $botId = (int) $this->agentIndex;
        $signalBotId = (int) $data->idString;
        if ($signalBotId !== $botId) {
            return;
        }
        $active = $data->row[ObjectBot::active] ?? null;
        if ($active === false) {
            $this->selfStop();
        }
    }

    /**
     * Handle DB sync deleted signal for the bot.
     *
     * Stops agent when its bot record is deleted.
     *
     * @param DbSyncDeletedSignalData $data Sync data with deleted row id
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncDeleted(DbSyncDeletedSignalData $data, string $source, string $name): void
    {
        $botId = (int) $this->agentIndex;
        $signalBotId = (int) $data->idString;
        if ($signalBotId !== $botId) {
            return;
        }
        $this->selfStop();
    }

    /**
     * Handle DB sync created signal.
     *
     * When chat is cleared, topic is reset - leaders should propose a new topic.
     *
     * @param DbSyncCreatedSignalData $data Sync data with created row
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey !== DbChatContext::events) {
            return;
        }
        $eventType = $data->row['type'] ?? '';
        if ($eventType === ChatEventType::CHAT_CLEARED->value) {
            Logger::logAgentInfo($this->getId(), '[chat_cleared] Scheduling topic proposal');
            $this->scheduleReaction();
        }
    }

    /**
     * Handle RT sync created signal.
     *
     * ChatContext created (init) - can schedule reaction if context has content.
     *
     * @param RtSyncCreatedSignalData $data Sync data with created state
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === RtChatContext::chatContexts) {
            $this->scheduleReaction();
        }
    }

    /**
     * Handle RT sync updated signal.
     *
     * ChatContext updated (new messages summarized) - schedule reaction.
     *
     * @param RtSyncUpdatedSignalData $data Sync data with updated state
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === RtChatContext::chatContexts) {
            $this->scheduleReaction();
        }
    }

    /**
     * Agent-specific tick implementation.
     * Processes async LLM results and triggers scheduled reactions.
     */
    public function onTick(): void
    {
        $nowMs = microtime(true) * 1000;
        $nowSec = microtime(true);

        $this->chatClient->tick($nowMs);

        if ($this->chatClient->hasResult()) {
            $text = $this->chatClient->getResult();
            $this->generationInFlight = false;

            $botId = (int) $this->agentIndex;
            $message = $text !== null && trim($text) !== '' ? trim($text) : null;

            if ($message !== null) {
                $this->lastMessageSentAt = $nowSec;
                $this->sendToAgent(
                    ChatSignalConstants::MODERATE_BOT_REQUEST,
                    new ModerationBotRequestSignalData(botId: $botId, message: $message),
                );
                $len = mb_strlen($message);
                Logger::logAgentInfo($this->getId(), "[publish] Queued for moderation, {$len} chars");
            } else {
                Logger::logAgentInfo($this->getId(), "[publish] Skipped: LLM returned empty");
            }
        }

        if ($this->scheduledReactAt > 0 && $nowSec >= $this->scheduledReactAt && !$this->chatClient->isBusy()) {
            $this->scheduledReactAt = 0.0;
            if ($this->shouldReact()) {
                $this->startGenerate();
            }
        }
    }

    /**
     * Schedule next reaction based on bot delay settings.
     */
    private function scheduleReaction(): void
    {
        if ($this->chatClient->isBusy() || $this->generationInFlight) {
            return;
        }

        $bot = $this->getBot();
        if ($bot === null) {
            return;
        }

        $delayMin = max(0, (int) ($bot->reactionDelayMin ?? 0));
        $delayMax = max($delayMin, (int) ($bot->reactionDelayMax ?? 1));
        $delaySec = $delayMin === $delayMax ? $delayMin : random_int($delayMin, $delayMax);
        $this->scheduledReactAt = microtime(true) + (float) $delaySec;

        Logger::logAgentInfo($this->getId(), "[schedule] Reaction in {$delaySec}s");
    }

    /**
     * Check whether bot should react (chance, cooldown, topic match).
     *
     * @return bool True if bot should generate a message
     */
    private function shouldReact(): bool
    {
        $bot = $this->getBot();
        if ($bot === null) {
            return false;
        }

        $reactionChance = (int) ($bot->reactionChance ?? 100);
        if ($reactionChance <= 0) {
            return false;
        }
        if ($reactionChance < 100 && random_int(1, 100) > $reactionChance) {
            return false;
        }

        $cooldownSec = (int) ($bot->cooldownAfterMessage ?? 0);
        if ($cooldownSec > 0 && $this->lastMessageSentAt > 0) {
            $elapsed = microtime(true) - $this->lastMessageSentAt;
            if ($elapsed < $cooldownSec) {
                return false;
            }
        }

        if ((bool) ($bot->topicMatchRequired ?? false)) {
            if (!$this->topicMatches($bot)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if chat topic matches bot preferred topics.
     *
     * @param ViewBot $bot Bot view with topics configuration
     * @return bool True if topic matches or bot has no topic restriction
     */
    private function topicMatches(ViewBot $bot): bool
    {
        $botTopics = $this->parseTopics((string) ($bot->topics ?? ''));
        if ($botTopics === []) {
            return true;
        }

        $ctx = Hilos::$rt->chatContexts->getStateCollection()->get(StateChatContext::ID_MAIN);
        $contextTopic = $ctx?->topic;
        if ($contextTopic === null || $contextTopic === '') {
            return false;
        }

        $contextLower = mb_strtolower($contextTopic);
        foreach ($botTopics as $topic) {
            if ($topic !== '' && str_contains($contextLower, mb_strtolower($topic))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse topics from JSON array or comma/semicolon-separated string.
     *
     * @param string $topics Topics as JSON or comma/semicolon-separated string
     * @return list<string> List of topic strings
     */
    private function parseTopics(string $topics): array
    {
        $trimmed = trim($topics);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $result = [];
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $result[] = trim($item);
                }
            }

            return $result;
        }

        $parts = preg_split('/[\s,;]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    /**
     * Start async LLM generation for bot message.
     */
    private function startGenerate(): void
    {
        $bot = $this->getBot();
        if ($bot === null) {
            return;
        }

        $messages = $this->buildGenerationMessages($bot);
        if ($messages === []) {
            return;
        }

        $options = new ChatGenerateOptions(
            model: BotEnv::getModel(),
            temperature: 0.7,
            timeoutSec: BotEnv::getTimeoutSec(),
            maxTokens: self::MAX_RESPONSE_TOKENS,
        );

        if ($this->chatClient->startGenerate($messages, $options)) {
            $this->generationInFlight = true;
            Logger::logAgentInfo($this->getId(), '[generate] Started');
        }
    }

    /**
     * Build LLM messages for generation (system + user context).
     *
     * @param ViewBot $bot Bot view with name, description, personality, etc.
     * @return list<Message> Messages for LLM (system + user)
     */
    private function buildGenerationMessages(ViewBot $bot): array
    {
        $systemParts = [];
        $systemParts[] = "You are a chat participant. Name: {$bot->name}.";
        if ($bot->description !== null && $bot->description !== '') {
            $systemParts[] = "Description: {$bot->description}";
        }
        if ($bot->personality !== null && $bot->personality !== '') {
            $systemParts[] = "Personality: {$bot->personality}";
        }
        if ($bot->style !== null && $bot->style !== '') {
            $systemParts[] = "Style: {$bot->style}";
        }
        if ($bot->topics !== null && $bot->topics !== '') {
            $systemParts[] = "Preferred topics: {$bot->topics}";
        }
        $systemParts[] = "Your personality and style override generic politeness. Be bold in character.";
        $systemParts[] = "CRITICAL: " . BotEnv::getLanguageInstruction() . " All your output must be in this language.";
        $systemParts[] = "Never refer to yourself by name. Speak in first person (I, me, my). Do not start with your own name.";
        $systemParts[] = "Respond to what others said. Build on their ideas, challenge them, or agree — but always in character. Do not speak in a vacuum.";
        $systemParts[] = "If a user (User#N) suggested a topic or expressed an opinion, engage with it confidently — agree, challenge, or build on it. Stay in character.";
        $systemParts[] = "Be confident. Avoid hedging (maybe, perhaps, I think, I'm not sure). State your views directly in character.";
        $systemParts[] = "Prefer statements over questions. Do not end your message with a question mark. Contribute with assertions and opinions, not interrogations.";
        $systemParts[] = "Keep your message under 240 characters.";
        $systemParts[] = "Use emojis and Unicode symbols (e.g. 😊, 👍, ❤️, 🤔, ✨) to express emotions.";
        $systemParts[] = "Respond briefly. Stay in character. Do not repeat what others said.";

        $systemContent = implode("\n", $systemParts);

        $userParts = [];
        $ctx = Hilos::$rt->chatContexts->getStateCollection()->get(StateChatContext::ID_MAIN);
        if ($ctx !== null) {
            if ($ctx->topic !== null && $ctx->topic !== '') {
                $userParts[] = "Current topic: {$ctx->topic}";
            }
            if ($ctx->summary !== '') {
                $userParts[] = "Summary: {$ctx->summary}";
            }
        }

        $recentContext = $this->buildRecentEventsContext();
        $recentMeta = $this->getRecentMessagesMeta($recentContext);
        if ($recentContext !== '') {
            $userParts[] = "Recent messages (react to these):\n{$recentContext}";
        }

        if ($userParts === []) {
            $topicsList = ChatTopicConstants::getTopicsForPrompt();
            $userParts[] = "The chat is empty or just started. No topic yet."
                . " Propose exactly one topic from this list: {$topicsList}"
                . " Say which topic you suggest and briefly why. Greet everyone.";
        }

        $userContent = implode("\n\n", $userParts);

        Logger::logAgentInfo(
            $this->getId(),
            "[generate] Reacting to: {$recentMeta}"
        );

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(Message::ROLE_USER, $userContent),
        ];
    }

    /**
     * Build short meta string for logging: what the bot is reacting to.
     *
     * @param string $recentContext Recent messages context text
     * @return string Meta description (e.g. "5 message(s), last from User#1, Bot#2")
     */
    private function getRecentMessagesMeta(string $recentContext): string
    {
        if ($recentContext === '') {
            return 'empty chat (starting conversation)';
        }

        $lines = explode("\n", $recentContext);
        $count = count($lines);
        $lastAuthors = array_slice(array_map(
            static function (string $line): string {
                $colon = strpos($line, ':');

                return $colon !== false ? trim(substr($line, 0, $colon)) : '?';
            },
            $lines
        ), -3);

        return "{$count} message(s), last from " . implode(', ', $lastAuthors);
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
                $msg = is_array($data) && isset($data['message']) ? (string) $data['message'] : '(no text)';
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
     * Get bot view by agent index (bot ID).
     *
     * @return ?ViewBot Bot view or null if not found
     */
    private function getBot(): ?ViewBot
    {
        $botId = (int) $this->agentIndex;

        return Hilos::$db->bots[$botId] ?? null;
    }
}
