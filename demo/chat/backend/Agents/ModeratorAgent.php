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
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;

/**
 * Regular agent that discovers runtime user moderation requests and returns decisions.
 *
 * Uses async LLM moderation for outbound user messages.
 */
class ModeratorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

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
            try {
                $this->handleModerationClientResult($this->chatClient->getResult());
            } catch (AgentException $e) {
                $this->logAgentError($e->getMessage());
                $this->currentAcceptKey = null;
                $this->currentModerationUpdatedAt = 0;
                $this->currentModerationResultQueued = false;
            }
        }

        if ($this->currentAcceptKey !== null && $this->currentModerationResultQueued) {
            if (!isset(Hilos::$rt->connections[$this->currentAcceptKey])) {
                $this->currentAcceptKey = null;
                $this->currentModerationUpdatedAt = 0;
                $this->currentModerationResultQueued = false;
            } elseif (
                Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationPhase
                !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING
            ) {
                $this->currentAcceptKey = null;
                $this->currentModerationUpdatedAt = 0;
                $this->currentModerationResultQueued = false;
            } elseif (
                Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationUpdatedAt
                !== $this->currentModerationUpdatedAt
            ) {
                $this->currentAcceptKey = null;
                $this->currentModerationUpdatedAt = 0;
                $this->currentModerationResultQueued = false;
            }
        }

        if ($this->currentAcceptKey !== null || $this->chatClient->isBusy()) {
            return;
        }

        foreach (Hilos::$rt->connections as $connection) {
            if ($connection->outboundModerationPhase !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING) {
                continue;
            }

            $this->currentAcceptKey = $connection->acceptKey;
            $this->currentModerationUpdatedAt = $connection->outboundModerationUpdatedAt;

            $timeoutSec = ChatSettingsHelper::getModerationTimeoutSec();
            $options = new ChatGenerateOptions(
                model: ChatSettingsHelper::getModerationModel(),
                temperature: 0.0,
                timeoutSec: $timeoutSec > 0 ? $timeoutSec : LLMConstants::DEFAULT_TIMEOUT_SEC,
                maxTokens: 32,
            );

            try {
                $messages = $this->buildModerationMessages();
            } catch (AgentException $e) {
                $this->logAgentError($e->getMessage());
                $this->currentAcceptKey = null;
                $this->currentModerationUpdatedAt = 0;
                $this->currentModerationResultQueued = false;
                return;
            }

            if (!$this->chatClient->startGenerate($messages, $options)) {
                $this->logAgentError('Moderation request could not be started');
                try {
                    $this->sendCurrentUserModerationOutcome(false, self::REASON_SERVICE_UNAVAILABLE);
                } catch (AgentException $e) {
                    $this->logAgentError($e->getMessage());
                    $this->currentAcceptKey = null;
                    $this->currentModerationUpdatedAt = 0;
                    $this->currentModerationResultQueued = false;
                }
            }

            return;
        }
    }

    /**
     * Parses a completed LLM response and queues the active user moderation result.
     *
     * Orphan client results are logged and ignored.
     *
     * @param ?string $text Completed LLM output, or null when the async client failed
     * @throws AgentException When the result cannot be sent because request state is stale
     */
    private function handleModerationClientResult(?string $text): void
    {
        try {
            if ($this->currentAcceptKey === null) {
                throw new AgentException('Moderation client returned a result without an active request');
            }
            if ($text === null) {
                throw new AgentException('Moderation request failed without a model response');
            }

            $decision = ModerationDecision::fromModelOutput($text);
            if ($decision === null) {
                throw new AgentException('Moderation response did not contain a valid decision');
            }
        } catch (AgentException $e) {
            $this->logAgentError($e->getMessage());
            if ($this->currentAcceptKey === null) {
                return;
            }
            $this->sendCurrentUserModerationOutcome(
                false,
                $text === null ? self::REASON_SERVICE_UNAVAILABLE : self::REASON_UNKNOWN,
            );
            return;
        }

        $this->sendCurrentUserModerationOutcome(
            $decision->allow,
            $decision->reason !== '' ? $decision->reason : self::REASON_NONE,
        );
    }

    /**
     * Queues the current user moderation decision if runtime state still matches the active request snapshot.
     *
     * @param bool $allow Whether the submitted message may be published
     * @param string $reason Short moderation reason code
     * @throws AgentException When active request state is missing or stale
     */
    private function sendCurrentUserModerationOutcome(bool $allow, string $reason): void
    {
        if ($this->currentAcceptKey === null) {
            throw new AgentException('Cannot send moderation outcome without an active request');
        }

        $acceptKey = $this->currentAcceptKey;
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new AgentException('Cannot send moderation outcome for a stale connection');
        }
        if (
            Hilos::$rt->connections[$acceptKey]->outboundModerationPhase
            !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new AgentException('Cannot send moderation outcome for a non-checking connection');
        }
        if (
            Hilos::$rt->connections[$acceptKey]->outboundModerationUpdatedAt
            !== $this->currentModerationUpdatedAt
        ) {
            throw new AgentException('Cannot send moderation outcome for an outdated request');
        }

        $this->sendToAgent(
            ChatSignalConstants::MODERATION_RESULT,
            new ModerationResultSignalData(
                acceptKey: $acceptKey,
                userId: Hilos::$rt->connections[$acceptKey]->userId,
                message: Hilos::$rt->connections[$acceptKey]->outboundModerationMessage,
                allow: $allow,
                reason: $reason,
            ),
        );

        $this->currentModerationResultQueued = true;
    }

    /**
     * Builds moderation prompt messages from rules and the active runtime request.
     *
     * @return list<Message> System and user messages for LLM
     * @throws AgentException When there is no active runtime request
     * @throws HilosException When moderation rule lookup fails
     */
    private function buildModerationMessages(): array
    {
        if ($this->currentAcceptKey === null) {
            throw new AgentException('Cannot build moderation messages without an active request');
        }

        $acceptKey = $this->currentAcceptKey;
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new AgentException('Cannot build moderation messages for a stale connection');
        }

        $ruleLines = [];
        foreach (Hilos::$db->moderatorPromptPieces as $piece) {
            if ($piece->section !== ObjectModeratorPromptPiece::SECTION_MESSAGE_RULE) {
                continue;
            }

            $promptPiece = trim($piece->promptPiece);
            if ($promptPiece === '') {
                continue;
            }

            $ruleLines[] = "- {$promptPiece}";
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

        $userContentParts = [
            'User ID: ' . Hilos::$rt->connections[$acceptKey]->userId,
        ];
        if (Hilos::$rt->connections[$acceptKey]->outboundModerationMessage !== '') {
            $userContentParts[] = "Message:\n"
                . Hilos::$rt->connections[$acceptKey]->outboundModerationMessage;
        }
        foreach (Hilos::$rt->connections[$acceptKey]->attachmentDrafts as $draft) {
            $userContentParts[] = sprintf(
                'Attachment: name=%s, mime=%s, size=%d bytes.',
                $draft->originalFilename,
                $draft->mimeType,
                $draft->size,
            );
        }

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(Message::ROLE_USER, implode("\n\n", $userContentParts)),
        ];
    }
}
