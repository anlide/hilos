<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Utils\ModerationEnv;
use Demo\Chat\Core\Router\DTO\ModerationBotRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationBotResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Utils\Logger;

/**
 * ModeratorAgent - Regular agent for content moderation
 *
 * Runs in regular worker process. Manages content moderation and AI-based checks.
 * Uses framework LLM layer (local Ollama or external OpenAI) via ModerationEnv.
 */
class ModeratorAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::MODERATOR;

    private AsyncChatLLMInterface $chatClient;

    /** @var list<array{type: 'user'|'bot', payload: ModerationRequestSignalData|ModerationBotRequestSignalData}> */
    private array $pendingQueue = [];

    /** @var ?array{type: 'user'|'bot', payload: ModerationRequestSignalData|ModerationBotRequestSignalData} Request currently in flight */
    private ?array $currentPending = null;

    public function __construct()
    {
        $this->chatClient = ModerationEnv::useExternalProvider()
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: ModerationEnv::getUrl(),
                model: ModerationEnv::getModel(),
                apiKey: null,
            );
    }

    /**
     * Get agent type
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index
     *
     * Moderator agent has no index (global singleton)
     *
     * @return ?string Agent index (null for global moderator agent)
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Called when agent is started
     */
    public function onStart(): void
    {
        Logger::logAgentStart($this->getId(), $this->getType());

        // Register this agent as truth source for moderator prompt pieces collection (all keys)
        TruthSourceRegistry::register(DbChatContext::moderatorPromptPieces, true, $this->getId());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        // Unregister as truth source
        TruthSourceRegistry::unregister(DbChatContext::moderatorPromptPieces, $this->getId());

        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        $this->chatClient->tick(microtime(true) * 1000);

        if (!$this->chatClient->hasResult()) {
            return;
        }

        $text = $this->chatClient->getResult();
        $pending = $this->currentPending;
        $this->currentPending = null;

        if ($pending === null) {
            return;
        }

        $allow = false;
        $reason = $text === null ? 'service_unavailable' : 'unknown';

        if ($text !== null) {
            $decision = self::parseModerationDecision($text);
            if ($decision !== null) {
                $allow = $decision['allow'];
                $reason = $decision['reason'] !== '' ? $decision['reason'] : 'none';
            }
        }

        $authorContext = $pending['type'] === 'user'
            ? 'userId=' . ($pending['payload']->userId ?? '?')
            : 'botId=' . ($pending['payload']->botId ?? '?');
        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation request finished [{$authorContext}; decision=" . ($allow ? 'allow' : 'block') . "; reason={$reason}]"
        );

        if ($pending['type'] === 'user') {
            /** @var ModerationRequestSignalData $payload */
            $payload = $pending['payload'];
            $this->sendToAgent(
                ChatSignalConstants::MODERATION_RESULT,
                new ModerationResultSignalData(
                    acceptKey: $payload->acceptKey,
                    userId: $payload->userId,
                    message: $payload->message,
                    allow: $allow,
                    reason: $reason,
                ),
            );
        } else {
            /** @var ModerationBotRequestSignalData $payload */
            $payload = $pending['payload'];
            $this->sendToAgent(
                ChatSignalConstants::MODERATION_BOT_RESULT,
                new ModerationBotResultSignalData(
                    botId: $payload->botId,
                    message: $payload->message,
                    allow: $allow,
                    reason: $reason,
                ),
            );
        }

        $this->startNextPending();
    }

    /**
     * Handle agent-to-agent signals (moderation request from ChatAgent or BotAgent).
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $payload = $data->data;

        switch ($name) {
            case ChatSignalConstants::MODERATE_REQUEST:
                if ($payload instanceof ModerationRequestSignalData) {
                    $this->handleModerateRequest($payload);
                } else {
                    $this->logInvalidPayload($name, $payload);
                }
                return;
            case ChatSignalConstants::MODERATE_BOT_REQUEST:
                if ($payload instanceof ModerationBotRequestSignalData) {
                    $this->handleModerateBotRequest($payload);
                } else {
                    $this->logInvalidPayload($name, $payload);
                }
                return;
            default:
                $this->logInvalidPayload($name, $payload);
        }
    }

    private function handleModerateRequest(ModerationRequestSignalData $payload): void
    {
        $messageLength = mb_strlen($payload->message);
        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation request queued [userId={$payload->userId}; messageLen={$messageLength}]"
        );

        $this->pendingQueue[] = ['type' => 'user', 'payload' => $payload];
        $this->startNextPending();
    }

    private function handleModerateBotRequest(ModerationBotRequestSignalData $payload): void
    {
        $messageLength = mb_strlen($payload->message);
        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation bot request queued [botId={$payload->botId}; messageLen={$messageLength}]"
        );

        $this->pendingQueue[] = ['type' => 'bot', 'payload' => $payload];
        $this->startNextPending();
    }

    private function startNextPending(): void
    {
        if ($this->chatClient->isBusy() || $this->pendingQueue === []) {
            return;
        }

        $item = array_shift($this->pendingQueue);
        $this->currentPending = $item;

        $payload = $item['payload'];
        $message = $payload->message;
        $userId = $item['type'] === 'user' ? $payload->userId : null;
        $botId = $item['type'] === 'bot' ? $payload->botId : null;

        $messages = $this->buildModerationMessages($message, $userId, $botId);
        $options = new ChatGenerateOptions(
            model: ModerationEnv::getModel(),
            temperature: 0.0,
            timeoutSec: ModerationEnv::getTimeoutSec(),
            maxTokens: 32,
        );

        if (!$this->chatClient->startGenerate($messages, $options)) {
            $this->currentPending = null;
            array_unshift($this->pendingQueue, $item);
        }
    }

    private function logInvalidPayload(string $name, mixed $payload): void
    {
        Logger::logAgentError('ModeratorAgent', "Invalid payload type for {$name}: " . get_class($payload));
    }

    /**
     * Parses JSON returned by moderation model.
     *
     * Expected shape:
     * {"allow": true|false, "reason": "short reason"}
     *
     * @param string $text Raw model output
     *
     * @return ?array{allow: bool, reason: string}
     */
    private static function parseModerationDecision(string $text): ?array
    {
        $candidate = self::extractJsonObject($text);
        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);
        if (!is_array($decoded) || !is_bool($decoded['allow'] ?? null)) {
            return null;
        }

        return [
            'allow' => $decoded['allow'],
            'reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '',
        ];
    }

    /**
     * Extracts first JSON object from model output text.
     *
     * @param string $text Raw model output
     *
     * @return ?string JSON object string when found
     */
    private static function extractJsonObject(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            return $trimmed;
        }

        if (preg_match('/\{.*}/s', $trimmed, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Build chat messages for moderation (system rules + author context + message).
     *
     * @return list<Message>
     */
    private function buildModerationMessages(string $message, ?int $userId = null, ?int $botId = null): array
    {
        $rules = $this->getMessageModerationRules();
        $rulesBlock = $rules === []
            ? "- Default policy: allow benign messages.\n- Block only explicit insults, threats, hate speech, sexual content, and obvious spam.\n- If uncertain, return allow=true."
            : implode("\n", array_map(static fn (string $rule): string => "- {$rule}", $rules));

        $systemContent = "Moderation. JSON only. Output: {\"allow\":true|false,\"reason\":\"ok|insult|threat|hate_speech|sexual|spam\"}\nRules:\n{$rulesBlock}";

        $authorLabel = $userId !== null ? "User ID: {$userId}" : "Bot ID: {$botId}";
        $userContent = "{$authorLabel}\nMessage:\n{$message}";

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(Message::ROLE_USER, $userContent),
        ];
    }

    /**
     * @return list<string>
     */
    private function getMessageModerationRules(): array
    {
        $rules = [];
        foreach (Hilos::$db->moderatorPromptPieces as $piece) {
            if ($piece->section !== ObjectModeratorPromptPiece::SECTION_MESSAGE_RULE) {
                continue;
            }

            $rule = trim($piece->promptPiece);
            if ($rule !== '') {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}
