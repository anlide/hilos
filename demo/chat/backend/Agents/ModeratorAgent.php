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
use Hilos\LLM\Contract\ChatLLMInterface;
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

    private ChatLLMInterface $chatClient;

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
        // TODO: Add moderator-specific logic here
        // For example: process queued messages for moderation, run AI checks, etc.
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
        [$allow, $reason] = $this->moderateAndNormalize($payload->message, userId: $payload->userId);
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
    }

    private function handleModerateBotRequest(ModerationBotRequestSignalData $payload): void
    {
        [$allow, $reason] = $this->moderateAndNormalize($payload->message, botId: $payload->botId);
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

    /**
     * Run moderation and return normalized [allow, reason].
     *
     * @return array{0: bool, 1: string}
     */
    private function moderateAndNormalize(string $message, ?int $userId = null, ?int $botId = null): array
    {
        $response = $this->moderateMessage($message, $userId, $botId);
        return [
            $response['allow'] ?? false,
            $response['reason'] ?? ($response === null ? 'service_unavailable' : 'unknown'),
        ];
    }

    private function logInvalidPayload(string $name, mixed $payload): void
    {
        Logger::logAgentError('ModeratorAgent', "Invalid payload type for {$name}: " . get_class($payload));
    }

    /**
     * Moderates a message with LLM (local or external by config).
     *
     * @param string $message Message text
     * @param ?int $userId Sender user ID (for user messages)
     * @param ?int $botId Bot ID (for bot messages; mutually exclusive with userId)
     *
     * @return ?array{allow: bool, reason: string}
     */
    private function moderateMessage(string $message, ?int $userId = null, ?int $botId = null): ?array
    {
        $startedAt = microtime(true);
        $model = ModerationEnv::getModel();
        $timeoutSec = ModerationEnv::getTimeoutSec();
        $messageLength = mb_strlen($message);
        $authorContext = $userId !== null ? "userId={$userId}" : "botId={$botId}";

        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation request started [{$authorContext}; messageLen={$messageLength}; model={$model}; timeoutSec={$timeoutSec}]"
        );

        $messages = $this->buildModerationMessages($message, $userId, $botId);
        $options = new ChatGenerateOptions(
            model: $model,
            temperature: 0.0,
            timeoutSec: $timeoutSec,
        );

        $modelText = $this->chatClient->generate($messages, $options);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($modelText === null) {
            Logger::logAgentInfo(
                'ModeratorAgent',
                "Moderation request finished [{$authorContext}; decision=block_unavailable; reason=service_unavailable; durationMs={$elapsedMs}]"
            );
            return null;
        }

        $decision = self::parseModerationDecision($modelText);
        if ($decision === null) {
            Logger::logAgentError(
                'ModeratorAgent',
                'Moderation response JSON parse failed [modelText=' . self::truncateForLog($modelText) . ']'
            );
            Logger::logAgentInfo(
                'ModeratorAgent',
                "Moderation request finished [{$authorContext}; decision=block_parse_error; reason=parse_error; durationMs={$elapsedMs}]"
            );
            return null;
        }

        $decisionValue = $decision['allow'] ? 'allow' : 'block';
        $reason = $decision['reason'] !== '' ? $decision['reason'] : 'none';
        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation request finished [{$authorContext}; decision={$decisionValue}; reason={$reason}; durationMs={$elapsedMs}]"
        );

        return $decision;
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

        if (preg_match('/\{.*\}/s', $trimmed, $matches) === 1) {
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

        $systemContent = <<<PROMPT
You are a strict chat moderation classifier.
Output must be ONLY valid JSON object, without markdown and without explanations.

Decision rules:
{$rulesBlock}

Return JSON with this exact schema:
{"allow": true|false, "reason": "short_snake_case_reason"}
PROMPT;

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

    private static function truncateForLog(string $text, int $limit = 1200): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . '...<truncated>';
    }
}
