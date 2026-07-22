<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatFileUploadConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Constants\PasswordPolicy;
use Demo\Chat\Pages\DTO\Main\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmPasswordResetActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmMagicLinkActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmRegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmSmsCodeActionDTO;
use Demo\Chat\Pages\DTO\Main\FileUploadInitActionDTO;
use Demo\Chat\Pages\DTO\Main\LinkOAuthAfterReauthActionDTO;
use Demo\Chat\Pages\DTO\Main\LoginActionDTO;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\DTO\Main\OAuthCallbackActionDTO;
use Demo\Chat\Pages\DTO\Main\OAuthStartActionDTO;
use Demo\Chat\Pages\DTO\Main\PasskeyLoginConfirmActionDTO;
use Demo\Chat\Pages\DTO\Main\PasskeyLoginOptionsActionDTO;
use Demo\Chat\Pages\DTO\Main\PasskeyRegisterConfirmActionDTO;
use Demo\Chat\Pages\DTO\Main\PasskeyRegisterOptionsActionDTO;
use Demo\Chat\Pages\DTO\Main\RegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\RequestMagicLinkActionDTO;
use Demo\Chat\Pages\DTO\Main\RequestPasswordResetActionDTO;
use Demo\Chat\Pages\DTO\Main\RequestRegisterConfirmActionDTO;
use Demo\Chat\Pages\DTO\Main\RequestSmsCodeActionDTO;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\PasskeyOptionsSignalData;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Hilos\Auth\OAuth\DTO\OAuthAuthorizeSignalData;
use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\AttestationVerifier;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\Exception\WebAuthnChallengeException;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\WebAuthnChallengeSigner;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Runtime\State\Collection\OAuthPendingLogins;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Database\Verification\VerificationType;
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
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Object\Item\PasskeyCredential;
use Hilos\Database\View\Item\Identity;
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
        ChatSignalConstants::REQUEST_PASSWORD_RESET => RequestPasswordResetActionDTO::class,
        ChatSignalConstants::CONFIRM_PASSWORD_RESET => ConfirmPasswordResetActionDTO::class,
        ChatSignalConstants::REQUEST_SMS_CODE => RequestSmsCodeActionDTO::class,
        ChatSignalConstants::CONFIRM_SMS_CODE => ConfirmSmsCodeActionDTO::class,
        ChatSignalConstants::REQUEST_MAGIC_LINK => RequestMagicLinkActionDTO::class,
        ChatSignalConstants::CONFIRM_MAGIC_LINK => ConfirmMagicLinkActionDTO::class,
        ChatSignalConstants::REQUEST_REGISTER_CONFIRM => RequestRegisterConfirmActionDTO::class,
        ChatSignalConstants::CONFIRM_REGISTER => ConfirmRegisterActionDTO::class,
        ChatSignalConstants::FILE_UPLOAD_INIT => FileUploadInitActionDTO::class,
        ChatSignalConstants::ATTACHMENT_DRAFT_DELETE => AttachmentDraftDeleteActionDTO::class,
        ChatSignalConstants::OAUTH_START => OAuthStartActionDTO::class,
        ChatSignalConstants::OAUTH_CALLBACK => OAuthCallbackActionDTO::class,
        ChatSignalConstants::LINK_OAUTH_AFTER_REAUTH => LinkOAuthAfterReauthActionDTO::class,
        ChatSignalConstants::PASSKEY_REGISTER_OPTIONS => PasskeyRegisterOptionsActionDTO::class,
        ChatSignalConstants::PASSKEY_REGISTER_CONFIRM => PasskeyRegisterConfirmActionDTO::class,
        ChatSignalConstants::PASSKEY_LOGIN_OPTIONS => PasskeyLoginOptionsActionDTO::class,
        ChatSignalConstants::PASSKEY_LOGIN_CONFIRM => PasskeyLoginConfirmActionDTO::class,
    ];

    // Sending a message requires a signed-in session: an anonymous visitor reads
    // the chat but is denied MESSAGE with a typed 401 (the frontend pre-disables
    // the composer and opens sign-in). LOGIN/REGISTER and the password-recovery
    // pair stay open — a guest needs them to authenticate or recover. The
    // register-confirm pair is authenticated: it verifies the signed-in user's
    // own email, so it must never run for an anonymous session. The passkey
    // register pair is authenticated too: a passkey is added to an already
    // signed-in account (passkey login stays open — that is how a guest signs in).
    // Uploads ride the message they draft, so the guard here is enough.
    public const array AUTH_ACTIONS = [
        ChatSignalConstants::MESSAGE,
        ChatSignalConstants::REQUEST_REGISTER_CONFIRM,
        ChatSignalConstants::CONFIRM_REGISTER,
        ChatSignalConstants::LINK_OAUTH_AFTER_REAUTH,
        ChatSignalConstants::PASSKEY_REGISTER_OPTIONS,
        ChatSignalConstants::PASSKEY_REGISTER_CONFIRM,
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
     * Generic failure message for every verification-code path (unknown, expired,
     * wrong, exhausted) so a response never discloses which case occurred.
     */
    private const string INVALID_CODE_MESSAGE = 'Invalid or expired code';

    /**
     * Generic failure message for a malformed phone number. A format error is not
     * an enumeration concern (it reveals nothing about who has an account), so the
     * SMS-request path can surface it directly rather than answering generically.
     */
    private const string INVALID_PHONE_MESSAGE = 'Enter a valid phone number';

    /**
     * Generic failure message for an OAuth account link (HIL-282). A bad, expired,
     * foreign-owned, or already-linked token all answer the same way so the wire
     * discloses nothing about the matched account beyond the collision it implies.
     */
    private const string INVALID_LINK_MESSAGE = 'This account link is invalid or has expired';

    /**
     * Generic failure message for the passkey login path (HIL-284). A bad
     * challenge token, an unknown credential, a failed assertion, or a
     * counter regression all answer the same way so the wire discloses nothing
     * about which account exists or which check failed.
     */
    private const string INVALID_PASSKEY_MESSAGE = 'Passkey sign-in could not be completed';

    /**
     * ES256 (ECDSA over P-256, SHA-256) COSE algorithm identifier — the single
     * passkey algorithm this demo requests and verifies.
     */
    private const int PASSKEY_ALG_ES256 = -7;

    /**
     * Domain-separation prefix for the derived WebAuthn user handle (HIL-284).
     * See {@see self::passkeyUserHandle()} for why the handle is derived rather
     * than random.
     */
    private const string PASSKEY_USER_HANDLE_PREFIX = 'passkey-user-handle:';

    /**
     * Routes main-page actions to message, upload init, and attachment draft handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Main-page action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When a routed handler rejects the action
     * @throws \Random\RandomException When issuing a verification code cannot draw from the CSPRNG
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

            case ChatSignalConstants::REQUEST_PASSWORD_RESET:
                if (!$dto instanceof RequestPasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestPasswordResetActionDTO::class, $dto);
                }
                $this->handleRequestPasswordReset($dto);

                break;

            case ChatSignalConstants::CONFIRM_PASSWORD_RESET:
                if (!$dto instanceof ConfirmPasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmPasswordResetActionDTO::class, $dto);
                }
                $this->handleConfirmPasswordReset($dto);

                break;

            case ChatSignalConstants::REQUEST_SMS_CODE:
                if (!$dto instanceof RequestSmsCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestSmsCodeActionDTO::class, $dto);
                }
                $this->handleRequestSmsCode($dto);

                break;

            case ChatSignalConstants::CONFIRM_SMS_CODE:
                if (!$dto instanceof ConfirmSmsCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmSmsCodeActionDTO::class, $dto);
                }
                $this->handleConfirmSmsCode($dto);

                break;

            case ChatSignalConstants::REQUEST_MAGIC_LINK:
                if (!$dto instanceof RequestMagicLinkActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestMagicLinkActionDTO::class, $dto);
                }
                $this->handleRequestMagicLink($dto);

                break;

            case ChatSignalConstants::CONFIRM_MAGIC_LINK:
                if (!$dto instanceof ConfirmMagicLinkActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmMagicLinkActionDTO::class, $dto);
                }
                $this->handleConfirmMagicLink($dto);

                break;

            case ChatSignalConstants::REQUEST_REGISTER_CONFIRM:
                if (!$dto instanceof RequestRegisterConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestRegisterConfirmActionDTO::class, $dto);
                }
                $this->handleRequestRegisterConfirm($dto);

                break;

            case ChatSignalConstants::CONFIRM_REGISTER:
                if (!$dto instanceof ConfirmRegisterActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmRegisterActionDTO::class, $dto);
                }
                $this->handleConfirmRegister($dto);

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

            case ChatSignalConstants::OAUTH_START:
                if (!$dto instanceof OAuthStartActionDTO) {
                    throw new InvalidActionPayloadException($action, OAuthStartActionDTO::class, $dto);
                }
                $this->handleOauthStart($dto);

                break;

            case ChatSignalConstants::OAUTH_CALLBACK:
                if (!$dto instanceof OAuthCallbackActionDTO) {
                    throw new InvalidActionPayloadException($action, OAuthCallbackActionDTO::class, $dto);
                }
                $this->handleOauthCallback($dto);

                break;

            case ChatSignalConstants::LINK_OAUTH_AFTER_REAUTH:
                if (!$dto instanceof LinkOAuthAfterReauthActionDTO) {
                    throw new InvalidActionPayloadException($action, LinkOAuthAfterReauthActionDTO::class, $dto);
                }
                $this->handleLinkOAuthAfterReauth($dto);

                break;

            case ChatSignalConstants::PASSKEY_REGISTER_OPTIONS:
                if (!$dto instanceof PasskeyRegisterOptionsActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyRegisterOptionsActionDTO::class, $dto);
                }
                $this->handlePasskeyRegisterOptions($dto);

                break;

            case ChatSignalConstants::PASSKEY_REGISTER_CONFIRM:
                if (!$dto instanceof PasskeyRegisterConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyRegisterConfirmActionDTO::class, $dto);
                }
                $this->handlePasskeyRegisterConfirm($dto);

                break;

            case ChatSignalConstants::PASSKEY_LOGIN_OPTIONS:
                if (!$dto instanceof PasskeyLoginOptionsActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyLoginOptionsActionDTO::class, $dto);
                }
                $this->handlePasskeyLoginOptions($dto);

                break;

            case ChatSignalConstants::PASSKEY_LOGIN_CONFIRM:
                if (!$dto instanceof PasskeyLoginConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyLoginConfirmActionDTO::class, $dto);
                }
                $this->handlePasskeyLoginConfirm($dto);

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
        if (strlen($dto->password) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
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

        // Announce the new member in the chat event stream. Under the old
        // auto-guest model connecting emitted this notice; with explicit
        // registration the form is the real trigger, so the "registered in chat"
        // event fans out from here to every reader.
        Hilos::$db->events->actions->addUserRegistered($userId);

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
     * Issues a password-reset code for an email, always answering generically.
     *
     * Anti-enumeration: whether or not a `password` identity exists for the
     * email, the action returns the same generic success. A real account issues
     * (throttled) a code through the verification service; an unknown one still
     * spends a dummy hash cost so the response time does not disclose which
     * emails have accounts.
     *
     * @param RequestPasswordResetActionDTO $dto Parsed request payload (email)
     * @throws HilosException When identity lookup or code issuing fails
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a code
     */
    private function handleRequestPasswordReset(RequestPasswordResetActionDTO $dto): void
    {
        $email = strtolower($dto->email);
        $identity = $email !== ''
            ? Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email)
            : null;

        if ($identity === null || $identity->userId === null) {
            // No account for this email: still spend a hash cost so the response
            // time does not disclose that the identifier is unknown.
            Hilos::$db->identities->verifyDummyPassword($dto->email);

            return;
        }

        (new VerificationService())->issue(VerificationType::PASSWORD_RESET, $email, $identity->userId);
    }

    /**
     * Verifies a password-reset code and sets the new password on the identity.
     *
     * The new password is length-checked before the code is verified so a weak
     * password does not burn a valid code. A missing/expired/wrong code fails
     * with the same generic message (no enumeration). On success the code is
     * single-use consumed inside the service and the `password` identity's secret
     * is rewritten through the identity layer; there is no auto-login (the user
     * signs in afterwards).
     *
     * @param ConfirmPasswordResetActionDTO $dto Parsed confirm payload (email, code, newPassword)
     * @throws ValidationException When the new password is too short or the code is invalid
     * @throws HilosException When verification or the identity secret write fails
     */
    private function handleConfirmPasswordReset(ConfirmPasswordResetActionDTO $dto): void
    {
        if (strlen($dto->newPassword) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        $email = strtolower($dto->email);
        $userId = (new VerificationService())->verify(VerificationType::PASSWORD_RESET, $email, $dto->code);
        if ($userId === null) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
        if ($identity === null) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $identity->setPassword($dto->newPassword);
    }

    /**
     * Issues an SMS one-time login code for a phone, always answering generically.
     *
     * The additive phone-login entry (HIL-280): a malformed number is rejected as
     * a format error (no account is disclosed by that), but a well-formed number
     * always issues (throttled inside the service) whether or not it already has
     * an `sms` identity — the account is find-or-created on confirm, so issuing
     * unconditionally reveals nothing about who has an account. The code is issued
     * with a null owning user because the phone user may not exist yet.
     *
     * @param RequestSmsCodeActionDTO $dto Parsed request payload (phone)
     * @throws ValidationException When the phone number is malformed
     * @throws HilosException When code issuing fails
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a code
     */
    private function handleRequestSmsCode(RequestSmsCodeActionDTO $dto): void
    {
        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null) {
            throw new ValidationException(self::INVALID_PHONE_MESSAGE);
        }

        (new VerificationService())->issue(VerificationType::SMS_LOGIN, $phone, null);
    }

    /**
     * Verifies an SMS login code and signs the phone in, creating the user if new.
     *
     * Single login+register flow (HIL-280): a missing/expired/wrong code — and a
     * malformed phone — fail with the same generic message (no enumeration). On a
     * valid code the code is single-use consumed inside the service, then the `sms`
     * identity is find-or-created: an existing one resolves its user, a new phone
     * mints a user (display name = the E.164 number), a verified `sms` identity,
     * and the "registered in chat" event. The live anonymous session is then
     * upgraded to that user through {@see ChatAgent::authenticateSession()}.
     *
     * @param ConfirmSmsCodeActionDTO $dto Parsed confirm payload (phone, code)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the phone or code is invalid
     * @throws HilosException When verification, user/identity creation, or session promotion fails
     */
    private function handleConfirmSmsCode(ConfirmSmsCodeActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null
            || !(new VerificationService())->verifyCode(VerificationType::SMS_LOGIN, $phone, $dto->code)) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $identity = Hilos::$db->identities->findByIdentity(IdentityType::SMS, $phone);
        if ($identity !== null && $identity->userId !== null) {
            $userId = $identity->userId;
        } else {
            $user = Hilos::$db->users->actions->createWithName($phone);
            $userId = (int)$user->id;
            Hilos::$db->identities->createSmsIdentity($userId, $phone);
            Hilos::$db->events->actions->addUserRegistered($userId);
        }

        $this->agent->authenticateSession(Hilos::$rt->selfConnection->sessionToken, $userId);
    }

    /**
     * Issues an email magic-link sign-in token, always answering generically.
     *
     * The passwordless login entry (HIL-283): login-only, so it resolves the
     * account from a verified email identity (any type) through
     * {@see Identities::findUserIdByVerifiedEmail()} and issues (throttled inside
     * the service) a token bound to that user — no user or identity is ever
     * created here. An unknown or unverified email is a silent no-op; either way
     * the response is the same generic success, so this never discloses whether
     * the address has an account. The token is delivered as a clickable URL by the
     * deliverer seam (dev-stub logs it), which the /auth/magic route relays back.
     *
     * @param RequestMagicLinkActionDTO $dto Parsed request payload (email)
     * @throws HilosException When identity lookup or token issuing fails
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a token
     */
    private function handleRequestMagicLink(RequestMagicLinkActionDTO $dto): void
    {
        $email = strtolower($dto->email);
        $userId = $email !== ''
            ? Hilos::$db->identities->findUserIdByVerifiedEmail($email)
            : null;
        if ($userId === null) {
            return;
        }

        (new VerificationService())->issue(VerificationType::MAGIC_LINK, $email, $userId);
    }

    /**
     * Verifies a magic-link token and signs the resolved user into the session.
     *
     * Login-only (HIL-283): the token was minted for a user resolved at request
     * time, so {@see VerificationService::verify()} returns that user id on a
     * match. A missing/expired/wrong/exhausted token — and an empty email — fail
     * with the same generic message (no enumeration). On success the token is
     * single-use consumed inside the service and the live anonymous session (the
     * one that opened the /auth/magic route) is upgraded to that user through
     * {@see ChatAgent::authenticateSession()}; no user or identity is created.
     *
     * @param ConfirmMagicLinkActionDTO $dto Parsed confirm payload (email, token)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the email or token is invalid
     * @throws HilosException When verification or session promotion fails
     */
    private function handleConfirmMagicLink(ConfirmMagicLinkActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $email = strtolower($dto->email);
        $userId = $email !== ''
            ? (new VerificationService())->verify(VerificationType::MAGIC_LINK, $email, $dto->token)
            : null;
        if ($userId === null) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $this->agent->authenticateSession(Hilos::$rt->selfConnection->sessionToken, $userId);
    }

    /**
     * Issues an email-confirmation code for the signed-in user's own email.
     *
     * Authenticated (see AUTH_ACTIONS): the target email is the user's `password`
     * identity identifier, resolved from the session — never from the client. A
     * user with no email identity has nothing to confirm and the request is a
     * silent no-op.
     *
     * @param RequestRegisterConfirmActionDTO $dto Parsed request payload (no fields)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws HilosException When identity lookup or code issuing fails
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a code
     */
    private function handleRequestRegisterConfirm(RequestRegisterConfirmActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $userId = Hilos::$rt->selfConnection->userId;
        $identity = $this->passwordIdentityForUser($userId);
        if ($identity === null || $identity->identifier === '') {
            return;
        }

        (new VerificationService())->issue(VerificationType::REGISTER_CONFIRM, $identity->identifier, $userId);
    }

    /**
     * Verifies an email-confirmation code and flips the identity to verified.
     *
     * Authenticated: the target email is resolved from the session user's
     * `password` identity. A missing/expired/wrong code fails generically. On
     * success the code is single-use consumed inside the service and the email
     * identity's `verified` flag is set through the identity layer.
     *
     * @param ConfirmRegisterActionDTO $dto Parsed confirm payload (code)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the code is invalid or there is no email to confirm
     * @throws HilosException When verification or the identity verify-flip fails
     */
    private function handleConfirmRegister(ConfirmRegisterActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $identity = $this->passwordIdentityForUser(Hilos::$rt->selfConnection->userId);
        if ($identity === null || $identity->identifier === '') {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $userId = (new VerificationService())->verify(VerificationType::REGISTER_CONFIRM, $identity->identifier, $dto->code);
        if ($userId === null) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $identity->markVerified();
    }

    /**
     * Resolves a user's `password` identity (the one carrying their email).
     *
     * @param int $userId Owning user id
     * @return ?Identity The user's password identity, or null when they have none
     * @throws HilosException When the identity lookup fails
     */
    private function passwordIdentityForUser(int $userId): ?Identity
    {
        foreach (Hilos::$db->identities->listByUser($userId) as $identity) {
            if ($identity->type === IdentityType::PASSWORD) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * Begins an OAuth login: mints the authorize URL and hands it to the browser.
     *
     * The redirect-start entry (HIL-281). It does no outbound HTTP — it builds the
     * provider authorize URL and a signed, session-bound `state` synchronously — but
     * the framework `action_success` cannot carry a domain payload, so the URL is
     * delivered on the OAUTH_AUTHORIZE signal (WS_USER) to the initiating connection;
     * the SPA navigates there. An unknown provider is a synchronous rejection.
     *
     * @param OAuthStartActionDTO $dto Parsed start payload (provider)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the provider is not configured
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a state nonce
     */
    private function handleOauthStart(OAuthStartActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        try {
            $authorizeUrl = ChatOAuthConfig::buildService()
                ->beginAuthorization($dto->provider, $connection->sessionToken);
        } catch (OAuthUnknownProviderException) {
            throw new ValidationException('Unknown authentication provider');
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_AUTHORIZE,
            $connection->acceptKey,
            new OAuthAuthorizeSignalData($connection->acceptKey, $authorizeUrl),
        );
    }

    /**
     * Accepts an OAuth callback: verifies the state and records the exchange op.
     *
     * The callback arm of mechanism B (HIL-281). Provider resolution and state
     * verification are I/O-free, so an unknown provider or a bad/expired/foreign
     * state fails synchronously (CSRF guard) before anything is recorded. On a valid
     * state it records a short-lived in-flight op keyed by the initiating accept key
     * for the framework OAuth agent to exchange across ticks, then returns: the
     * auto-sent `action_success` means only "accepted, working", not "logged in" —
     * the FE keeps its spinner and resolves on the currentUser update (success) or
     * the OAuthResult failure signal.
     *
     * @param OAuthCallbackActionDTO $dto Parsed callback payload (provider, code, state)
     * @throws ItemNotFoundForUpdateException When the WebSocket session or OAuth runtime is missing
     * @throws ValidationException When the provider is unknown or the state is invalid
     */
    private function handleOauthCallback(OAuthCallbackActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $service = ChatOAuthConfig::buildService();
        try {
            $service->providerFor($dto->provider);
            $mode = $service->verifyState($dto->state, $connection->sessionToken);
        } catch (OAuthUnknownProviderException | OAuthStateException) {
            throw new ValidationException('OAuth verification failed');
        }

        // The linked-to user is taken server-side from the live session, never from
        // the client: a link-mode callback can only ever bind into its own account.
        // A link state on an anonymous session is impossible (the start action is on
        // the authenticated profile page) and is refused as a verification failure.
        $linkUserId = 0;
        if ($mode === OAuthStateSigner::MODE_LINK) {
            if ($connection->userId === null) {
                throw new ValidationException('OAuth verification failed');
            }
            $linkUserId = $connection->userId;
        }

        $pending = Hilos::$rt->getStateCollection(OAuthPendingLogin::RT_COLLECTION);
        if (!$pending instanceof OAuthPendingLogins) {
            throw new ItemNotFoundForUpdateException('OAuth login runtime is unavailable');
        }

        $pending->add(OAuthPendingLogin::create(
            $connection->acceptKey,
            $connection->sessionToken,
            $dto->provider,
            $dto->code,
            microtime(true) * 1000 + ChatOAuthConfig::EXCHANGE_TTL_MS,
            $mode,
            $linkUserId,
        ));
    }

    /**
     * Links an OAuth account to the re-authenticated user after an email collision (HIL-282).
     *
     * The redemption arm of the collision flow. Authenticated (see AUTH_ACTIONS):
     * the signed link token minted by the collision branch is verified for HMAC and
     * expiry, then its email must resolve to the now-signed-in user — this is the
     * ownership proof that a full re-authentication provided, so a token minted for
     * one account can never link into another. The verified oauth identity is then
     * bound. A bad, expired, foreign-owned, or already-linked token all fail with
     * the same generic message; nothing about the matched account crosses the wire.
     *
     * @param LinkOAuthAfterReauthActionDTO $dto Parsed link payload (signed token)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the token is invalid, foreign-owned, or already linked
     * @throws HilosException When the identity lookup or bind fails
     */
    private function handleLinkOAuthAfterReauth(LinkOAuthAfterReauthActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        $link = ChatOAuthConfig::buildService()->verifyLinkToken($dto->token);
        if ($link === null) {
            throw new ValidationException(self::INVALID_LINK_MESSAGE);
        }

        if (Hilos::$db->identities->findUserIdByVerifiedEmail($link->email) !== $userId) {
            throw new ValidationException(self::INVALID_LINK_MESSAGE);
        }

        try {
            Hilos::$db->identities->createOauthIdentity($userId, $link->provider, $link->subject);
        } catch (DuplicateValueException) {
            throw new ValidationException(self::INVALID_LINK_MESSAGE);
        }
    }

    /**
     * Mints WebAuthn registration options for the signed-in user (HIL-284).
     *
     * The register-start entry, authenticated (see AUTH_ACTIONS): a passkey is
     * added to an already signed-in account. It builds the publicKey creation
     * options (ES256, resident key required, the user's existing passkeys excluded)
     * and a stateless challenge token bound to the session and user synchronously
     * (CPU-only, no I/O), then — the framework `action_success` carries no domain
     * payload — hands them to the browser on the PASSKEY_OPTIONS signal for
     * navigator.credentials.create().
     *
     * @param PasskeyRegisterOptionsActionDTO $dto Parsed options request payload (no fields)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing or anonymous
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a challenge
     * @throws HilosException When WebAuthn env config or credential lookup fails
     */
    private function handlePasskeyRegisterOptions(PasskeyRegisterOptionsActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;
        $userId = $connection->userId;

        $config = WebAuthnConfig::fromEnv();
        $challenge = (new WebAuthnChallengeSigner($config->challengeSecret))->issue(
            WebAuthnChallengeSigner::PURPOSE_REGISTER,
            $connection->sessionToken,
            $userId,
            $config->challengeTtlSeconds,
        );

        $publicKeyOptions = $this->buildRegistrationOptions(
            $config,
            $userId,
            $challenge->challenge,
            Hilos::$db->passkeyCredentials->listByUser($userId),
        );

        $this->sendToUser(
            ChatSignalConstants::PASSKEY_OPTIONS,
            $connection->acceptKey,
            new PasskeyOptionsSignalData(
                $connection->acceptKey,
                WebAuthnChallengeSigner::PURPOSE_REGISTER,
                $publicKeyOptions,
                $challenge->token,
            ),
        );
    }

    /**
     * Verifies a WebAuthn attestation and stores a new passkey for the user (HIL-284).
     *
     * The register-confirm arm, authenticated: it re-derives the challenge from the
     * signed token (a bad/expired/foreign token fails generically) and asserts the
     * token was minted for this session's user, then verifies the attestation
     * ceremony ({@see AttestationVerifier}) and persists the credential as a thin
     * `passkey` identity anchor ({@see Identities::createPasskeyIdentity()}) plus the
     * crypto sidecar row. A ceremony failure surfaces a (register-only) specific
     * reason; a duplicate credential answers "already registered". No auto-login —
     * the user is already signed in.
     *
     * @param PasskeyRegisterConfirmActionDTO $dto Parsed confirm payload (signed challenge, attestation object, client data, transports)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing or anonymous
     * @throws ValidationException When the challenge, payload, or ceremony is invalid, or the passkey is already registered
     * @throws HilosException When WebAuthn env config, identity creation, or credential storage fails
     */
    private function handlePasskeyRegisterConfirm(PasskeyRegisterConfirmActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;
        $userId = $connection->userId;

        $config = WebAuthnConfig::fromEnv();
        try {
            $claims = (new WebAuthnChallengeSigner($config->challengeSecret))->verify(
                $dto->signedChallenge,
                WebAuthnChallengeSigner::PURPOSE_REGISTER,
                $connection->sessionToken,
            );
        } catch (WebAuthnChallengeException) {
            throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
        }
        if ($claims->userId !== $userId) {
            throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
        }

        $attestationObject = Base64Url::decode($dto->attestationObject);
        $clientDataJson = Base64Url::decode($dto->clientDataJson);
        if ($attestationObject === null || $clientDataJson === null) {
            throw new ValidationException('Malformed passkey registration payload');
        }

        try {
            $result = (new AttestationVerifier($config))->verify($claims->challenge, $clientDataJson, $attestationObject);
        } catch (WebAuthnVerificationException $e) {
            throw new ValidationException('Passkey registration failed: ' . $e->getMessage());
        }

        $transports = $dto->transports === [] ? null : implode(',', $dto->transports);

        try {
            $identity = Hilos::$db->identities->createPasskeyIdentity($userId, $result->credentialId);
            Hilos::$db->passkeyCredentials->createFromRegistration(
                (int)$identity->id,
                $userId,
                $result->credentialId,
                $result->publicKeyPem,
                $result->signCount,
                $transports,
                $result->aaguid,
                $this->passkeyUserHandle($config, $userId, Hilos::$db->passkeyCredentials->listByUser($userId)),
            );
        } catch (DuplicateValueException) {
            throw new ValidationException('This passkey is already registered');
        }
    }

    /**
     * Mints WebAuthn login options for a username-first passkey sign-in (HIL-284).
     *
     * The login-start entry, public (anonymous-reachable): the client names the
     * account email; the email resolves the user's passkey credentials into
     * allowCredentials regardless of whether the email is verified — the WebAuthn
     * assertion is the proof, so a passkey account whose email was never confirmed
     * still signs in. An unknown email — or a known account with no passkey —
     * answers with a single fabricated allowCredentials entry so the response never
     * discloses which case it is (anti-enumeration). The stateless challenge is
     * bound to the session (no user, resolved on confirm) and, since
     * `action_success` carries no payload, delivered on the PASSKEY_OPTIONS signal
     * for navigator.credentials.get().
     *
     * @param PasskeyLoginOptionsActionDTO $dto Parsed options request payload (email)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws \Random\RandomException When the platform CSPRNG cannot produce a challenge or dummy id
     * @throws HilosException When WebAuthn env config or credential lookup fails
     */
    private function handlePasskeyLoginOptions(PasskeyLoginOptionsActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $config = WebAuthnConfig::fromEnv();
        $challenge = (new WebAuthnChallengeSigner($config->challengeSecret))->issue(
            WebAuthnChallengeSigner::PURPOSE_LOGIN,
            $connection->sessionToken,
            null,
            $config->challengeTtlSeconds,
        );

        $email = strtolower($dto->email);
        $userId = $email !== '' ? Hilos::$db->identities->findUserIdByEmail($email) : null;
        $credentials = $userId !== null ? Hilos::$db->passkeyCredentials->listByUser($userId) : [];

        $allowCredentials = $credentials === []
            ? [$this->dummyCredentialDescriptor()]
            : array_map(
                fn(PasskeyCredential $credential): array => $this->credentialDescriptor($credential),
                $credentials,
            );

        // WebAuthn PublicKeyCredentialRequestOptions wire shape (spec-defined keys,
        // binary fields base64url); the client passkey wrapper translates it.
        $publicKeyOptions = [
            'challenge' => $challenge->challenge,
            'rpId' => $config->rpId,
            'allowCredentials' => $allowCredentials,
            'userVerification' => $config->userVerification,
            'timeout' => $config->timeoutMs,
        ];

        $this->sendToUser(
            ChatSignalConstants::PASSKEY_OPTIONS,
            $connection->acceptKey,
            new PasskeyOptionsSignalData(
                $connection->acceptKey,
                WebAuthnChallengeSigner::PURPOSE_LOGIN,
                $publicKeyOptions,
                $challenge->token,
            ),
        );
    }

    /**
     * Verifies a WebAuthn assertion and signs the resolved user into the session (HIL-284).
     *
     * The login-confirm arm, public: it re-derives the challenge from the signed
     * token, resolves the asserted credential by its id, verifies the assertion
     * against the credential's stored key and advances the clone-detection counter
     * ({@see PasskeyCredential::verifyAssertion()}), then upgrades the live
     * anonymous session to the credential's owner through
     * {@see ChatAgent::authenticateSession()} (the session upgrade rides the
     * existing handshake response). Every failure — bad token, unknown credential,
     * malformed payload, failed assertion — collapses to one generic message.
     *
     * @param PasskeyLoginConfirmActionDTO $dto Parsed confirm payload (signed challenge, credential id, authenticator data, client data, signature)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the challenge, credential, payload, or assertion is invalid
     * @throws HilosException When WebAuthn env config, credential lookup, counter persistence, or session promotion fails
     */
    private function handlePasskeyLoginConfirm(PasskeyLoginConfirmActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $config = WebAuthnConfig::fromEnv();
        try {
            $claims = (new WebAuthnChallengeSigner($config->challengeSecret))->verify(
                $dto->signedChallenge,
                WebAuthnChallengeSigner::PURPOSE_LOGIN,
                $connection->sessionToken,
            );
        } catch (WebAuthnChallengeException) {
            throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
        }

        $credential = Hilos::$db->passkeyCredentials->findByCredentialId($dto->credentialId);
        if ($credential === null) {
            throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
        }

        $authenticatorData = Base64Url::decode($dto->authenticatorData);
        $clientDataJson = Base64Url::decode($dto->clientDataJson);
        $signature = Base64Url::decode($dto->signature);
        if ($authenticatorData === null || $clientDataJson === null || $signature === null) {
            throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
        }

        try {
            $credential->verifyAssertion(
                new AssertionVerifier($config),
                $claims->challenge,
                $clientDataJson,
                $authenticatorData,
                $signature,
            );
        } catch (WebAuthnVerificationException) {
            throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
        }

        $this->agent->authenticateSession($connection->sessionToken, $credential->userId);
    }

    /**
     * Builds the WebAuthn creation-options wire shape for a register ceremony.
     *
     * @param WebAuthnConfig $config Resolved WebAuthn configuration
     * @param int $userId Signed-in user the passkey is registered for
     * @param string $challenge base64url challenge value for the client
     * @param list<PasskeyCredential> $existing The user's existing passkeys (excluded from re-registration)
     * @return array<string, mixed> WebAuthn PublicKeyCredentialCreationOptions wire shape (spec-defined keys)
     * @throws HilosException When resolving the user record fails
     */
    private function buildRegistrationOptions(WebAuthnConfig $config, int $userId, string $challenge, array $existing): array
    {
        $user = Hilos::$db->users[$userId];
        $displayName = $user !== null && $user->name !== '' ? $user->name : 'user';

        return [
            'challenge' => $challenge,
            'rp' => ['id' => $config->rpId, 'name' => $config->rpName],
            'user' => [
                'id' => Base64Url::encode($this->passkeyUserHandle($config, $userId, $existing)),
                'name' => $displayName,
                'displayName' => $displayName,
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::PASSKEY_ALG_ES256]],
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => $config->userVerification,
            ],
            'attestation' => 'none',
            'excludeCredentials' => array_map(
                fn(PasskeyCredential $credential): array => $this->credentialDescriptor($credential),
                $existing,
            ),
            'timeout' => $config->timeoutMs,
        ];
    }

    /**
     * Maps a stored passkey credential to a WebAuthn credential descriptor.
     *
     * @param PasskeyCredential $credential Stored passkey credential
     * @return array{type: string, id: string, transports: list<string>} WebAuthn PublicKeyCredentialDescriptor wire shape
     */
    private function credentialDescriptor(PasskeyCredential $credential): array
    {
        $transports = $credential->transports;

        return [
            'type' => 'public-key',
            'id' => $credential->credentialId,
            'transports' => $transports === null || $transports === '' ? [] : explode(',', $transports),
        ];
    }

    /**
     * Builds one fabricated credential descriptor for the login anti-enumeration path.
     *
     * @return array{type: string, id: string, transports: list<string>} WebAuthn descriptor with a random id
     * @throws \Random\RandomException When the platform CSPRNG cannot produce the dummy id
     */
    private function dummyCredentialDescriptor(): array
    {
        return [
            'type' => 'public-key',
            'id' => Base64Url::encode(random_bytes(32)),
            'transports' => [],
        ];
    }

    /**
     * Resolves the WebAuthn user handle for a user (one per user, reused across passkeys).
     *
     * The handle is placed in the registration options `user.id` and stored on the
     * credential; it must be stable across a user's passkeys and match on a later
     * discoverable login (HIL-400). Because the challenge token is stateless it
     * cannot carry a fresh random handle from options to confirm, so the handle is
     * derived deterministically from the user id via HMAC over the challenge secret
     * — opaque, non-PII, and identical at both steps — while any handle already
     * stored for the user takes precedence.
     *
     * @param WebAuthnConfig $config Resolved WebAuthn configuration (challenge secret)
     * @param int $userId Owning user id
     * @param list<PasskeyCredential> $existing The user's existing passkeys
     * @return string Raw WebAuthn user handle bytes
     */
    private function passkeyUserHandle(WebAuthnConfig $config, int $userId, array $existing): string
    {
        if ($existing !== []) {
            return $existing[0]->userHandle;
        }

        return hash_hmac('sha256', self::PASSKEY_USER_HANDLE_PREFIX . $userId, $config->challengeSecret, true);
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
