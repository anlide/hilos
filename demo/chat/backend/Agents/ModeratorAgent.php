<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Utils\ChatSettingsHelper;
use Demo\Chat\Core\Router\DTO\ModerationBotRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationBotResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Utils\Env;
use Hilos\Utils\Helpers\JsonHelper;
use Hilos\Utils\Logger;

/**
 * ModeratorAgent - Regular agent for content moderation.
 *
 * Runs in regular worker process. Manages content moderation and AI-based checks.
 * Uses framework LLM layer (local Ollama or external OpenAI) via settings with Env fallback.
 */
class ModeratorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

    /** @var AsyncChatLLMInterface LLM chat client for moderation */
    private AsyncChatLLMInterface $chatClient;

    /** @var list<array<string, mixed>> Queue of pending moderation requests (type, payload) */
    private array $pendingQueue = [];

    /** @var array<string, mixed>|null Request currently in flight (type, payload) or null */
    private ?array $currentPending = null;

    /**
     * Creates moderator agent with LLM client from Env config.
     */
    public function __construct()
    {
        $this->chatClient = ChatSettingsHelper::getModerationProviderIsExternal()
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: ChatSettingsHelper::getModerationUrl(),
                model: ChatSettingsHelper::getModerationModel(),
                apiKey: null,
            );
    }

    /**
     * Called when agent is started.
     * Registers as truth source for moderator prompt pieces.
     */
    public function onStart(): void
    {
        // Register this agent as truth source for moderator prompt pieces collection (all keys)
        TruthSourceRegistry::register(DbChatContext::moderatorPromptPieces, true, $this->getId());
    }

    /**
     * Called when agent is stopped.
     *
     * WorkerManager unregisters truth sources after this hook.
     */
    public function onStop(): void
    {
    }

    /**
     * Agent-specific tick implementation.
     * Processes LLM results and sends moderation decisions to ChatAgent.
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

        $authorContext = match ($pending['type']) {
            'user' => 'userId=' . ($pending['payload']->userId ?? '?'),
            'file' => 'file userId=' . ($pending['payload']->userId ?? '?'),
            default => 'botId=' . ($pending['payload']->botId ?? '?'),
        };
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
        } elseif ($pending['type'] === 'file') {
            /** @var ModerationFileRequestSignalData $payload */
            $payload = $pending['payload'];
            $this->sendToAgent(
                ChatSignalConstants::MODERATION_FILE_RESULT,
                new ModerationFileResultSignalData(
                    acceptKey: $payload->acceptKey,
                    userId: $payload->userId,
                    allow: $allow,
                    reason: $reason,
                    quarantineBasename: $payload->quarantineBasename,
                    originalFilename: $payload->originalFilename,
                    mimeType: $payload->mimeType,
                    size: $payload->size,
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
     *
     * @param AgentSignalData $data Incoming signal payload
     * @param string          $source Source agent id
     * @param string          $name Signal name (MODERATE_REQUEST or MODERATE_BOT_REQUEST)
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
            case ChatSignalConstants::MODERATE_FILE_REQUEST:
                if ($payload instanceof ModerationFileRequestSignalData) {
                    $this->handleModerateFileRequest($payload);
                } else {
                    $this->logInvalidPayload($name, $payload);
                }
                return;
            default:
                $this->logInvalidPayload($name, $payload);
        }
    }

    /**
     * Queue or bypass user message moderation request.
     *
     * @param ModerationRequestSignalData $payload User message moderation request
     */
    private function handleModerateFileRequest(ModerationFileRequestSignalData $payload): void
    {
        if (!ChatSettingsHelper::getModerationUsers()) {
            $this->bypassModerationFile($payload);
            return;
        }

        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation file request queued [userId={$payload->userId}; file={$payload->originalFilename}]"
        );

        $this->pendingQueue[] = ['type' => 'file', 'payload' => $payload];
        $this->startNextPending();
    }

    /**
     * Send allow result without LLM when user moderation is disabled (files).
     */
    private function bypassModerationFile(ModerationFileRequestSignalData $payload): void
    {
        Logger::logAgentInfo('ModeratorAgent', "File moderation bypassed [userId={$payload->userId}] (disabled)");
        $this->sendToAgent(
            ChatSignalConstants::MODERATION_FILE_RESULT,
            new ModerationFileResultSignalData(
                acceptKey: $payload->acceptKey,
                userId: $payload->userId,
                allow: true,
                reason: 'disabled',
                quarantineBasename: $payload->quarantineBasename,
                originalFilename: $payload->originalFilename,
                mimeType: $payload->mimeType,
                size: $payload->size,
            ),
        );
    }

    private function handleModerateRequest(ModerationRequestSignalData $payload): void
    {
        if (!ChatSettingsHelper::getModerationUsers()) {
            $this->bypassModerationUser($payload);
            return;
        }

        $messageLength = mb_strlen($payload->message);
        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation request queued [userId={$payload->userId}; messageLen={$messageLength}]"
        );

        $this->pendingQueue[] = ['type' => 'user', 'payload' => $payload];
        $this->startNextPending();
    }

    /**
     * Queue or bypass bot message moderation request.
     *
     * @param ModerationBotRequestSignalData $payload Bot message moderation request
     */
    private function handleModerateBotRequest(ModerationBotRequestSignalData $payload): void
    {
        if (!ChatSettingsHelper::getModerationBots()) {
            $this->bypassModerationBot($payload);
            return;
        }

        $messageLength = mb_strlen($payload->message);
        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation bot request queued [botId={$payload->botId}; messageLen={$messageLength}]"
        );

        $this->pendingQueue[] = ['type' => 'bot', 'payload' => $payload];
        $this->startNextPending();
    }

    /**
     * Send allow result without LLM when user moderation is disabled.
     *
     * @param ModerationRequestSignalData $payload User message data to pass through
     */
    private function bypassModerationUser(ModerationRequestSignalData $payload): void
    {
        Logger::logAgentInfo('ModeratorAgent', "Moderation bypassed for user [userId={$payload->userId}] (disabled)");
        $this->sendToAgent(
            ChatSignalConstants::MODERATION_RESULT,
            new ModerationResultSignalData(
                acceptKey: $payload->acceptKey,
                userId: $payload->userId,
                message: $payload->message,
                allow: true,
                reason: 'disabled',
            ),
        );
    }

    /**
     * Send allow result without LLM when bot moderation is disabled.
     *
     * @param ModerationBotRequestSignalData $payload Bot message data to pass through
     */
    private function bypassModerationBot(ModerationBotRequestSignalData $payload): void
    {
        Logger::logAgentInfo('ModeratorAgent', "Moderation bypassed for bot [botId={$payload->botId}] (disabled)");
        $this->sendToAgent(
            ChatSignalConstants::MODERATION_BOT_RESULT,
            new ModerationBotResultSignalData(
                botId: $payload->botId,
                message: $payload->message,
                allow: true,
                reason: 'disabled',
            ),
        );
    }

    /**
     * Starts next pending moderation request via LLM if not busy.
     *
     * Shifts one item from queue and calls LLM. Re-queues item if LLM is busy.
     */
    private function startNextPending(): void
    {
        if ($this->chatClient->isBusy() || $this->pendingQueue === []) {
            return;
        }

        $item = array_shift($this->pendingQueue);
        $this->currentPending = $item;

        $payload = $item['payload'];
        $message = $item['type'] === 'file'
            ? $payload->syntheticMessage
            : $payload->message;
        $userId = $item['type'] === 'user' || $item['type'] === 'file' ? $payload->userId : null;
        $botId = $item['type'] === 'bot' ? $payload->botId : null;

        $messages = $this->buildModerationMessages($message, $userId, $botId);
        $timeoutSec = ChatSettingsHelper::getModerationTimeoutSec();
        $options = new ChatGenerateOptions(
            model: ChatSettingsHelper::getModerationModel(),
            temperature: 0.0,
            timeoutSec: $timeoutSec > 0 ? $timeoutSec : LLMConstants::DEFAULT_TIMEOUT_SEC,
            maxTokens: 32,
        );

        if (!$this->chatClient->startGenerate($messages, $options)) {
            $this->currentPending = null;
            array_unshift($this->pendingQueue, $item);
        }
    }

    /**
     * Log invalid signal payload and its type.
     *
     * @param string $name Signal name
     * @param mixed  $payload Payload instance (wrong type)
     */
    private function logInvalidPayload(string $name, mixed $payload): void
    {
        Logger::logAgentError('ModeratorAgent', "Invalid payload type for {$name}: " . get_class($payload));
    }

    /**
     * Parse JSON returned by moderation model.
     *
     * Expected shape: {"allow": true|false, "reason": "short reason"}
     *
     * @param string $text Raw model output
     * @return ?array<string, mixed> Parsed decision or null when invalid
     */
    private static function parseModerationDecision(string $text): ?array
    {
        $candidate = JsonHelper::extractJsonObject($text);
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
     * Build chat messages for moderation (system rules + author context + message).
     *
     * @param string   $message Message text to moderate
     * @param ?int     $userId User id when moderating user message
     * @param ?int     $botId Bot id when moderating bot message
     * @return list<Message> System and user messages for LLM
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
     * Fetch message moderation rules from database.
     *
     * @return list<string> Rules from moderator prompt pieces
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
