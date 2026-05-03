<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ModerationBotRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationBotResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Utils\Helpers\JsonHelper;

/**
 * Regular agent that queues user and bot moderation requests and returns decisions.
 *
 * Uses async LLM moderation unless the matching moderation category is disabled.
 */
class ModeratorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

    private AsyncChatLLMInterface $chatClient;

    /**
     * @var list<array{
     *     type: 'user'|'bot',
     *     request: ModerationRequestSignalData|ModerationBotRequestSignalData
     * }>
     */
    private array $pendingQueue = [];

    /**
     * @var ?array{
     *     type: 'user'|'bot',
     *     request: ModerationRequestSignalData|ModerationBotRequestSignalData
     * }
     */
    private ?array $currentPending = null;

    /**
     * Creates a moderator with an LLM client from moderation settings.
     */
    public function __construct()
    {
        $this->chatClient = ChatSettingsHelper::getModerationProviderIsExternal()
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: ChatSettingsHelper::getModerationUrl(),
                model: ChatSettingsHelper::getModerationModel(),
            );
    }

    /**
     * Leaves in-memory moderation queue to be discarded with the worker.
     */
    public function onStop(): void
    {
    }

    /**
     * Advances async moderation, sends completed decisions, and starts the next queued request.
     *
     * @throws HilosException When moderation rule lookup fails
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
            'user' => 'userId=' . ($pending['request']->userId ?? '?'),
            default => 'botId=' . ($pending['request']->botId ?? '?'),
        };
        $this->logAgentInfo(
            "Moderation request finished [{$authorContext}; decision=" . ($allow ? 'allow' : 'block') . "; reason={$reason}]"
        );

        $this->sendModerationOutcome($pending, $allow, $reason);

        $this->startNextPending();
    }

    /**
     * Routes valid moderation request payloads into the queue.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner moderation payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Moderation request signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidAgentSignalPayloadException When signal payload does not match the signal name
     * @throws HilosException When moderation rule lookup fails
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::MODERATE_REQUEST:
                $moderationRequest = $data->data;
                if (!$moderationRequest instanceof ModerationRequestSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        ModerationRequestSignalData::class,
                        $moderationRequest,
                    );
                }
                $this->handleModerateRequest($moderationRequest);
                return;
            case ChatSignalConstants::MODERATE_BOT_REQUEST:
                $moderationBotRequest = $data->data;
                if (!$moderationBotRequest instanceof ModerationBotRequestSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        ModerationBotRequestSignalData::class,
                        $moderationBotRequest,
                    );
                }
                $this->handleModerateBotRequest($moderationBotRequest);
                return;
            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Queues or bypasses a user message moderation request.
     *
     * @param ModerationRequestSignalData $moderationRequest User message moderation request
     * @throws HilosException When moderation rule lookup fails
     */
    private function handleModerateRequest(ModerationRequestSignalData $moderationRequest): void
    {
        if (!ChatSettingsHelper::getModerationUsers()) {
            $this->bypassModerationUser($moderationRequest);
            return;
        }

        $messageLength = mb_strlen($moderationRequest->message);
        $this->logAgentInfo(
            "Moderation request queued [userId={$moderationRequest->userId}; messageLen={$messageLength}]"
        );

        $this->pendingQueue[] = ['type' => 'user', 'request' => $moderationRequest];
        $this->startNextPending();
    }

    /**
     * Queues or bypasses a bot message moderation request.
     *
     * @param ModerationBotRequestSignalData $moderationBotRequest Bot message moderation request
     * @throws HilosException When moderation rule lookup fails
     */
    private function handleModerateBotRequest(ModerationBotRequestSignalData $moderationBotRequest): void
    {
        if (!ChatSettingsHelper::getModerationBots()) {
            $this->bypassModerationBot($moderationBotRequest);
            return;
        }

        $messageLength = mb_strlen($moderationBotRequest->message);
        $this->logAgentInfo(
            "Moderation bot request queued [botId={$moderationBotRequest->botId}; messageLen={$messageLength}]"
        );

        $this->pendingQueue[] = ['type' => 'bot', 'request' => $moderationBotRequest];
        $this->startNextPending();
    }

    /**
     * Sends an allow result without LLM when user moderation is disabled.
     *
     * @param ModerationRequestSignalData $moderationRequest User message data to pass through
     */
    private function bypassModerationUser(ModerationRequestSignalData $moderationRequest): void
    {
        $this->logAgentInfo("Moderation bypassed for user [userId={$moderationRequest->userId}] (disabled)");
        $this->sendToAgent(
            ChatSignalConstants::MODERATION_RESULT,
            new ModerationResultSignalData(
                requestId: $moderationRequest->requestId,
                acceptKey: $moderationRequest->acceptKey,
                userId: $moderationRequest->userId,
                message: $moderationRequest->message,
                allow: true,
                reason: 'disabled',
            ),
        );
    }

    /**
     * Sends an allow result without LLM when bot moderation is disabled.
     *
     * @param ModerationBotRequestSignalData $moderationBotRequest Bot message data to pass through
     */
    private function bypassModerationBot(ModerationBotRequestSignalData $moderationBotRequest): void
    {
        $this->logAgentInfo("Moderation bypassed for bot [botId={$moderationBotRequest->botId}] (disabled)");
        $this->sendToAgent(
            ChatSignalConstants::MODERATION_BOT_RESULT,
            new ModerationBotResultSignalData(
                botId: $moderationBotRequest->botId,
                message: $moderationBotRequest->message,
                allow: true,
                reason: 'disabled',
            ),
        );
    }

    /**
     * Starts the next queued moderation request if the LLM client is idle.
     *
     * @throws HilosException When moderation rule lookup fails
     */
    private function startNextPending(): void
    {
        if ($this->chatClient->isBusy() || $this->pendingQueue === []) {
            return;
        }

        $item = array_shift($this->pendingQueue);
        $this->currentPending = $item;

        $queuedRequest = $item['request'];
        $message = $queuedRequest->message;
        if (
            $queuedRequest instanceof ModerationRequestSignalData
            && $queuedRequest->contentForModeration !== ''
        ) {
            $message = $queuedRequest->contentForModeration;
        }
        $userId = $queuedRequest instanceof ModerationRequestSignalData ? $queuedRequest->userId : null;
        $botId = $queuedRequest instanceof ModerationBotRequestSignalData ? $queuedRequest->botId : null;

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
            $this->sendModerationOutcome($item, false, 'service_unavailable');
            $this->startNextPending();
        }
    }

    /**
     * Sends the typed moderation result for a completed or unavailable queued item.
     *
     * @param array{
     *     type: 'user'|'bot',
     *     request: ModerationRequestSignalData|ModerationBotRequestSignalData
     * } $pending Queue item
     * @param bool $allow Whether moderation approved the item
     * @param string $reason Moderation reason
     */
    private function sendModerationOutcome(array $pending, bool $allow, string $reason): void
    {
        if ($pending['type'] === 'user') {
            /** @var ModerationRequestSignalData $moderationRequest */
            $moderationRequest = $pending['request'];
            $this->sendToAgent(
                ChatSignalConstants::MODERATION_RESULT,
                new ModerationResultSignalData(
                    requestId: $moderationRequest->requestId,
                    acceptKey: $moderationRequest->acceptKey,
                    userId: $moderationRequest->userId,
                    message: $moderationRequest->message,
                    allow: $allow,
                    reason: $reason,
                ),
            );

            return;
        }

        /** @var ModerationBotRequestSignalData $moderationBotRequest */
        $moderationBotRequest = $pending['request'];
        $this->sendToAgent(
            ChatSignalConstants::MODERATION_BOT_RESULT,
            new ModerationBotResultSignalData(
                botId: $moderationBotRequest->botId,
                message: $moderationBotRequest->message,
                allow: $allow,
                reason: $reason,
            ),
        );
    }

    /**
     * Parses JSON returned by the moderation model.
     *
     * Expected shape: {"allow": true|false, "reason": "short reason"}
     *
     * @param string $text Raw model output
     * @return ?array{allow: bool, reason: string} Parsed decision or null when invalid
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
     * Builds moderation prompt messages from rules, author context, and message text.
     *
     * @param string $message Message text to moderate
     * @param ?int $userId User id when moderating a user request
     * @param ?int $botId Bot id when moderating a bot message
     * @return list<Message> System and user messages for LLM
     * @throws HilosException When moderation rule lookup fails
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
     * Fetches message moderation rules from moderator prompt pieces.
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
