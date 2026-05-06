<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\Connection;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;

/**
 * Regular agent that discovers runtime user moderation requests and returns decisions.
 *
 * Uses async LLM moderation unless user moderation is disabled.
 */
class ModeratorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

    private const string REASON_DISABLED = 'disabled';
    private const string REASON_NONE = 'none';
    private const string REASON_SERVICE_UNAVAILABLE = 'service_unavailable';
    private const string REASON_UNKNOWN = 'unknown';

    private AsyncChatLLMInterface $chatClient;

    private ?string $currentAcceptKey = null;

    /** Runtime moderation update timestamp observed when the LLM request started. */
    private int $currentModerationUpdatedAt = 0;

    /** Whether the current result is queued and waiting for the page handler to mutate runtime state. */
    private bool $currentModerationResultQueued = false;

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
     * Cancels any in-flight moderation request owned by this worker.
     */
    public function onStop(): void
    {
        $this->chatClient->reset();
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
            if ($this->currentAcceptKey === null) {
                $this->chatClient->getResult();
                $this->logAgentError('Moderation client returned a result without an active request');
            } else {
                $this->handleModerationClientResult($this->chatClient->getResult());
            }
        }

        $this->clearQueuedModerationResultIfApplied();

        if ($this->currentAcceptKey !== null || $this->chatClient->isBusy()) {
            return;
        }

        foreach (Hilos::$rt->connections as $connection) {
            if ($connection->outboundModerationPhase !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING) {
                continue;
            }

            $this->currentAcceptKey = $connection->acceptKey;
            $this->currentModerationUpdatedAt = $connection->outboundModerationUpdatedAt;

            if (!ChatSettingsHelper::getModerationUsers()) {
                $this->sendCurrentUserModerationOutcome(true, self::REASON_DISABLED);
                return;
            }

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
                $this->logAgentError('Moderation request could not be started');
                $this->sendCurrentUserModerationOutcome(false, self::REASON_SERVICE_UNAVAILABLE);
            }

            return;
        }
    }

    /**
     * Parses a completed LLM response and queues the active user moderation result.
     *
     * Orphan client results are consumed by onTick() before this method is called.
     *
     * @param ?string $text Completed LLM output, or null when the async client failed
     */
    private function handleModerationClientResult(?string $text): void
    {
        $allow = false;
        $reason = $text === null ? self::REASON_SERVICE_UNAVAILABLE : self::REASON_UNKNOWN;

        if ($text === null) {
            $this->logAgentError('Moderation request failed without a model response');
        } else {
            $decision = ModerationDecision::fromModelOutput($text);
            if ($decision !== null) {
                $allow = $decision->allow;
                $reason = $decision->reason !== '' ? $decision->reason : self::REASON_NONE;
            } else {
                $this->logAgentError('Moderation response did not contain a valid decision');
            }
        }

        if (!$this->sendCurrentUserModerationOutcome($allow, $reason)) {
            $this->clearCurrentModerationRequest();
        }
    }

    /**
     * Queues the current user moderation decision if runtime state still matches the active request snapshot.
     *
     * @param bool $allow Whether the submitted message may be published
     * @param string $reason Short moderation reason code
     * @return bool Whether the result was queued
     */
    private function sendCurrentUserModerationOutcome(bool $allow, string $reason): bool
    {
        if ($this->currentAcceptKey === null) {
            return false;
        }
        if (!isset(Hilos::$rt->connections[$this->currentAcceptKey])) {
            return false;
        }
        if (
            Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationPhase
            !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            return false;
        }
        if (
            Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationUpdatedAt
            !== $this->currentModerationUpdatedAt
        ) {
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

        $this->currentModerationResultQueued = true;

        return true;
    }

    /**
     * Clears the local request snapshot after the queued result mutates its runtime request.
     */
    private function clearQueuedModerationResultIfApplied(): void
    {
        if ($this->currentAcceptKey === null) {
            return;
        }
        if (!$this->currentModerationResultQueued) {
            return;
        }

        if (!isset(Hilos::$rt->connections[$this->currentAcceptKey])) {
            $this->clearCurrentModerationRequest();
            return;
        }
        if (
            Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationPhase
            !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            $this->clearCurrentModerationRequest();
            return;
        }
        if (
            Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationUpdatedAt
            !== $this->currentModerationUpdatedAt
        ) {
            $this->clearCurrentModerationRequest();
        }
    }

    /**
     * Clears the active accept key and runtime update timestamp snapshot.
     */
    private function clearCurrentModerationRequest(): void
    {
        $this->currentAcceptKey = null;
        $this->currentModerationUpdatedAt = 0;
        $this->currentModerationResultQueued = false;
    }

    /**
     * Builds user moderation content from submitted text and current connection-local drafts.
     *
     * @param Connection $connection Runtime connection to moderate
     */
    private function buildUserContentForModeration(Connection $connection): string
    {
        $parts = [];
        if ($connection->outboundModerationMessage !== '') {
            $parts[] = "Message:\n{$connection->outboundModerationMessage}";
        }
        foreach ($connection->attachmentDrafts as $draft) {
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
     * Builds moderation prompt messages from rules, author context, and message text.
     *
     * @param string $message Message text to moderate
     * @param int $userId User id for the current outbound request
     * @return list<Message> System and user messages for LLM
     * @throws HilosException When moderation rule lookup fails
     */
    private function buildModerationMessages(string $message, int $userId): array
    {
        $ruleLines = [];
        foreach (Hilos::$db->moderatorPromptPieces as $piece) {
            if (
                $piece->section === ObjectModeratorPromptPiece::SECTION_MESSAGE_RULE
                && $piece->promptPiece !== ''
            ) {
                $ruleLines[] = "- {$piece->promptPiece}";
            }
        }

        $rulesBlock = $ruleLines === []
            ? implode("\n", [
                '- Default policy: allow benign messages.',
                '- Block only explicit insults, threats, hate speech, sexual content, and obvious spam.',
                '- If uncertain, return ' . ModerationDecision::KEY_ALLOW . '=true.',
            ])
            : implode("\n", $ruleLines);

        $systemContent = sprintf(
            "Moderation. JSON only. Output: {\"%s\":true|false,\"%s\":\"ok|insult|threat|hate_speech|sexual|spam\"}\nRules:\n%s",
            ModerationDecision::KEY_ALLOW,
            ModerationDecision::KEY_REASON,
            $rulesBlock,
        );

        $userContent = "User ID: {$userId}\nMessage:\n{$message}";

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(Message::ROLE_USER, $userContent),
        ];
    }
}
