<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\AttachmentDraft;
use Demo\Chat\Runtime\View\Item\Connection;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Utils\Helpers\JsonHelper;

/**
 * Regular agent that discovers runtime user moderation requests and returns decisions.
 *
 * Uses async LLM moderation unless user moderation is disabled.
 */
class ModeratorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

    private AsyncChatLLMInterface $chatClient;

    private ?string $currentAcceptKey = null;

    private int $currentUpdatedAt = 0;

    private bool $currentResultSent = false;

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
     * Discards any in-flight moderation marker with the worker.
     */
    public function onStop(): void
    {
    }

    /**
     * Advances async user moderation and starts one pending runtime connection when idle.
     *
     * @throws HilosException When moderation rule lookup fails
     */
    public function onTick(): void
    {
        $this->chatClient->tick(microtime(true) * 1000);

        if ($this->chatClient->hasResult()) {
            $this->handleModerationClientResult($this->chatClient->getResult());
        }

        if ($this->currentResultSent) {
            $this->clearCurrentModerationAfterDelivery();
        }

        if ($this->currentAcceptKey !== null || $this->chatClient->isBusy() || $this->chatClient->hasResult()) {
            return;
        }

        $this->startNextConnectionModeration();
    }

    /**
     * Starts moderation for the first runtime connection currently waiting for a user decision.
     *
     * @throws HilosException When moderation rule lookup fails
     */
    private function startNextConnectionModeration(): void
    {
        foreach (Hilos::$rt->connections as $connection) {
            if ($connection->outboundModerationPhase !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING) {
                continue;
            }

            $this->startConnectionModeration($connection);
            return;
        }
    }

    /**
     * Starts or bypasses moderation for one runtime connection.
     *
     * @param Connection $connection Runtime connection with an active outbound moderation state
     * @throws HilosException When moderation rule lookup fails
     */
    private function startConnectionModeration(Connection $connection): void
    {
        $this->currentAcceptKey = $connection->acceptKey;
        $this->currentUpdatedAt = $connection->outboundModerationUpdatedAt;
        $this->currentResultSent = false;

        if (!ChatSettingsHelper::getModerationUsers()) {
            $this->logAgentInfo("Moderation bypassed for user [userId={$connection->userId}] (disabled)");
            $this->currentResultSent = $this->sendCurrentUserModerationOutcome(true, 'disabled');
            return;
        }

        $messageLength = mb_strlen($connection->outboundModerationMessage);
        $this->logAgentInfo(
            "Moderation request started [userId={$connection->userId}; messageLen={$messageLength}]"
        );

        $timeoutSec = ChatSettingsHelper::getModerationTimeoutSec();
        $options = new ChatGenerateOptions(
            model: ChatSettingsHelper::getModerationModel(),
            temperature: 0.0,
            timeoutSec: $timeoutSec > 0 ? $timeoutSec : LLMConstants::DEFAULT_TIMEOUT_SEC,
            maxTokens: 32,
        );

        if (!$this->chatClient->startGenerate(
            $this->buildModerationMessages(
                $this->buildUserContentForModeration($connection),
                $connection->userId,
            ),
            $options,
        )) {
            $this->currentResultSent = $this->sendCurrentUserModerationOutcome(false, 'service_unavailable');
        }
    }

    /**
     * Converts the completed async LLM response into the current user moderation result signal.
     */
    private function handleModerationClientResult(?string $text): void
    {
        if ($this->currentAcceptKey === null) {
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

        if (!isset(Hilos::$rt->connections[$this->currentAcceptKey])) {
            $this->clearCurrentModeration();
            return;
        }

        $this->logAgentInfo(
            "Moderation request finished [userId="
            . Hilos::$rt->connections[$this->currentAcceptKey]->userId
            . '; decision='
            . ($allow ? 'allow' : 'block')
            . "; reason={$reason}]"
        );

        $this->currentResultSent = $this->sendCurrentUserModerationOutcome($allow, $reason);
    }

    /**
     * Sends the current user moderation decision when the runtime state still matches the in-flight request.
     */
    private function sendCurrentUserModerationOutcome(bool $allow, string $reason): bool
    {
        if (
            $this->currentAcceptKey === null
            || !isset(Hilos::$rt->connections[$this->currentAcceptKey])
            || Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationPhase
                !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING
            || Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationUpdatedAt
                !== $this->currentUpdatedAt
        ) {
            $this->clearCurrentModeration();
            return false;
        }

        $this->sendToAgent(
            ChatSignalConstants::MODERATION_RESULT,
            new ModerationResultSignalData(
                acceptKey: $this->currentAcceptKey,
                userId: Hilos::$rt->connections[$this->currentAcceptKey]->userId,
                message: Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationMessage,
                allow: $allow,
                reason: $reason,
            ),
        );

        return true;
    }

    /**
     * Clears the in-flight marker after ChatAgent applies the result to runtime state.
     */
    private function clearCurrentModerationAfterDelivery(): void
    {
        if (
            $this->currentAcceptKey === null
            || !isset(Hilos::$rt->connections[$this->currentAcceptKey])
            || Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationPhase
                !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING
            || Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationUpdatedAt
                !== $this->currentUpdatedAt
        ) {
            $this->clearCurrentModeration();
        }
    }

    private function clearCurrentModeration(): void
    {
        $this->currentAcceptKey = null;
        $this->currentUpdatedAt = 0;
        $this->currentResultSent = false;
    }

    /**
     * Builds user moderation content from submitted text and current connection-local drafts.
     *
     * @param Connection $connection Runtime connection to moderate
     */
    private function buildUserContentForModeration(Connection $connection): string
    {
        return $this->buildContentForModeration(
            $connection->outboundModerationMessage,
            ...$connection->attachmentDrafts,
        );
    }

    /**
     * Builds moderation prompt content from message text and attachment metadata.
     *
     * @param AttachmentDraft ...$drafts Attachment drafts
     */
    private function buildContentForModeration(string $message, AttachmentDraft ...$drafts): string
    {
        $parts = [];
        if ($message !== '') {
            $parts[] = "Message:\n{$message}";
        }
        foreach ($drafts as $draft) {
            $parts[] = sprintf(
                'Attachment: name=%s, mime=%s, size=%d bytes.',
                $draft->originalFilename,
                $draft->mimeType,
                $draft->size,
            );
        }

        return implode("\n\n", $parts);
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
     * @param int $userId User id for the current outbound request
     * @return list<Message> System and user messages for LLM
     * @throws HilosException When moderation rule lookup fails
     */
    private function buildModerationMessages(string $message, int $userId): array
    {
        $rules = $this->getMessageModerationRules();
        $rulesBlock = $rules === []
            ? "- Default policy: allow benign messages.\n- Block only explicit insults, threats, hate speech, sexual content, and obvious spam.\n- If uncertain, return allow=true."
            : implode("\n", array_map(static fn (string $rule): string => "- {$rule}", $rules));

        $systemContent = "Moderation. JSON only. Output: {\"allow\":true|false,\"reason\":\"ok|insult|threat|hate_speech|sexual|spam\"}\nRules:\n{$rulesBlock}";

        $userContent = "User ID: {$userId}\nMessage:\n{$message}";

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
