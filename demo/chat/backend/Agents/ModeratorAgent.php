<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Item\Connection;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
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

    private const string REASON_SERVICE_UNAVAILABLE = 'service_unavailable';
    private const string REASON_UNKNOWN = 'unknown';

    private AsyncChatLLMInterface $chatClient;

    private ?string $currentAcceptKey = null;

    /** Runtime moderation update timestamp observed when the LLM request started. */
    private int $currentModerationUpdatedAt = 0;

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
     * Cancels the active moderation request when its runtime connection changes.
     *
     * Unrelated RT sync updates are ignored; matching updates only reset stale requests.
     *
     * @param RtSyncUpdatedSignalData $data Runtime sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        if (
            $this->currentAcceptKey === null
            || $data->collectionKey !== RtChatContext::connections
            || $data->stateId !== $this->currentAcceptKey
        ) {
            return;
        }

        if (!isset(Hilos::$rt->connections[$this->currentAcceptKey])) {
            $this->resetCurrentModerationRequest();
            return;
        }

        if (
            Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationPhase
            !== Connection::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            $this->resetCurrentModerationRequest();
            return;
        }

        if (
            Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationUpdatedAt
            !== $this->currentModerationUpdatedAt
        ) {
            $this->resetCurrentModerationRequest();
        }
    }

    /**
     * Cancels the active moderation request when its runtime connection is removed.
     *
     * Unrelated RT sync deletes are ignored.
     *
     * @param RtSyncDeletedSignalData $data Runtime sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     */
    public function onSignalRtSyncDeleted(RtSyncDeletedSignalData $data, string $source, string $name): void
    {
        if (
            $this->currentAcceptKey !== null
            && $data->collectionKey === RtChatContext::connections
            && $data->stateId === $this->currentAcceptKey
        ) {
            $this->resetCurrentModerationRequest();
        }
    }

    /**
     * Advances one async moderation request and waits for RT sync to confirm result handling.
     *
     * @throws HilosException When moderation rule lookup fails
     */
    public function onTick(): void
    {
        $this->chatClient->tick(microtime(true) * 1000);

        if ($this->currentAcceptKey !== null) {
            if ($this->chatClient->isBusy()) {
                return;
            }

            if ($this->chatClient->hasResult()) {
                $text = $this->chatClient->getResult();
                $allow = false;
                $reason = self::REASON_UNKNOWN;

                try {
                    if ($text === null) {
                        throw new AgentException('Moderation request failed without a model response');
                    }

                    $decision = ModerationDecision::fromModelOutput($text);

                    $allow = $decision->allow;
                    $reason = $decision->reason;
                } catch (InvalidArgumentException $e) {
                    $this->logAgentError($e->getMessage());
                } catch (AgentException $e) {
                    $this->logAgentError($e->getMessage());
                    $reason = self::REASON_SERVICE_UNAVAILABLE;
                } finally {
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
                }
            }

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
                $this->resetCurrentModerationRequest();
                return;
            }

            if (!$this->chatClient->startGenerate($messages, $options)) {
                $this->logAgentError('Moderation request could not be started');
                $this->sendToAgent(
                    ChatSignalConstants::MODERATION_RESULT,
                    new ModerationResultSignalData(
                        acceptKey: $this->currentAcceptKey,
                        userId: Hilos::$rt->connections[$this->currentAcceptKey]->userId,
                        message: Hilos::$rt->connections[$this->currentAcceptKey]->outboundModerationMessage,
                        allow: false,
                        reason: self::REASON_SERVICE_UNAVAILABLE,
                    ),
                );
            }

            return;
        }
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

    /**
     * Cancels any stale LLM request and clears the active moderation snapshot.
     */
    private function resetCurrentModerationRequest(): void
    {
        $this->chatClient->reset();
        $this->currentAcceptKey = null;
        $this->currentModerationUpdatedAt = 0;
    }
}
