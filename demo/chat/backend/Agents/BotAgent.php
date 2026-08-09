<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatContextAnalyzerConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ChatTopicConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Database\View\Item\Bot as ViewBot;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Utils\ChatLLMHelper;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\HilosException;
use Hilos\LLM\Agent\AbstractLlmChatAgent;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Per-bot agent that schedules async LLM reactions and publishes generated messages through ChatAgent.
 *
 * One agent is keyed by bot id through agentIndex. It reacts to chat context syncs and its own bot row changes.
 */
final class BotAgent extends AbstractLlmChatAgent
{
    public const string AGENT_TYPE = AgentType::BOT;

    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_AGENT_START => [
            AgentSignalConfigKey::INDEX_FIELD => 'botId',
            AgentSignalConfigKey::DTO => BotAgentSignalData::class,
        ],
    ];

    private const int MAX_RESPONSE_TOKENS = 256;

    private float $scheduledReactAt = 0.0;

    private float $lastMessageSentAt = 0.0;

    private bool $generationInFlight = false;

    private int $botId;

    /**
     * Creates a bot agent bound to a persisted bot id.
     *
     * @param string $agentIndex Bot id from the agent manager
     * @throws AgentIndexRequiredException When agentIndex is empty
     * @throws InvalidAgentIndexException When agentIndex is not a positive integer
     * @throws LLMConfigurationException When the chat.bot profile cannot be resolved
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('BotAgent requires non-empty agentIndex (bot id)');
        }
        $botId = ctype_digit($agentIndex)
            ? filter_var($agentIndex, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($botId === false) {
            throw new InvalidAgentIndexException('BotAgent requires positive integer agentIndex (bot id)');
        }
        $this->agentIndex = $agentIndex;
        $this->botId = $botId;

        parent::__construct();
    }

    /**
     * @return string The chat bot LLM profile key
     */
    protected function profileKey(): string
    {
        return ChatLLMConstants::PROFILE_BOT;
    }

    /**
     * Marks this bot online and schedules an initial reaction fallback.
     *
     * @throws HilosException On bot lookup failure
     */
    public function onStart(): void
    {
        $this->registerRtTruthSource(ChatRtContext::botAgentStatuses, [(string)$this->botId]);
        Hilos::$rt->botAgentStatuses->actions->ensure($this->botId)->actions->markJoined();
        $this->scheduleReaction();
    }

    /**
     * Marks this bot offline.
     *
     * @throws HilosException When runtime status cleanup fails
     */
    public function onStop(): void
    {
        Hilos::$rt->botAgentStatuses->actions->ensure($this->botId)->actions->markLeft();
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
        if ((int)$data->idString !== $this->botId) {
            return;
        }

        if (($data->row[ObjectBot::active] ?? null) === false) {
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
        if ((int)$data->idString !== $this->botId) {
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
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey !== ChatDbContext::events) {
            return;
        }

        if ($data->row[ObjectEvent::type] === ChatEventType::CHAT_CLEARED->value) {
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
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === ChatRtContext::chatContext) {
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
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === ChatRtContext::chatContext) {
            $this->scheduleReaction();
        }
    }

    /**
     * @return bool Whether a scheduled reaction is due
     */
    protected function hasPendingWork(): bool
    {
        return $this->scheduledReactAt > 0.0 && microtime(true) >= $this->scheduledReactAt;
    }

    /**
     * Consumes the scheduled reaction and starts generation when the bot should react.
     *
     * @throws HilosException On bot or chat context lookup failure
     */
    protected function startRequest(): void
    {
        $this->scheduledReactAt = 0.0;
        if ($this->shouldReact()) {
            $this->startGenerate();
        }
    }

    /**
     * Publishes a generated bot message to ChatAgent.
     *
     * @param string $text Generated message text
     */
    protected function handleResult(string $text): void
    {
        $this->generationInFlight = false;

        $message = trim($text);
        if ($message !== '') {
            $this->lastMessageSentAt = microtime(true);
            $this->sendToAgent(
                ChatSignalConstants::BOT_MESSAGE,
                new BotMessageSignalData(botId: $this->botId, message: $message),
            );
        }
    }

    /**
     * Clears the in-flight flag on LLM errors before the default logging.
     *
     * @param LLMException $error The error raised while driving the client
     */
    protected function onLlmError(LLMException $error): void
    {
        $this->generationInFlight = false;
        parent::onLlmError($error);
    }

    /**
     * Schedules the next reaction from bot delay settings unless generation is active or bot is missing.
     *
     * @throws HilosException When bot lookup fails
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

        $delayMin = max(0, $bot->reactionDelayMin);
        $delayMax = max($delayMin, $bot->reactionDelayMax);
        $delaySec = $delayMin === $delayMax ? $delayMin : RandomHelper::integer($delayMin, $delayMax);
        $this->scheduledReactAt = microtime(true) + (float) $delaySec;
    }

    /**
     * Checks chance, cooldown, and topic restrictions for the current bot.
     *
     * @return bool True if bot should generate a message
     * @throws HilosException When bot or chat context lookup fails
     */
    private function shouldReact(): bool
    {
        $bot = $this->getBot();
        if ($bot === null) {
            return false;
        }

        $reactionChance = $bot->reactionChance;
        if ($reactionChance <= 0) {
            return false;
        }
        if ($reactionChance < 100 && RandomHelper::integer(1, 100) > $reactionChance) {
            return false;
        }

        $cooldownSec = $bot->cooldownAfterMessage;
        if ($cooldownSec > 0 && $this->lastMessageSentAt > 0) {
            if (microtime(true) - $this->lastMessageSentAt < $cooldownSec) {
                return false;
            }
        }

        if ($bot->topicMatchRequired && !$this->topicMatches($bot)) {
            return false;
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
        $botTopics = $this->parseTopics($bot->topics);
        if ($botTopics === []) {
            return true;
        }

        $contextTopic = Hilos::$rt->chatContext->topic;
        if ($contextTopic === null || $contextTopic === '') {
            return false;
        }

        $contextLower = mb_strtolower($contextTopic);

        return array_any($botTopics, fn($topic) => $topic !== '' && str_contains($contextLower, mb_strtolower($topic)));
    }

    /**
     * Parses topics from a JSON array or comma/semicolon-separated string.
     *
     * @param ?string $topics Topics as JSON or comma/semicolon-separated string, or null when the bot restricts none
     * @return list<string> List of topic strings
     */
    private function parseTopics(?string $topics): array
    {
        if ($topics === null) {
            return [];
        }

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

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));
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

        $options = new ChatGenerateOptions(
            model: $this->profile->model,
            temperature: 0.7,
            timeoutSec: $this->profile->timeoutSec,
            maxTokens: self::MAX_RESPONSE_TOKENS,
        );

        try {
            $this->chatClient->startGenerate($messages, $options);
            $this->generationInFlight = true;
        } catch (LLMException $e) {
            $this->logAgentError($e->getMessage());
            $this->generationInFlight = false;
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
        $systemParts[] = "CRITICAL: "
            . ChatLLMHelper::getLanguageInstruction(
                Hilos::$setting[ChatSettingsConstants::CHAT_BOT_LANGUAGE]->string(),
            )
            . " All your output must be in this language.";
        $systemParts[] = "Never refer to yourself by name. Speak in first person (I, me, my). Do not start with your own name.";
        $systemParts[] = "Respond to what others said. Build on their ideas, challenge them, or agree — but always in character. Do not speak in a vacuum.";
        $systemParts[] = "If a user (User#N) suggested a topic or expressed an opinion, engage with it confidently — agree, challenge, or build on it. Stay in character.";
        $systemParts[] = "Be confident. Avoid hedging (maybe, perhaps, I think, I'm not sure). State your views directly in character.";
        $systemParts[] = "Prefer statements over questions. Do not end your message with a question mark. Contribute with assertions and opinions, not interrogations.";
        $systemParts[] = "Keep your message under 240 characters.";
        $systemParts[] = "Use emojis and Unicode symbols (e.g. 😊, 👍, ❤️, 🤔, ✨) to express emotions.";
        $systemParts[] = "Respond briefly. Stay in character. Do not repeat what others said.";

        $userParts = [];
        if (Hilos::$rt->chatContext->topic !== null && Hilos::$rt->chatContext->topic !== '') {
            $userParts[] = "Current topic: " . Hilos::$rt->chatContext->topic;
        }
        if (Hilos::$rt->chatContext->summary !== null) {
            $userParts[] = "Summary: " . Hilos::$rt->chatContext->summary;
        }

        $recentContext = $this->buildRecentEventsContext();
        if ($recentContext !== '') {
            $userParts[] = "Recent messages (react to these):\n{$recentContext}";
        }

        if ($userParts === []) {
            $userParts[] = "The chat is empty or just started. No topic yet."
                . " Propose exactly one topic from this list: " . ChatTopicConstants::getTopicsForPrompt()
                . " Say which topic you suggest and briefly why. Greet everyone.";
        }

        return [
            new Message(Message::ROLE_SYSTEM, implode("\n", $systemParts)),
            new Message(Message::ROLE_USER, implode("\n\n", $userParts)),
        ];
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
                $eventMessage = $event->eventMessage;
                $message = $eventMessage?->message !== null && $eventMessage->message !== ''
                    ? $eventMessage->message
                    : '(no text)';
                $author = $eventMessage?->authorUserId !== null
                    ? "User#{$eventMessage->authorUserId}"
                    : "Bot#{$eventMessage?->authorBotId}";
                $linesByEventId[(int)($event->id ?? 0)] = $author
                    . ': '
                    . $message;
            }
            if ($event->type === ChatEventType::CHAT_CLEARED->value) {
                $linesByEventId[(int)($event->id ?? 0)] = 'System: [chat cleared]';
            }
        }

        ksort($linesByEventId);

        return implode("\n", array_slice($linesByEventId, -ChatContextAnalyzerConstants::MAX_RECENT_EVENTS));
    }

    /**
     * Returns this agent's bot view, or null when the bot row is gone.
     *
     * @return ?ViewBot Current bot view
     */
    private function getBot(): ?ViewBot
    {
        return Hilos::$db->bots[$this->botId] ?? null;
    }
}
