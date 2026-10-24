<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Files\Image\ImageCopy;
use Hilos\Files\Image\ImageEngineInterface;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImageRenderException;
use Hilos\Files\Image\ImageVariant;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Upload\UploadMime;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\DTO\MessageImage;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\WiringRefusal;

/**
 * Monopolistic agent that discovers runtime user moderation requests and returns decisions.
 *
 * Uses async LLM moderation for outbound user messages, a JPEG copy of each picture
 * attached to an allowed message.
 */
final class ModeratorAgent extends AbstractAgent
{
    /** @var list<string> The prompt it moderates by, which the library agent owns */
    public const array READS_DB = [ChatDbContext::moderatorPromptPieces];

    public const string AGENT_TYPE = AgentType::MODERATOR;

    /**
     * Connections carry the moderation requests this agent answers, and the uploads a moderated
     * message names are the framework uploads agent's rows (HIL-144); it owns neither.
     *
     * @var list<string>
     */
    public const array READS_RT = [
        ChatRtContext::connections,
        HilosUpload::RT_COLLECTION,
    ];

    private const string REASON_SERVICE_UNAVAILABLE = 'service_unavailable';
    private const string REASON_UNKNOWN = 'unknown';
    private const string REQUEST_TYPE_MESSAGE = 'message';
    private const string REQUEST_TYPE_MESSAGE_PICTURE = 'message_picture';
    private const string PICTURE_COPY_NAME = 'chat_message_picture';
    private const int PICTURE_COPY_SIDE = 512;
    private const int MODERATION_MAX_TOKENS = 32;

    private LlmProfile $profile;

    private AsyncChatLLMInterface $chatClient;

    private LlmProfile $photoProfile;

    private AsyncChatLLMInterface $photoClient;

    private ImageEngineInterface $imageEngine;

    private string|false $previousMemoryLimit = false;

    private ?string $currentAcceptKey = null;

    private ?string $currentRequestType = null;

    private int $currentUserId = 0;

    private string $currentModerationValue = '';

    /** Runtime moderation update timestamp observed when the LLM request started. */
    private int $currentModerationUpdatedAt = 0;

    /** @var list<string> Client ids of the message's pictures that are not checked yet */
    private array $currentPictures = [];

    /** Client id of the picture currently with the vision client, or null. */
    private ?string $currentPicture = null;

    /**
     * Creates a moderator with an LLM client from the chat.moderation profile.
     *
     * @throws LLMConfigurationException When a moderation profile cannot be resolved
     * @throws EnvException When a profile's environment value is missing or invalid
     * @throws SettingException When a moderation profile setting cannot be read
     */
    public function __construct()
    {
        $this->profile = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_MODERATION);
        $this->chatClient = ClientFactory::createChatClientForProfile($this->profile);
        $this->photoProfile = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_PHOTO_MODERATION);
        $this->photoClient = ClientFactory::createChatClientForProfile($this->photoProfile);
    }

    /**
     * Reserves the rendering engine and the image memory budget until this agent stops.
     *
     * @throws LogicException When images is not declared with an images worker
     */
    public function onStart(): void
    {
        $this->imageEngine = ImageCopy::engine();
        $this->previousMemoryLimit = ini_set('memory_limit', ImagesAgent::MEMORY_LIMIT);
        if ($this->previousMemoryLimit === false) {
            $this->logAgentWarning('Cannot set image rendering memory_limit to ' . ImagesAgent::MEMORY_LIMIT);
        }
    }

    /**
     * Cancels any in-flight moderation request and restores the memory budget.
     */
    public function onStop(): void
    {
        $this->resetCurrentModerationRequest();
        if ($this->previousMemoryLimit !== false) {
            ini_set('memory_limit', $this->previousMemoryLimit);
            $this->previousMemoryLimit = false;
        }
    }

    /**
     * Cancels the active moderation request when its runtime connection changes.
     *
     * Unrelated RT sync updates are ignored; matching updates only reset stale requests.
     *
     * @param RtSyncUpdatedSignalData $data Runtime sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When a runtime row cannot be read
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
     * An allowed message that still has pictures does not send a verdict yet: the next
     * picture is drawn and asked of the vision client, and the first refusal ends the rest.
     *
     * @throws HilosException When moderation rule lookup fails
     */
    public function onTick(): void
    {
        try {
            $now = microtime(true) * TimeConstants::MS_PER_SECOND;
            $this->chatClient->tick($now);
            $this->photoClient->tick($now);
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
            $client = $this->activeClient();
            if ($client->isBusy()) {
                return;
            }

            if ($client->hasResult()) {
                $allow = false;
                $reason = self::REASON_UNKNOWN;

                try {
                    $text = $client->consumeResult();
                    $decision = ModerationDecision::fromModelOutput($text);

                    $allow = $decision->allow;
                    $reason = $decision->reason;
                } catch (InvalidArgumentException $e) {
                    $this->logAgentError($e->getMessage());
                } catch (AgentException|LLMException $e) {
                    $this->logAgentError($e->getMessage());
                    $reason = self::REASON_SERVICE_UNAVAILABLE;
                }

                if ($allow && $this->currentRequestType === self::REQUEST_TYPE_MESSAGE) {
                    $this->currentPictures = $this->picturesOfCurrentMessage();
                    if ($this->currentPictures !== []) {
                        $this->startNextPicture();
                        return;
                    }
                } elseif ($allow && $this->currentRequestType === self::REQUEST_TYPE_MESSAGE_PICTURE) {
                    if ($this->currentPictures !== []) {
                        $this->startNextPicture();
                        return;
                    }

                    $this->sendCurrentModerationResult(true, ModerationDecision::REASON_ALLOWED);
                    return;
                }

                $this->sendCurrentModerationResult($allow, $reason);
            }

            return;
        }

        foreach (Hilos::$rt->connections as $connection) {
            $message = $connection->outboundModerationMessage;
            if (
                $connection->outboundModerationPhase
                !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
                || $message === null
            ) {
                continue;
            }

            $this->startModerationRequest(
                $connection,
                self::REQUEST_TYPE_MESSAGE,
                $message,
                $connection->outboundModerationUpdatedAt,
            );

            return;
        }

    }

    /**
     * Starts one async LLM moderation request for a connection-local pending item.
     *
     * @param Connection $connection Runtime connection with a pending moderation item
     * @param string $requestType Active request kind
     * @param string $value Submitted message text
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

        try {
            $messages = $this->buildModerationMessages();
        } catch (AgentException $e) {
            $this->logAgentError($e->getMessage());
            $this->resetCurrentModerationRequest();
            return;
        }

        try {
            $candidateProfile = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_MODERATION);
            if (
                $candidateProfile->key !== $this->profile->key
                || $candidateProfile->provider !== $this->profile->provider
                || $candidateProfile->url !== $this->profile->url
                || $candidateProfile->model !== $this->profile->model
                || $candidateProfile->apiKey !== $this->profile->apiKey
                || $candidateProfile->timeoutSec !== $this->profile->timeoutSec
                || $candidateProfile->placement !== $this->profile->placement
            ) {
                $candidateClient = ClientFactory::createChatClientForProfile($candidateProfile);
                $this->chatClient = $candidateClient;
                $this->profile = $candidateProfile;
            }
            $options = new ChatGenerateOptions(
                model: $this->profile->model,
                timeoutSec: $this->profile->timeoutSec,
                maxTokens: self::MODERATION_MAX_TOKENS,
            );
            $this->chatClient->startGenerate($messages, $options);
        } catch (HilosException $e) {
            $this->logAgentError($e->getMessage());
            $this->sendCurrentModerationResult(false, self::REASON_SERVICE_UNAVAILABLE);
        }
    }

    /** @return AsyncChatLLMInterface Client for the request currently in flight */
    private function activeClient(): AsyncChatLLMInterface
    {
        return $this->currentRequestType === self::REQUEST_TYPE_MESSAGE_PICTURE
            ? $this->photoClient
            : $this->chatClient;
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
            default => throw new AgentException('Cannot build moderation messages for unknown request type'),
        };
    }

    /**
     * Builds outbound message moderation prompt messages.
     *
     * Attachment lines carry names, types and sizes. The picture bytes are checked
     * afterwards, once this text is allowed.
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
        // An upload gone since the message was sent is left out here; its publication refuses next.
        foreach (Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments as $clientUploadId) {
            $upload = Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId);
            if ($upload === null) {
                continue;
            }
            $userContentParts[] = sprintf(
                'Attachment: name=%s, mime=%s, size=%d bytes.',
                $upload->filename,
                $upload->detectedMimeType ?? $upload->mimeType,
                $upload->declaredSize,
            );
        }

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(Message::ROLE_USER, implode("\n\n", $userContentParts)),
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
     * Client ids of the message's pictures, in attach order.
     *
     * An upload that is already gone is left out. A file whose type does not start
     * with image/ is not a picture. The declared type is used when none was read
     * from the content.
     *
     * @return list<string> Client upload ids still to check
     * @throws HilosException When an upload row cannot be read
     */
    private function picturesOfCurrentMessage(): array
    {
        $acceptKey = $this->currentAcceptKey;
        if ($acceptKey === null || !isset(Hilos::$rt->connections[$acceptKey])) {
            return [];
        }

        $pictureIds = [];
        foreach (Hilos::$rt->connections[$acceptKey]->outboundModerationAttachments as $clientUploadId) {
            $upload = Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId);
            if ($upload === null) {
                continue;
            }
            $mimeType = UploadMime::normalize($upload->detectedMimeType ?? $upload->mimeType);
            if (str_starts_with($mimeType, 'image/')) {
                $pictureIds[] = $clientUploadId;
            }
        }

        return $pictureIds;
    }

    /**
     * Draws the next picture and asks the vision client, or sends the verdict when none remain.
     *
     * A missing, incomplete, or file-less upload is skipped. When every remaining picture
     * is skipped the message is allowed. A copy that cannot be drawn refuses the picture
     * as unreadable, and the copy itself is deleted before the method returns.
     *
     * @throws WiringRefusal When this process is not a declared reader of the uploads
     */
    private function startNextPicture(): void
    {
        $this->currentRequestType = self::REQUEST_TYPE_MESSAGE_PICTURE;

        while (true) {
            $pictureId = array_shift($this->currentPictures);
            if (!is_string($pictureId)) {
                $this->currentPicture = null;
                $this->sendCurrentModerationResult(true, ModerationDecision::REASON_ALLOWED);

                return;
            }
            $this->currentPicture = $pictureId;

            try {
                $acceptKey = $this->currentAcceptKey ?? throw new AgentException('Picture check has no connection');
                $upload = Hilos::$rt->hilosUploads->find($acceptKey, $pictureId);
                if ($upload === null || $upload->phase !== UploadPhase::COMPLETE || $upload->tmpIndex === null) {
                    continue;
                }

                $tmp = (Hilos::$fs ?? throw new AgentException('Temporary files are unavailable'))->getTmp();
                $copyIndex = null;
                try {
                    $copyIndex = $tmp->create();
                    ImageCopy::draw(
                        $this->imageEngine,
                        FsPath::read($tmp[$upload->tmpIndex]->getPath()),
                        new ImageVariant(
                            self::PICTURE_COPY_NAME,
                            self::PICTURE_COPY_SIDE,
                            self::PICTURE_COPY_SIDE,
                            ImageFit::CONTAIN,
                            ImageFormat::JPEG,
                        ),
                        $tmp[$copyIndex]->getPath(),
                    );
                    $copy = FsPath::read($tmp[$copyIndex]->getPath());
                } finally {
                    if ($copyIndex !== null) {
                        try {
                            $tmp[$copyIndex]->unlink();
                        } catch (FsException $unlinkError) {
                            $this->logAgentError(
                                'Cannot delete the picture copy ' . $copyIndex . ': ' . $unlinkError->getMessage(),
                            );
                        }
                    }
                }

                $this->photoClient->startGenerate(
                    $this->buildMessagePictureModerationMessages($upload->filename, $copy),
                    new ChatGenerateOptions(
                        model: $this->photoProfile->model,
                        timeoutSec: $this->photoProfile->timeoutSec,
                        maxTokens: self::MODERATION_MAX_TOKENS,
                    ),
                );

                return;
            } catch (ImageRenderException $e) {
                $this->logAgentWarning('Cannot draw a copy of picture ' . $pictureId . ': ' . $e->getMessage());
                $this->sendCurrentModerationResult(false, ModerationDecision::REASON_UNREADABLE);

                return;
            } catch (FsException $e) {
                $this->logAgentError('Cannot read picture ' . $pictureId . ': ' . $e->getMessage());
                $this->sendCurrentModerationResult(false, self::REASON_SERVICE_UNAVAILABLE);

                return;
            } catch (WiringRefusal $refusal) {
                throw $refusal;
            } catch (HilosException $e) {
                $this->logAgentError($e->getMessage());
                $this->sendCurrentModerationResult(false, self::REASON_SERVICE_UNAVAILABLE);

                return;
            }
        }
    }

    /**
     * Builds the image-bearing prompt for one picture attached to the message.
     *
     * The message text is not included. The image is the JPEG copy, not the original.
     *
     * @param string $filename File name of the upload
     * @param string $jpeg Encoded JPEG copy
     * @return list<Message> System rule and the picture
     * @throws HilosException When the picture rules cannot be read
     */
    private function buildMessagePictureModerationMessages(string $filename, string $jpeg): array
    {
        $rulesBlock = $this->buildRuleBlock(
            ObjectModeratorPromptPiece::SECTION_MESSAGE_PICTURE_RULE,
            [
                '- Default policy: allow ordinary pictures, screenshots and drawings.',
                '- Block only nudity or sexual content, graphic violence, and hate symbols.',
                '- If uncertain, return ' . ModerationDecision::KEY_ALLOW . '=true.',
            ],
        );
        $systemContent = sprintf(implode("\n", [
            'Moderation of a picture attached to a chat message. JSON only. Output: {"%s":true|false,"%s":"ok|nudity|violence|hate"}',
            'Rules:',
            '%s',
        ]), ModerationDecision::KEY_ALLOW, ModerationDecision::KEY_REASON, $rulesBlock);

        return [
            new Message(Message::ROLE_SYSTEM, $systemContent),
            new Message(
                Message::ROLE_USER,
                implode("\n\n", [
                    'User ID: ' . $this->currentUserId,
                    'Picture: name=' . $filename,
                ]),
                [new MessageImage(ImageFormat::JPEG->value, base64_encode($jpeg))],
            ),
        ];
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

        if (
            !$allow
            && (
                $this->currentRequestType === self::REQUEST_TYPE_MESSAGE
                || $this->currentRequestType === self::REQUEST_TYPE_MESSAGE_PICTURE
            )
        ) {
            $detail = "Moderation refusal for request type '{$this->currentRequestType}': reason={$reason}";
            if ($this->currentRequestType === self::REQUEST_TYPE_MESSAGE_PICTURE) {
                $detail .= ", picture={$this->currentPicture}";
            }
            $this->logAgentInfo($detail);
        }

        switch ($this->currentRequestType) {
            case self::REQUEST_TYPE_MESSAGE:
            case self::REQUEST_TYPE_MESSAGE_PICTURE:
                $this->sendToAgent(
                    ChatSignalConstants::MODERATION_RESULT,
                    new ModerationResultSignalData(
                        acceptKey: $this->currentAcceptKey,
                        userId: $this->currentUserId,
                        message: $this->currentModerationValue,
                        allow: $allow,
                        reason: $reason,
                        picture: !$allow && $this->currentRequestType === self::REQUEST_TYPE_MESSAGE_PICTURE
                            ? $this->currentPicture
                            : null,
                    ),
                );
                return;

        }
    }

    /**
     * Checks that the active request still points to the same runtime state snapshot.
     *
     * @throws HilosException When a runtime row cannot be read
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

        $messageStillChecking = $connection->userId === $this->currentUserId
            && $connection->outboundModerationPhase
                === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
            && $connection->outboundModerationMessage === $this->currentModerationValue
            && $connection->outboundModerationUpdatedAt === $this->currentModerationUpdatedAt;

        return match ($this->currentRequestType) {
            self::REQUEST_TYPE_MESSAGE => $messageStillChecking,
            self::REQUEST_TYPE_MESSAGE_PICTURE => $messageStillChecking
                && $this->currentPicture !== null
                && in_array($this->currentPicture, $connection->outboundModerationAttachments, true),
            default => false,
        };
    }

    /**
     * Cancels any stale LLM request and clears the active moderation snapshot.
     */
    private function resetCurrentModerationRequest(): void
    {
        if ($this->currentRequestType === null) {
            $this->chatClient->reset();
            $this->photoClient->reset();
        } else {
            $this->activeClient()->reset();
        }
        $this->currentAcceptKey = null;
        $this->currentRequestType = null;
        $this->currentUserId = 0;
        $this->currentModerationValue = '';
        $this->currentModerationUpdatedAt = 0;
        $this->currentPictures = [];
        $this->currentPicture = null;
    }
}
