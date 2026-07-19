<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatFileUploadConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Pages\DTO\Main\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Pages\DTO\Main\FileUploadInitActionDTO;
use Demo\Chat\Pages\DTO\Main\LoginActionDTO;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\DTO\Main\RegisterActionDTO;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Database\Identity\IdentityType;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Fs\FsException;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * Handles main chat subscriptions, message submit actions, upload signals, and outbound moderation results.
 *
 * @property ChatAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::MESSAGE => MessageActionDTO::class,
        ChatSignalConstants::LOGIN => LoginActionDTO::class,
        ChatSignalConstants::REGISTER => RegisterActionDTO::class,
        ChatSignalConstants::FILE_UPLOAD_INIT => FileUploadInitActionDTO::class,
        ChatSignalConstants::ATTACHMENT_DRAFT_DELETE => AttachmentDraftDeleteActionDTO::class,
    ];

    // Sending a message requires a signed-in session: an anonymous visitor reads
    // the chat but is denied MESSAGE with a typed 401 (the frontend pre-disables
    // the composer and opens sign-in). LOGIN/REGISTER stay open — a guest needs
    // them to authenticate. Uploads ride the message it drafts, so the guard here
    // is enough (the anonymous composer never reaches an upload).
    public const array AUTH_ACTIONS = [
        ChatSignalConstants::MESSAGE,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::FRAME_BINARY => [],
        SignalTypeConstants::AGENT_SIGNAL => [
            ChatSignalConstants::MODERATION_RESULT => ModerationResultSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
    ];

    /**
     * Minimum wall-clock interval between upload-progress browser notifications when not forced.
     */
    private const float FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC = 0.3;

    /**
     * Generic login failure message shared by the unknown-email and wrong-password
     * paths so the response never discloses which account exists.
     */
    private const string INVALID_CREDENTIALS_MESSAGE = 'Invalid email or password';

    /**
     * Minimum registration password length. Length-only policy (no complexity
     * rule) — the shortest lever that keeps trivially weak passwords out.
     */
    private const int PASSWORD_MIN_LENGTH = 8;

    /**
     * Routes main-page actions to message, upload init, and attachment draft handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Main-page action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When a routed handler rejects the action
     * @throws HilosException When a routed handler exposes storage, settings, database, or runtime failure
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::MESSAGE:
                if (!$dto instanceof MessageActionDTO) {
                    throw new InvalidActionPayloadException($action, MessageActionDTO::class, $dto);
                }
                $this->handleMessage($dto);

                break;

            case ChatSignalConstants::LOGIN:
                if (!$dto instanceof LoginActionDTO) {
                    throw new InvalidActionPayloadException($action, LoginActionDTO::class, $dto);
                }
                $this->handleLogin($dto);

                break;

            case ChatSignalConstants::REGISTER:
                if (!$dto instanceof RegisterActionDTO) {
                    throw new InvalidActionPayloadException($action, RegisterActionDTO::class, $dto);
                }
                $this->handleRegister($dto);

                break;

            case ChatSignalConstants::FILE_UPLOAD_INIT:
                if (!$dto instanceof FileUploadInitActionDTO) {
                    throw new InvalidActionPayloadException($action, FileUploadInitActionDTO::class, $dto);
                }
                $this->handleFileUploadInit($dto);

                break;

            case ChatSignalConstants::ATTACHMENT_DRAFT_DELETE:
                if (!$dto instanceof AttachmentDraftDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, AttachmentDraftDeleteActionDTO::class, $dto);
                }
                $this->handleAttachmentDraftDelete($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Routes main-page agent signals to outbound moderation handlers.
     *
     * @param AgentSignalData $data Wrapped moderation result payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Moderation result signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this page
     * @throws LogicException When the moderation result payload type does not match the signal contract
     * @throws ValidationException When moderation rejects the message or is unavailable
     * @throws AgentException When moderation result does not match an active connection
     * @throws HilosException When moderation follow-up exposes storage, database, or runtime failure
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
                if (!$data->data instanceof ModerationResultSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::MODERATION_RESULT . ' payload must be ' . ModerationResultSignalData::class,
                    );
                }
                $this->handleTextModerationResult($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Delegates binary upload frames to the main-page upload handler.
     *
     * @param WebSocketFrameBinarySignalDTO $data Frame payload and connection id
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When upload runtime cleanup or progress sync fails
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $this->handleFileUploadBinaryFrame($data);
    }

    /**
     * Starts outbound moderation for a valid text or attachment-backed message submit.
     *
     * @param MessageActionDTO $dto Parsed message action payload
     * @throws EmptyValueException When message has no non-empty text and no attachments
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the user is rate-limited or already moderating
     * @throws HilosException When draft cleanup or runtime state writes fail
     */
    private function handleMessage(MessageActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (Hilos::$rt->selfConnection->userState === null) {
            throw new ItemNotFoundForUpdateException('User runtime state not found');
        }
        if (
            Hilos::$rt->selfConnection->outboundModerationPhase
            === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Another message is already being moderated');
        }
        if (
            microtime(true) - Hilos::$rt->selfConnection->userState->lastOutboundSubmittedAt
            < ChatUserState::MESSAGE_RATE_LIMIT_SECONDS - ChatUserState::MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS
        ) {
            throw new ValidationException('Message rate limit is active');
        }

        Hilos::$rt->attachmentDrafts->actions->deleteExpired();
        if (trim($dto->content) === '' && count(Hilos::$rt->selfConnection->attachmentDrafts) === 0) {
            throw new EmptyValueException('Message cannot be empty');
        }

        Hilos::$rt->selfConnection->userState->actions->recordOutboundSubmission();
        Hilos::$rt->selfConnection->actions->startOutboundModeration($dto->content);
    }

    /**
     * Verifies email+password against a `password` identity and promotes the session.
     *
     * A missing identity and a wrong password both fail with the same generic
     * message (no user enumeration); the unknown-email path still spends the
     * hash-verify cost so response time stays constant. A verified login rehashes
     * the stored hash when its parameters are outdated, then upgrades the live
     * anonymous session to the matched user through
     * {@see ChatAgent::authenticateSession()}.
     *
     * @param LoginActionDTO $dto Parsed login payload (email, password)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the credentials are invalid
     * @throws HilosException When identity lookup, rehash, or session promotion fails
     */
    private function handleLogin(LoginActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $email = strtolower($dto->email);
        $identity = $email !== ''
            ? Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email)
            : null;

        if ($identity === null) {
            // No account for this email: still spend the verify cost so the
            // response time does not disclose that the identifier is unknown.
            Hilos::$db->identities->verifyDummyPassword($dto->password);

            throw new ValidationException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        if (!$identity->verifyPassword($dto->password)) {
            throw new ValidationException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        $identity->rehashPasswordIfNeeded($dto->password);

        $userId = $identity->userId;
        if ($userId === null) {
            throw new ValidationException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        $this->agent->authenticateSession(Hilos::$rt->selfConnection->sessionToken, $userId);
    }

    /**
     * Registers a new email+password account and, by default, logs it in.
     *
     * Validates the submission (required fields, email format, password length,
     * confirmation match), then creates a durable user whose display name defaults
     * to the email local part and a `password` identity (identifier = lowercased
     * email, secret = bcrypt hash, verified = false) through the identity layer.
     * A taken email surfaces as {@see DuplicateValueException} ("email already
     * used") on the existing single-message action-error channel — registration
     * legitimately reveals a taken email, so there is no anti-enumeration concern
     * here (that is a login concern). On success the live anonymous session is
     * upgraded to the new user through {@see ChatAgent::authenticateSession()},
     * unless {@see self::autoLoginAfterRegister()} is overridden to defer it.
     *
     * @param RegisterActionDTO $dto Parsed register payload (email, password, confirmPassword)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws EmptyValueException When email or password fields are empty
     * @throws InvalidFormatException When the email is not a valid address
     * @throws ValidationException When the password is too short or the confirmation does not match
     * @throws DuplicateValueException When the email already has a password identity
     * @throws HilosException When user creation, identity creation, or session promotion fails
     */
    private function handleRegister(RegisterActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $email = strtolower($dto->email);
        if ($email === '' || $dto->password === '' || $dto->confirmPassword === '') {
            throw new EmptyValueException('Email and password are required');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidFormatException('Enter a valid email address');
        }
        if (strlen($dto->password) < self::PASSWORD_MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters');
        }
        if ($dto->password !== $dto->confirmPassword) {
            throw new ValidationException('Passwords do not match');
        }

        // Reject a taken email before creating the user so a duplicate does not
        // leave an orphan user row; the identity write also guards it uniquely.
        if (Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email) !== null) {
            throw new DuplicateValueException('email already used');
        }

        $user = Hilos::$db->users->actions->createWithName($this->displayNameFromEmail($email));
        $userId = (int)$user->id;

        Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->password);

        if ($this->autoLoginAfterRegister()) {
            $this->agent->authenticateSession(Hilos::$rt->selfConnection->sessionToken, $userId);
        }
    }

    /**
     * Whether a freshly registered user is logged in immediately.
     *
     * Default = auto-login: registration upgrades the current session to the new
     * user right away. A concrete project overrides this to return false to hold
     * for email verification (HIL-298) or route to an explicit login instead.
     *
     * @return bool True to auto-login the new user (default)
     */
    protected function autoLoginAfterRegister(): bool
    {
        return true;
    }

    /**
     * Derives the default display name from an email address.
     *
     * Uses the local part (everything before the first `@`); the name is not an
     * identifier and stays editable later in Profile.
     *
     * @param string $email Lowercased account email
     * @return string Display name (email local part, or the whole string when no `@`)
     */
    private function displayNameFromEmail(string $email): string
    {
        $atPosition = strpos($email, '@');

        return $atPosition === false ? $email : substr($email, 0, $atPosition);
    }

    /**
     * Deletes one uploaded attachment draft owned by this WebSocket connection.
     *
     * @param AttachmentDraftDeleteActionDTO $dto Parsed delete action payload
     * @throws EmptyValueException When draft id is empty
     * @throws ItemNotFoundForDeleteException When the requested draft does not belong to this session
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the current outbound submit is being moderated
     * @throws HilosException When draft deletion, filesystem cleanup, or runtime sync fails
     */
    private function handleAttachmentDraftDelete(AttachmentDraftDeleteActionDTO $dto): void
    {
        if ($dto->draftId === '') {
            throw new EmptyValueException('Attachment draft id cannot be empty');
        }
        if (trim($dto->draftId) === '') {
            throw new EmptyValueException('Attachment draft id cannot be trim-empty');
        }
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (Hilos::$rt->selfConnection->userState === null) {
            throw new ItemNotFoundForUpdateException('User runtime state not found');
        }
        if (
            Hilos::$rt->selfConnection->outboundModerationPhase
            === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Cannot delete attachment while message is being moderated');
        }

        if (!isset(Hilos::$rt->selfConnection->attachmentDrafts[$dto->draftId])) {
            throw new ItemNotFoundForDeleteException('Attachment draft not found for delete');
        }

        Hilos::$rt->attachmentDrafts[$dto->draftId]->actions->delete(deleteFiles: true);
    }

    /**
     * Validates upload metadata, reserves storage, and publishes RT ready state.
     *
     * @param FileUploadInitActionDTO $dto Parsed upload metadata
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the current submit is being moderated or upload metadata lacks a client id
     * @throws HilosException When settings lookup, quota checks, cleanup, or runtime state writes fail
     */
    private function handleFileUploadInit(FileUploadInitActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (
            Hilos::$rt->selfConnection->outboundModerationPhase
            === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Cannot upload attachments while message is being moderated');
        }

        if (Hilos::$rt->selfConnection->fileSessionUploadId !== null) {
            Hilos::$rt->selfConnection->actions->discardActiveBinaryUploadSessionAndProgressUi();
        }

        if (!$dto->isValid()) {
            if ($dto->clientUploadId === null) {
                throw new ValidationException('Invalid file metadata');
            }
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_INVALID_PAYLOAD,
                'Invalid file metadata',
            );

            return;
        }

        Hilos::$rt->attachmentDrafts->actions->deleteExpired();

        if ($dto->size > Hilos::$setting[ChatSettingsConstants::CHAT_ATTACHMENT_MAX_FILE_BYTES]->int()) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_SIZE_LIMIT,
                'File exceeds maximum allowed size',
            );

            return;
        }

        if (
            Hilos::$db->eventAttachments->sumPublishedAttachmentBytes()
            + Hilos::$rt->connections->sumActiveUploadReservedBytes()
            + Hilos::$rt->attachmentDrafts->sumDraftBytes()
            + $dto->size > Hilos::$setting[ChatSettingsConstants::CHAT_ATTACHMENT_MAX_TOTAL_BYTES]->int()
        ) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_TOTAL_LIMIT,
                'Total attachment storage limit would be exceeded',
            );

            return;
        }

        $normalizeBasename = FileSystemHelper::normalizeBasename($dto->filename);
        if (
            Hilos::$rt->connections->hasActiveUploadWithNormalizedFilename($normalizeBasename)
            || Hilos::$rt->attachmentDrafts->hasDraftWithNormalizedFilename($normalizeBasename)
            || Hilos::$db->eventAttachments->hasPublishedFileWithNormalizedFilename($normalizeBasename)
        ) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_DUPLICATE_FILENAME,
                'A file with this name already exists',
            );

            return;
        }

        try {
            $tmpIndex = Hilos::$fs->tmp->create();
        } catch (FsException $e) {
            $this->logAgentError("Cannot create tmp file: {$e->getMessage()}");
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR,
                'Cannot start upload',
            );

            return;
        }

        Hilos::$rt->selfConnection->actions->beginBinaryFileUpload(
            $tmpIndex,
            $dto->size,
            $tmpIndex,
            $dto->filename,
            $dto->mimeType,
            $dto->clientUploadId,
            $normalizeBasename,
            $dto->filename,
            $dto->size,
        );
    }

    /**
     * Handles a WebSocket binary frame for an active main-page upload session.
     *
     * Appends the chunk to tmp storage, updates runtime progress, records throttled browser markers,
     * and completes the upload when received bytes reach the declared size.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload and connection id
     * @throws HilosException When upload cleanup, progress sync, or draft creation fails
     */
    private function handleFileUploadBinaryFrame(WebSocketFrameBinarySignalDTO $data): void
    {
        if (Hilos::$rt->selfConnection === null) {
            return;
        }
        if (Hilos::$rt->selfConnection->fileSessionUploadId === null) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                Hilos::$rt->selfConnection->fileUploadClientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD,
                $this->fileUploadFailureMessage(
                    ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD,
                ),
            );

            return;
        }

        if (
            Hilos::$rt->selfConnection->fileSessionReceivedBytes + strlen($data->payload)
            > Hilos::$rt->selfConnection->fileSessionDeclaredSize
        ) {
            $this->logAgentError(
                'frame_binary: overflow acceptKey=' . Hilos::$rt->selfConnection->acceptKey
                . ' userId=' . Hilos::$rt->selfConnection->userId,
            );
            Hilos::$rt->selfConnection->actions->failActiveBinaryFileUpload(
                Hilos::$rt->selfConnection->fileUploadClientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_SIZE_OVERFLOW,
                $this->fileUploadFailureMessage(
                    ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_SIZE_OVERFLOW,
                ),
            );

            return;
        }

        try {
            $isUploadComplete = Hilos::$rt->selfConnection->actions->storeBinaryFileUploadChunk(
                $data->payload,
                self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC,
            );
        } catch (FsException $e) {
            $this->logAgentError(
                'frame_binary: tmp append failed acceptKey=' . Hilos::$rt->selfConnection->acceptKey
                . ' userId=' . Hilos::$rt->selfConnection->userId
                . ' error=' . $e->getMessage(),
            );
            Hilos::$rt->selfConnection->actions->failActiveBinaryFileUpload(
                Hilos::$rt->selfConnection->fileUploadClientUploadId,
                ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_WRITE_ERROR,
                $this->fileUploadFailureMessage(
                    ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_WRITE_ERROR,
                ),
            );

            return;
        }

        if ($isUploadComplete) {
            try {
                Hilos::$rt->selfConnection->actions->completeBinaryFileUpload();
            } catch (FsException $e) {
                $this->logAgentError("Cannot move tmp to quarantine: {$e->getMessage()}");
                Hilos::$rt->selfConnection->actions->failActiveBinaryFileUpload(
                    Hilos::$rt->selfConnection->fileUploadClientUploadId,
                    ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR,
                    $this->fileUploadFailureMessage(
                        ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR,
                    ),
                );

                return;
            }
        }
    }

    /**
     * Resolves a user-facing message for an upload failure code exposed through self-connection state.
     *
     * @param string $code One of ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_* constants
     * @return string User-facing upload failure message
     */
    private function fileUploadFailureMessage(string $code): string
    {
        return match ($code) {
            ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD => 'No active upload',
            ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_SIZE_OVERFLOW => 'Uploaded data exceeds declared size',
            ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_WRITE_ERROR => 'Cannot store upload data',
            ChatFileUploadConstants::FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR => 'Cannot finish upload',
            default => 'Upload failed',
        };
    }

    /**
     * Applies outbound moderation: publish approved text plus attachments or expose a retryable failure state.
     *
     * Stale connection results fail the agent-signal contract and never publish a message.
     *
     * @param ModerationResultSignalData $result Uploader connection key, allow flag, message body, reason
     * @throws ValidationException When moderation rejects the message or is unavailable
     * @throws AgentException When result does not match an active connection
     * @throws HilosException When attachment publishing, runtime writes, or event persistence fails
     */
    private function handleTextModerationResult(ModerationResultSignalData $result): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new AgentException('Moderation result connection is stale');
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE
                : ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED;
            Hilos::$rt->selfConnection->actions->failOutboundModeration(
                $phase,
                $reason,
            );

            throw new ValidationException($reason);
        }

        try {
            $attachments = Hilos::$rt->attachmentDrafts->actions->publishForConnection(Hilos::$rt->selfConnection);
        } catch (FsException $e) {
            $this->logAgentError("Failed to publish attachment drafts: {$e->getMessage()}");
            Hilos::$rt->selfConnection->actions->failOutboundModeration(
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                'attachment_publish_failed',
            );
            return;
        }

        if ($attachments === null) {
            Hilos::$rt->selfConnection->actions->failOutboundModeration(
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                'attachment_missing',
            );

            return;
        }

        Hilos::$rt->selfConnection->actions->clearOutboundModeration();
        Hilos::$db->events->actions->addMessage(
            $result->message,
            userId: Hilos::$rt->selfConnection->userId,
            attachments: $attachments,
        );
    }
}
