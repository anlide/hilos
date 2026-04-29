<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatContextAnalyzerConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ChatTopicConstants;
use Demo\Chat\Core\Router\DTO\ModerationBotRequestSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\View\Item\Bot as ViewBot;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Utils\ChatLLMHelper;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Random\RandomException;

/**
 * Per-bot agent that schedules async LLM reactions and submits generated messages for moderation.
 *
 * One agent is keyed by bot id through agentIndex. It reacts to chat context syncs and its own bot row changes.
 */
class BotAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::BOT;

    private const int MAX_RESPONSE_TOKENS = 256;

    private AsyncChatLLMInterface $chatClient;

    private float $scheduledReactAt = 0.0;

    private float $lastMessageSentAt = 0.0;

    private bool $generationInFlight = false;

    /**
     * Creates a bot agent bound to a persisted bot id.
     *
     * @param string $agentIndex Bot id from the agent manager
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('BotAgent requires non-empty agentIndex (bot id)');
        }
        $this->agentIndex = $agentIndex;

        $this->chatClient = ChatSettingsHelper::getBotProviderIsExternal()
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: ChatSettingsHelper::getBotUrl(),
                model: ChatSettingsHelper::getBotModel(),
            );
    }

    /**
     * Marks this bot online and schedules an initial reaction fallback.
     *
     * @throws HilosException On bot lookup failure
     * @throws RandomException When scheduling the initial bot reaction delay fails
     */
    public function onStart(): void
    {
        $botId = (int) $this->agentIndex;
        $this->registerRtTruthSource(RtChatContext::botAgentStatuses, [(string) $botId]);
        Hilos::$rt->botAgentStatuses->actions->markJoined($botId);

        // Bots without topic restriction (leaders) should start conversation when chat is empty.
        // RtSync from init() may arrive before BotAgents exist, so schedule on join as fallback.
        $this->scheduleReaction();
    }

    /**
     * Marks this bot offline.
     */
    public function onStop(): void
    {
        $botId = (int) $this->agentIndex;
        Hilos::$rt->botAgentStatuses->actions->markLeft($botId);
    }

    /**
     * Stops this bot agent when its own bot row becomes inactive.
     *
     * @param DbSyncUpdatedSignalData $data Updated bot row sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
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
     * Stops this bot agent when its own bot row is deleted.
     *
     * @param DbSyncDeletedSignalData $data Deleted bot row sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
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
     * Schedules a post-clear reaction when the chat event stream is reset.
     *
     * @param DbSyncCreatedSignalData $data Created event row sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On bot lookup during reaction scheduling
     * @throws RandomException When scheduling the post-clear reaction delay fails
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey !== DbChatContext::events) {
            return;
        }
        $eventType = $data->row['type'] ?? '';
        if ($eventType === ChatEventType::CHAT_CLEARED->value) {
            $this->logAgentInfo('[chat_cleared] Scheduling topic proposal');
            $this->scheduleReaction();
        }
    }

    /**
     * Schedules a bot reaction when the main chat context is created.
     *
     * @param RtSyncCreatedSignalData $data Created runtime context sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On bot lookup during reaction scheduling
     * @throws RandomException When scheduling the reaction delay after context creation fails
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === RtChatContext::chatContexts) {
            $this->scheduleReaction();
        }
    }

    /**
     * Schedules a bot reaction when the main chat context is updated.
     *
     * @param RtSyncUpdatedSignalData $data Updated runtime context sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On bot lookup during reaction scheduling
     * @throws RandomException When scheduling the reaction delay after context update fails
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === RtChatContext::chatContexts) {
            $this->scheduleReaction();
        }
    }

    /**
     * Advances async LLM work, sends completed messages to moderation, and starts due reactions.
     *
     * @throws HilosException On bot or chat context lookup failure
     * @throws RandomException When bot reaction chance generation fails
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
                $this->logAgentInfo("[publish] Queued for moderation, {$len} chars");
            } else {
                $this->logAgentInfo("[publish] Skipped: LLM returned empty");
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
     * Schedules the next reaction from bot delay settings unless generation is active or bot is missing.
     *
     * @throws HilosException When bot lookup fails
     * @throws RandomException When generating a randomized reaction delay fails
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

        $delayMin = max(0, $bot->reactionDelayMin ?? 0);
        $delayMax = max($delayMin, $bot->reactionDelayMax ?? 1);
        $delaySec = $delayMin === $delayMax ? $delayMin : random_int($delayMin, $delayMax);
        $this->scheduledReactAt = microtime(true) + (float) $delaySec;

        $this->logAgentInfo("[schedule] Reaction in {$delaySec}s");
    }

    /**
     * Checks chance, cooldown, and topic restrictions for the current bot.
     *
     * @return bool True if bot should generate a message
     * @throws HilosException When bot or chat context lookup fails
     * @throws RandomException When generating a random chance threshold fails
     */
    private function shouldReact(): bool
    {
        $bot = $this->getBot();
        if ($bot === null) {
            return false;
        }

        $reactionChance = $bot->reactionChance ?? 100;
        if ($reactionChance <= 0) {
            return false;
        }
        if ($reactionChance < 100 && random_int(1, 100) > $reactionChance) {
            return false;
        }

        $cooldownSec = $bot->cooldownAfterMessage ?? 0;
        if ($cooldownSec > 0 && $this->lastMessageSentAt > 0) {
            $elapsed = microtime(true) - $this->lastMessageSentAt;
            if ($elapsed < $cooldownSec) {
                return false;
            }
        }

        if ($bot->topicMatchRequired ?? false) {
            if (!$this->topicMatches($bot)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks configured bot topics against the current analyzer topic.
     *
     * @param ViewBot $bot Bot view with topics configuration
     * @return bool True if topic matches or bot has no topic restriction
     * @throws HilosException When runtime context lookup fails
     */
    private function topicMatches(ViewBot $bot): bool
    {
        $botTopics = $this->parseTopics($bot->topics ?? '');
        if ($botTopics === []) {
            return true;
        }

        $ctx = Hilos::$rt->chatContexts->getStateCollection()->get(StateChatContext::ID_MAIN);
        $contextTopic = $ctx?->topic;
        if ($contextTopic === null || $contextTopic === '') {
            return false;
        }

        $contextLower = mb_strtolower($contextTopic);

        return array_any($botTopics, fn($topic) => $topic !== '' && str_contains($contextLower, mb_strtolower($topic)));
    }

    /**
     * Parses topics from a JSON array or comma/semicolon-separated string.
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
     * Starts async LLM generation when a bot and prompt context are available.
     *
     * @throws HilosException When bot or chat context lookup fails
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

        $timeoutSec = ChatSettingsHelper::getBotTimeoutSec();
        $options = new ChatGenerateOptions(
            model: ChatSettingsHelper::getBotModel(),
            temperature: 0.7,
            timeoutSec: $timeoutSec > 0 ? $timeoutSec : LLMConstants::DEFAULT_TIMEOUT_SEC,
            maxTokens: self::MAX_RESPONSE_TOKENS,
        );

        if ($this->chatClient->startGenerate($messages, $options)) {
            $this->generationInFlight = true;
            $this->logAgentInfo('[generate] Started');
        }
    }

    /**
     * Builds the system prompt and user context for bot message generation.
     *
     * @param ViewBot $bot Bot view with name, description, personality, etc.
     * @return list<Message> Messages for LLM (system + user)
     * @throws HilosException When chat context or recent event lookup fails
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
        $lang = ChatSettingsHelper::getBotLanguage();
        $systemParts[] = "CRITICAL: " . ChatLLMHelper::getLanguageInstruction($lang) . " All your output must be in this language.";
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

        $this->logAgentInfo(
            "[generate] Reacting to: {$recentMeta}"
        );

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(Message::ROLE_USER, $userContent),
        ];
    }

    /**
     * Builds a short log label that describes what the bot is reacting to.
     *
     * @param string $recentContext Recent messages context text
     * @return string Meta description such as "5 message(s), last from User#1, Bot#2"
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
     * Builds recent chat events as formatted text for LLM context.
     *
     * @return string Recent message and clear-event lines for LLM context
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
     * Returns this agent's bot view, or null when the bot row is gone.
     *
     * @return ?ViewBot Current bot view
     */
    private function getBot(): ?ViewBot
    {
        $botId = (int) $this->agentIndex;

        return Hilos::$db->bots[$botId] ?? null;
    }
}
