<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Constants\EnvConstants;
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
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Routing\LlmProfile;

/**
 * Regular agent that discovers runtime user moderation requests and returns decisions.
 *
 * Uses async LLM moderation for outbound user messages and user-initiated display names.
 */
final class ModeratorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::MODERATOR;

    private const string REASON_SERVICE_UNAVAILABLE = 'service_unavailable';
    private const string REASON_UNKNOWN = 'unknown';
    private const string REQUEST_TYPE_MESSAGE = 'message';
    private const string REQUEST_TYPE_RENAME = 'rename';

    private LlmProfile $profile;

    private AsyncChatLLMInterface $chatClient;

    private ?string $currentAcceptKey = null;

    private ?string $currentRequestType = null;

    private int $currentUserId = 0;

    private string $currentModerationValue = '';

    /** Runtime moderation update timestamp observed when the LLM request started. */
    private int $currentModerationUpdatedAt = 0;

    /**
     * Creates a moderator with an LLM client from the chat.moderation profile.
     *
     * @throws LLMConfigurationException When the chat.moderation profile cannot be resolved
     */
    public function __construct()
    {
        $this->profile = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_MODERATION);
        $this->chatClient = Hilos::$env[EnvConstants::APP_ENV] === 'test'
            ? new TestModerationChatClient()
            : ClientFactory::createChatClientForProfile($this->profile);
    }

    /**
     * Cancels any in-flight moderation request owned by this worker.
     */
    public function onStop(): void
    {
        $this->resetCurrentModerationRequest();
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
            || $data->collectionKey !== ChatRtContext::connections
            || $data->stateId !== $this->currentAcceptKey
        ) {
            return;
        }

        if (!$this->activeRequestStillMatchesRuntime()) {
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
            && $data->collectionKey === ChatRtContext::connections
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
        try {
            $this->chatClient->tick(microtime(true) * 1000);
        } catch (LLMException $e) {
            $this->logAgentError($e->getMessage());
            if ($this->currentAcceptKey !== null) {
                $this->sendCurrentModerationResult(false, self::REASON_SERVICE_UNAVAILABLE);
            } else {
                $this->resetCurrentModerationRequest();
            }
            return;
        }

        if ($this->currentAcceptKey !== null) {
            if ($this->chatClient->isBusy()) {
                return;
            }

            if ($this->chatClient->hasResult()) {
                $allow = false;
                $reason = self::REASON_UNKNOWN;

                try {
                    $text = $this->chatClient->consumeResult();
                    $decision = ModerationDecision::fromModelOutput($text);

                    $allow = $decision->allow;
                    $reason = $decision->reason;
                } catch (InvalidArgumentException $e) {
                    $this->logAgentError($e->getMessage());
                } catch (AgentException|LLMException $e) {
                    $this->logAgentError($e->getMessage());
                    $reason = self::REASON_SERVICE_UNAVAILABLE;
                } finally {
                    $this->sendCurrentModerationResult($allow, $reason);
                }
            }

            return;
        }

        foreach (Hilos::$rt->connections as $connection) {
            if (
                $connection->outboundModerationPhase
                !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
            ) {
                continue;
            }

            $this->startModerationRequest(
                $connection,
                self::REQUEST_TYPE_MESSAGE,
                $connection->outboundModerationMessage,
                $connection->outboundModerationUpdatedAt,
            );

            return;
        }

        foreach (Hilos::$rt->connections as $connection) {
            if (
                $connection->renameModerationPhase
                !== ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
            ) {
                continue;
            }

            $this->startModerationRequest(
                $connection,
                self::REQUEST_TYPE_RENAME,
                $connection->renameModerationName,
                $connection->renameModerationUpdatedAt,
            );

            return;
        }
    }

    /**
     * Starts one async LLM moderation request for a connection-local pending item.
     *
     * @param Connection $connection Runtime connection with a pending moderation item
     * @param string $requestType Active request kind
     * @param string $value Submitted message text or requested display name
     * @param int $updatedAt Runtime update timestamp observed at start
     * @throws HilosException When moderation rule lookup fails
     */
    private function startModerationRequest(
        Connection $connection,
        string $requestType,
        string $value,
        int $updatedAt,
    ): void {
        $this->currentAcceptKey = $connection->acceptKey;
        $this->currentRequestType = $requestType;
        $this->currentUserId = $connection->userId ?? 0;
        $this->currentModerationValue = $value;
        $this->currentModerationUpdatedAt = $updatedAt;

        $options = new ChatGenerateOptions(
            model: $this->profile->model,
            temperature: 0.0,
            timeoutSec: $this->profile->timeoutSec,
            maxTokens: 32,
        );

        try {
            $messages = $this->buildModerationMessages();
        } catch (AgentException $e) {
            $this->logAgentError($e->getMessage());
            $this->resetCurrentModerationRequest();
            return;
        }

        try {
            $this->chatClient->startGenerate($messages, $options);
        } catch (LLMException $e) {
            $this->logAgentError($e->getMessage());
            $this->sendCurrentModerationResult(false, self::REASON_SERVICE_UNAVAILABLE);
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
        if ($this->currentAcceptKey === null || $this->currentRequestType === null) {
            throw new AgentException('Cannot build moderation messages without an active request');
        }

        return match ($this->currentRequestType) {
            self::REQUEST_TYPE_MESSAGE => $this->buildMessageModerationMessages($this->currentAcceptKey),
            self::REQUEST_TYPE_RENAME => $this->buildRenameModerationMessages(),
            default => throw new AgentException('Cannot build moderation messages for unknown request type'),
        };
    }

    /**
     * Builds outbound message moderation prompt messages.
     *
     * @return list<Message> System and user messages for LLM
     * @throws AgentException When the active connection is stale
     * @throws HilosException When moderation rule lookup fails
     */
    private function buildMessageModerationMessages(string $acceptKey): array
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new AgentException('Cannot build moderation messages for a stale connection');
        }

        $rulesBlock = $this->buildRuleBlock(
            ObjectModeratorPromptPiece::SECTION_MESSAGE_RULE,
            [
                '- Default policy: allow benign messages.',
                '- Block only explicit insults, threats, hate speech, sexual content, and obvious spam.',
                '- If uncertain, return ' . ModerationDecision::KEY_ALLOW . '=true.',
            ],
        );

        $systemContent = sprintf(implode("\n", [
            'Moderation. JSON only. Output: {"%s":true|false,"%s":"ok|insult|threat|hate_speech|sexual|spam"}',
            'Rules:',
            '%s',
            'Be lenient: allow unless the text is unmistakably abusive. '
                . 'Attachments — their names, types and sizes — are never a reason to block.',
        ]),
            ModerationDecision::KEY_ALLOW,
            ModerationDecision::KEY_REASON,
            $rulesBlock,
        );

        $userContentParts = [
            'User ID: ' . $this->currentUserId,
        ];
        if ($this->currentModerationValue !== '') {
            $userContentParts[] = "Message:\n"
                . $this->currentModerationValue;
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
     * Builds user display-name moderation prompt messages.
     *
     * @return list<Message> System and user messages for LLM
     * @throws HilosException When moderation rule lookup fails
     */
    private function buildRenameModerationMessages(): array
    {
        $rulesBlock = $this->buildRuleBlock(
            ObjectModeratorPromptPiece::SECTION_NAME_RULE,
            [
                '- Default policy: allow benign display names.',
                '- Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
                '- If uncertain, return ' . ModerationDecision::KEY_ALLOW . '=true.',
            ],
        );

        $systemContent = sprintf(implode("\n", [
            'Display name moderation. JSON only. Output: '
                . '{"%s":true|false,"%s":"ok|insult|threat|hate_speech|sexual|spam|impersonation"}',
            'Rules:',
            '%s',
        ]),
            ModerationDecision::KEY_ALLOW,
            ModerationDecision::KEY_REASON,
            $rulesBlock,
        );

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(Message::ROLE_USER, implode("\n\n", [
                'User ID: ' . $this->currentUserId,
                "Requested display name:\n" . $this->currentModerationValue,
            ])),
        ];
    }

    /**
     * Builds a rules block from stored moderator prompt pieces or fallback lines.
     *
     * @param string $section Moderator prompt-piece section
     * @param list<string> $fallbackLines Default prompt lines when no rules are configured
     * @return string Rule block for the system prompt
     */
    private function buildRuleBlock(string $section, array $fallbackLines): string
    {
        $ruleLines = [];
        foreach (Hilos::$db->moderatorPromptPieces as $piece) {
            if ($piece->section !== $section) {
                continue;
            }

            $promptPiece = trim($piece->promptPiece);
            if ($promptPiece === '') {
                continue;
            }

            $ruleLines[] = "- {$promptPiece}";
        }

        return $ruleLines === [] ? implode("\n", $fallbackLines) : implode("\n", $ruleLines);
    }

    /**
     * Sends the active request result using the matching signal contract.
     *
     * @param bool $allow Whether the moderated value is allowed
     * @param string $reason Moderation reason
     */
    private function sendCurrentModerationResult(bool $allow, string $reason): void
    {
        if ($this->currentAcceptKey === null || $this->currentRequestType === null) {
            return;
        }

        switch ($this->currentRequestType) {
            case self::REQUEST_TYPE_MESSAGE:
                $this->sendToAgent(
                    ChatSignalConstants::MODERATION_RESULT,
                    new ModerationResultSignalData(
                        acceptKey: $this->currentAcceptKey,
                        userId: $this->currentUserId,
                        message: $this->currentModerationValue,
                        allow: $allow,
                        reason: $reason,
                    ),
                );
                return;

            case self::REQUEST_TYPE_RENAME:
                $this->sendToAgent(
                    ChatSignalConstants::RENAME_MODERATION_RESULT,
                    new RenameModerationResultSignalData(
                        acceptKey: $this->currentAcceptKey,
                        userId: $this->currentUserId,
                        newName: $this->currentModerationValue,
                        allow: $allow,
                        reason: $reason,
                    ),
                );
                return;
        }
    }

    /**
     * Checks that the active request still points to the same runtime state snapshot.
     */
    private function activeRequestStillMatchesRuntime(): bool
    {
        if ($this->currentAcceptKey === null || $this->currentRequestType === null) {
            return false;
        }

        if (!isset(Hilos::$rt->connections[$this->currentAcceptKey])) {
            return false;
        }

        $connection = Hilos::$rt->connections[$this->currentAcceptKey];

        return match ($this->currentRequestType) {
            self::REQUEST_TYPE_MESSAGE =>
                $connection->userId === $this->currentUserId
                && $connection->outboundModerationPhase
                    === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
                && $connection->outboundModerationMessage === $this->currentModerationValue
                && $connection->outboundModerationUpdatedAt === $this->currentModerationUpdatedAt,
            self::REQUEST_TYPE_RENAME =>
                $connection->userId === $this->currentUserId
                && $connection->renameModerationPhase
                    === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
                && $connection->renameModerationName === $this->currentModerationValue
                && $connection->renameModerationUpdatedAt === $this->currentModerationUpdatedAt,
            default => false,
        };
    }

    /**
     * Cancels any stale LLM request and clears the active moderation snapshot.
     */
    private function resetCurrentModerationRequest(): void
    {
        $this->chatClient->reset();
        $this->currentAcceptKey = null;
        $this->currentRequestType = null;
        $this->currentUserId = 0;
        $this->currentModerationValue = '';
        $this->currentModerationUpdatedAt = 0;
    }
}
