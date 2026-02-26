<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Utils\Logger;

/**
 * ModeratorAgent - Regular agent for content moderation
 *
 * Runs in regular worker process. Manages content moderation and AI-based checks.
 */
class ModeratorAgent extends AbstractAgent
{
    /** Default local moderation model name (Ollama). */
    private const string DEFAULT_MODERATION_MODEL = 'qwen2.5:3b';

    /** Default local moderation endpoint. */
    private const string DEFAULT_MODERATION_URL = 'http://127.0.0.1:11434/api/generate';

    /** Default moderation request timeout in seconds. */
    private const float DEFAULT_MODERATION_TIMEOUT_SEC = 20.0;

    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::MODERATOR;

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
     * Handle agent-to-agent signal (moderation request from ChatAgent).
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $payload = $data->data;
        if (!$payload instanceof ModerationRequestSignalData) {
            Logger::logAgentError('ModeratorAgent', "Invalid payload type for {$name}: " . get_class($payload));
            return;
        }

        $moderationResponse = self::moderateMessage($payload->message, $payload->userId);

        $allow = $moderationResponse['allow'] ?? false;
        $reason = $moderationResponse['reason'] ?? ($moderationResponse === null ? 'service_unavailable' : 'unknown');

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

    /**
     * Moderates a message with local LLM model.
     *
     * @param string $message User message text
     * @param int $userId Sender user ID
     *
     * @return ?array{allow: bool, reason: string}
     */
    private static function moderateMessage(string $message, int $userId): ?array
    {
        $startedAt = microtime(true);
        $model = self::getModerationModel();
        $url = self::getModerationUrl();
        $timeoutSec = self::getModerationTimeoutSec();
        $messageLength = mb_strlen($message);

        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation request started [userId={$userId}; messageLen={$messageLength}; model={$model}; url={$url}; timeoutSec={$timeoutSec}]"
        );

        $prompt = self::buildModerationPrompt($message, $userId);
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0,
            ],
        ];

        $rawResponse = self::postJson($url, $payload, $timeoutSec);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($rawResponse === null) {
            Logger::logAgentInfo(
                'ModeratorAgent',
                "Moderation request finished [userId={$userId}; decision=block_unavailable; reason=service_unavailable; durationMs={$elapsedMs}]"
            );
            return null;
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded) || !is_string($decoded['response'] ?? null)) {
            Logger::logAgentError(
                'ModeratorAgent',
                'Moderation response has invalid format'
                . " [url={$url}; timeoutSec={$timeoutSec}; model={$payload['model']}; rawResponse="
                . self::truncateForLog($rawResponse, 1200)
                . '; replay='
                . self::buildCurlReplayCommand($url, $payload)
                . ']'
            );
            Logger::logAgentInfo(
                'ModeratorAgent',
                "Moderation request finished [userId={$userId}; decision=block_invalid_format; reason=invalid_format; durationMs={$elapsedMs}]"
            );
            return null;
        }

        $modelText = trim($decoded['response']);
        $decision = self::parseModerationDecision($modelText);
        if ($decision === null) {
            Logger::logAgentError(
                'ModeratorAgent',
                'Moderation response JSON parse failed'
                . " [url={$url}; timeoutSec={$timeoutSec}; model={$payload['model']}; modelText="
                . self::truncateForLog($modelText, 1200)
                . '; replay='
                . self::buildCurlReplayCommand($url, $payload)
                . ']'
            );
            Logger::logAgentInfo(
                'ModeratorAgent',
                "Moderation request finished [userId={$userId}; decision=block_parse_error; reason=parse_error; durationMs={$elapsedMs}]"
            );
            return null;
        }

        $decisionValue = $decision['allow'] ? 'allow' : 'block';
        $reason = $decision['reason'] !== '' ? $decision['reason'] : 'none';
        Logger::logAgentInfo(
            'ModeratorAgent',
            "Moderation request finished [userId={$userId}; decision={$decisionValue}; reason={$reason}; durationMs={$elapsedMs}]"
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
     * Builds moderation prompt from DB rules and user message.
     *
     * @param string $message User message text
     * @param int $userId Sender user ID
     *
     * @return string Prompt for moderation model
     */
    private static function buildModerationPrompt(string $message, int $userId): string
    {
        $rules = self::getMessageModerationRules();
        $rulesBlock = $rules === []
            ? "- Default policy: allow benign messages.\n- Block only explicit insults, threats, hate speech, sexual content, and obvious spam.\n- If uncertain, return allow=true."
            : implode("\n", array_map(static fn(string $rule): string => "- {$rule}", $rules));

        return <<<PROMPT
You are a strict chat moderation classifier.
Output must be ONLY valid JSON object, without markdown and without explanations.

Decision rules:
{$rulesBlock}

User ID: {$userId}
Message:
{$message}

Return JSON with this exact schema:
{"allow": true|false, "reason": "short_snake_case_reason"}
PROMPT;
    }

    /**
     * @return list<string>
     */
    private static function getMessageModerationRules(): array
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

    /**
     * Sends JSON POST request and returns response body.
     *
     * @param string $url Endpoint URL
     * @param array<string, mixed> $payload Request payload
     * @param float $timeoutSec Request timeout in seconds
     *
     * @return ?string Response body, or null on transport/format errors
     */
    private static function postJson(string $url, array $payload, float $timeoutSec): ?string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            Logger::logAgentError('ModeratorAgent', 'Failed to encode moderation request payload');
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => $timeoutSec,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response) || $response === '') {
            $error = error_get_last();
            $httpHeaders = isset($http_response_header) && is_array($http_response_header)
                ? implode(' | ', $http_response_header)
                : 'none';
            $model = is_string($payload['model'] ?? null) ? $payload['model'] : 'unknown';

            Logger::logAgentError(
                'ModeratorAgent',
                'Moderation service is unavailable'
                . " [url={$url}; timeoutSec={$timeoutSec}; model={$model}; httpHeaders={$httpHeaders}; phpError="
                . ($error['message'] ?? 'none')
                . '; payload='
                . self::truncateForLog($body, 1200)
                . '; replay='
                . self::buildCurlReplayCommand($url, $payload)
                . ']'
            );
            return null;
        }

        return $response;
    }

    /**
     * Builds curl command that can replay moderation request manually.
     *
     * @param string $url Endpoint URL
     * @param array<string, mixed> $payload Request payload
     *
     * @return string One-line curl replay command
     */
    private static function buildCurlReplayCommand(string $url, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return 'curl command unavailable (payload encode failed)';
        }

        $escaped = str_replace("'", "'\"'\"'", $json);
        return "curl -sS -X POST '{$url}' -H 'Content-Type: application/json' -d '{$escaped}'";
    }

    /**
     * Truncates long log values to keep logs readable.
     *
     * @param string $text Source text
     * @param int $limit Max length
     *
     * @return string Truncated text
     */
    private static function truncateForLog(string $text, int $limit): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . '...<truncated>';
    }

    /**
     * Resolves moderation model name from environment.
     *
     * Uses CHAT_MODERATION_MODEL or falls back to DEFAULT_MODERATION_MODEL.
     *
     * @return string Model name
     */
    private static function getModerationModel(): string
    {
        $value = getenv('CHAT_MODERATION_MODEL');
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return self::DEFAULT_MODERATION_MODEL;
    }

    /**
     * Resolves moderation endpoint URL from environment.
     *
     * Uses CHAT_MODERATION_URL or falls back to DEFAULT_MODERATION_URL.
     *
     * @return string URL to moderation API endpoint
     */
    private static function getModerationUrl(): string
    {
        $value = getenv('CHAT_MODERATION_URL');
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return self::DEFAULT_MODERATION_URL;
    }

    /**
     * Resolves moderation timeout from environment.
     *
     * Uses CHAT_MODERATION_TIMEOUT_SEC or falls back to DEFAULT_MODERATION_TIMEOUT_SEC.
     *
     * @return float Timeout in seconds
     */
    private static function getModerationTimeoutSec(): float
    {
        $value = getenv('CHAT_MODERATION_TIMEOUT_SEC');
        if (is_string($value) && is_numeric($value)) {
            $timeout = (float)$value;
            if ($timeout > 0) {
                return $timeout;
            }
        }

        return self::DEFAULT_MODERATION_TIMEOUT_SEC;
    }
}
