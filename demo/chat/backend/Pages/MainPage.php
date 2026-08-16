<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Auth\ChatAuthMethods;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatFileUploadConstants;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Constants\PasswordPolicy;
use Demo\Chat\Pages\DTO\Main\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Pages\DTO\Main\CompletePasswordResetActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmPasswordResetActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmMagicLinkActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmRegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\ConfirmSmsCodeActionDTO;
use Demo\Chat\Pages\DTO\Main\DetectIdentifierActionDTO;
use Demo\Chat\Pages\DTO\Main\FileUploadInitActionDTO;
use Demo\Chat\Pages\DTO\Main\LinkOAuthAfterReauthActionDTO;
use Demo\Chat\Pages\DTO\Main\LoginActionDTO;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\DTO\Main\OAuthCallbackActionDTO;
use Demo\Chat\Pages\DTO\Main\OAuthStartActionDTO;
use Demo\Chat\Pages\DTO\Main\PasskeyDiscoverableLoginOptionsActionDTO;
use Demo\Chat\Pages\DTO\Main\PasskeyLoginConfirmActionDTO;
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
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\OAuth\DTO\OAuthAuthorizeSignalData;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Recovery\PasswordRecoveryService;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\AttestationVerifier;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\PasskeyDeviceName;
use Hilos\Auth\WebAuthn\Exception\WebAuthnChallengeException;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\WebAuthnChallengeSigner;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
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
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Fs\FsException;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Helpers\FileSystemHelper;
use Random\RandomException;

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
        ChatSignalConstants::DETECT_IDENTIFIER => DetectIdentifierActionDTO::class,
        ChatSignalConstants::LOGIN => LoginActionDTO::class,
        ChatSignalConstants::REGISTER => RegisterActionDTO::class,
        ChatSignalConstants::REQUEST_PASSWORD_RESET => RequestPasswordResetActionDTO::class,
        ChatSignalConstants::CONFIRM_PASSWORD_RESET => ConfirmPasswordResetActionDTO::class,
        ChatSignalConstants::COMPLETE_PASSWORD_RESET => CompletePasswordResetActionDTO::class,
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
        ChatSignalConstants::PASSKEY_LOGIN_CONFIRM => PasskeyLoginConfirmActionDTO::class,
        ChatSignalConstants::PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => PasskeyDiscoverableLoginOptionsActionDTO::class,
    ];

    // Sending a message requires a signed-in session: an anonymous visitor reads
    // the chat but is denied MESSAGE with a typed 401 (the frontend pre-disables
    // the composer and opens sign-in). LOGIN/REGISTER and the password-recovery
    // pair stay open — a guest needs them to authenticate or recover. The
    // register-confirm pair is open too since HIL-415: the code it re-sends and
    // verifies belongs to a registration that has no account yet, so the session
    // asking is anonymous by definition — requiring a signed-in user there would
    // close the only path that can create one. The passkey register pair stays
    // authenticated: a passkey is added to an already signed-in account (passkey
    // login stays open — that is how a guest signs in).
    // Uploads ride the message they draft, so the guard here is enough.
    public const array AUTH_ACTIONS = [
        ChatSignalConstants::MESSAGE,
        ChatSignalConstants::LINK_OAUTH_AFTER_REAUTH,
        ChatSignalConstants::PASSKEY_REGISTER_OPTIONS,
        ChatSignalConstants::PASSKEY_REGISTER_CONFIRM,
    ];

    // Every anonymous-reachable door into an account is throttled (HIL-420), and the list
    // is exactly that: the ones that guess a secret (LOGIN, the two code confirmations,
    // the passkey login confirmation) and the ones that make the server spend something on
    // a stranger's say-so - an email, an SMS, a password hash, a registration reservation.
    // The authenticated actions above are deliberately absent: reaching them already costs
    // an account, so the session, not the window, is what limits them. Reads are absent for
    // the same reason in reverse - nothing behind them is worth guessing at, with the one
    // exception that proves it: DETECT_IDENTIFIER answers whether an account exists, which
    // is precisely what an enumerator wants, and this list is the whole of what keeps that
    // answer expensive (HIL-414).
    public const array THROTTLED_ACTIONS = [
        ChatSignalConstants::DETECT_IDENTIFIER,
        ChatSignalConstants::LOGIN,
        ChatSignalConstants::REGISTER,
        ChatSignalConstants::REQUEST_PASSWORD_RESET,
        ChatSignalConstants::CONFIRM_PASSWORD_RESET,
        ChatSignalConstants::COMPLETE_PASSWORD_RESET,
        ChatSignalConstants::REQUEST_SMS_CODE,
        ChatSignalConstants::CONFIRM_SMS_CODE,
        ChatSignalConstants::REQUEST_MAGIC_LINK,
        ChatSignalConstants::CONFIRM_MAGIC_LINK,
        ChatSignalConstants::REQUEST_REGISTER_CONFIRM,
        ChatSignalConstants::CONFIRM_REGISTER,
        ChatSignalConstants::PASSKEY_LOGIN_CONFIRM,
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
     * Login failure for an address nobody has an account at.
     *
     * One of the three sentences that replaced the single generic "invalid email or
     * password" (HIL-414). The generic one was there to hide which addresses have
     * accounts; the live lookup in front of this form answers exactly that question
     * now, so keeping the login vague no longer withheld anything - it only left
     * the person guessing which half of what they typed was wrong.
     */
    private const string UNKNOWN_EMAIL_MESSAGE = 'No account found for this email';

    /**
     * Login failure for an address whose account was never given a password.
     *
     * An account built by a sign-in link, a provider or a phone has none, and
     * telling somebody their password is wrong when there is no password to be
     * wrong sends them to a recovery flow that cannot help them either.
     */
    private const string NO_PASSWORD_MESSAGE = 'This account has no password';

    /**
     * Login failure for a password that did not match the account's.
     */
    private const string WRONG_PASSWORD_MESSAGE = 'Incorrect password';

    /**
     * Password-reset refusal for an address with no password to reset.
     *
     * Covers both an unknown address and an account that has no password: the
     * distinction changes nothing the person can act on here, and what they can act
     * on - that this form is not their way in - is the same sentence either way.
     */
    private const string NO_PASSWORD_TO_RESET_MESSAGE = 'No password to reset for this email';

    /**
     * Generic failure message for every verification-code path (unknown, expired,
     * wrong, exhausted) so a response never discloses which case occurred.
     */
    private const string INVALID_CODE_MESSAGE = 'Invalid or expired code';

    /**
     * Message for a send refused by the window cap (HIL-421). It says nothing about
     * whose address it is - the same sentence answers a mailbox that has an account
     * and one that does not - and it deliberately quotes no number, because the
     * window it would name is the one thing worth knowing to pace a script by.
     */
    private const string SEND_CAP_MESSAGE = 'Too many codes have been sent to this address. Please try again later.';

    /**
     * Message for a registration submit on an address that already has an account.
     * It rides an outcome that moves the surface to sign-in, so it reads as a
     * redirection rather than a rejection; there is no anti-enumeration concern here
     * (registration legitimately reveals a taken address - login is where it matters).
     */
    private const string IDENTIFIER_TAKEN_MESSAGE = 'This email already has an account';

    /**
     * Message for a code submitted against a registration hold that has run out.
     * Deliberately distinct from {@see self::INVALID_CODE_MESSAGE}: the code may well
     * have been right, and telling the person it was invalid would send them looking
     * for a mistake they did not make.
     */
    private const string RESERVATION_EXPIRED_MESSAGE = 'That registration expired, please start again';

    /**
     * Message for a recovery whose code is no longer live - never issued, expired, or
     * spent while the person was still typing. Distinct from
     * {@see self::INVALID_CODE_MESSAGE} for the same reason the registration one is: it
     * rides an outcome that rolls the surface back to the address field, so it has to
     * explain a move rather than accuse a typo.
     */
    private const string RESET_CODE_EXPIRED_MESSAGE = 'That reset code is no longer valid, please start again';

    /**
     * Message for the losing save of a two-device recovery. The code is single-use, so
     * the second device is not wrong about anything - the password it was about to set
     * is simply not the one the account has now, and signing in is what is left to do.
     */
    private const string PASSWORD_ALREADY_CHANGED_MESSAGE = 'The password was already changed, please sign in';

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
     * @throws RandomException When issuing a verification code cannot draw from the CSPRNG
     * @throws HilosException When a routed handler exposes storage, settings, database, or runtime failure
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::MESSAGE:
                if (!$dto instanceof MessageActionDTO) {
                    throw new InvalidActionPayloadException($action, MessageActionDTO::class, $dto);
                }
                $this->handleMessage($dto);

                break;

            case ChatSignalConstants::DETECT_IDENTIFIER:
                if (!$dto instanceof DetectIdentifierActionDTO) {
                    throw new InvalidActionPayloadException($action, DetectIdentifierActionDTO::class, $dto);
                }

                return $this->handleDetectIdentifier($dto);

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

                return $this->handleRegister($dto);

            case ChatSignalConstants::REQUEST_PASSWORD_RESET:
                if (!$dto instanceof RequestPasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestPasswordResetActionDTO::class, $dto);
                }
                return $this->handleRequestPasswordReset($dto);

            case ChatSignalConstants::CONFIRM_PASSWORD_RESET:
                if (!$dto instanceof ConfirmPasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmPasswordResetActionDTO::class, $dto);
                }
                return $this->handleConfirmPasswordReset($dto);

            case ChatSignalConstants::COMPLETE_PASSWORD_RESET:
                if (!$dto instanceof CompletePasswordResetActionDTO) {
                    throw new InvalidActionPayloadException($action, CompletePasswordResetActionDTO::class, $dto);
                }
                return $this->handleCompletePasswordReset($dto);

            case ChatSignalConstants::REQUEST_SMS_CODE:
                if (!$dto instanceof RequestSmsCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestSmsCodeActionDTO::class, $dto);
                }
                return $this->handleRequestSmsCode($dto);

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
                return $this->handleRequestMagicLink($dto);

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

                return $this->handleRequestRegisterConfirm($dto);

            case ChatSignalConstants::CONFIRM_REGISTER:
                if (!$dto instanceof ConfirmRegisterActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmRegisterActionDTO::class, $dto);
                }

                return $this->handleConfirmRegister($dto);

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

            case ChatSignalConstants::PASSKEY_LOGIN_CONFIRM:
                if (!$dto instanceof PasskeyLoginConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyLoginConfirmActionDTO::class, $dto);
                }
                $this->handlePasskeyLoginConfirm($dto);

                break;

            case ChatSignalConstants::PASSKEY_DISCOVERABLE_LOGIN_OPTIONS:
                if (!$dto instanceof PasskeyDiscoverableLoginOptionsActionDTO) {
                    throw new InvalidActionPayloadException($action, PasskeyDiscoverableLoginOptionsActionDTO::class, $dto);
                }
                $this->handlePasskeyDiscoverableLoginOptions($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
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
     * Each of the three ways this fails says which one it was (HIL-414): the address
     * has no account, the account has no password, or the password is wrong. That is
     * the "all-in" trade of the identifier-first epic - the lookup in front of the
     * form already answers whether an address has an account, so a generic sentence
     * here withheld nothing from anybody probing and only confused the person
     * typing. The constant-time dummy hash that guarded the unknown-address path
     * went with it: it bought indistinguishable response times for an answer that is
     * now given outright.
     *
     * A verified login rehashes the stored hash when its parameters are outdated,
     * then upgrades the live anonymous session to the matched user through
     * {@see ChatAgent::authenticateSession()}.
     *
     * @param LoginActionDTO $dto Parsed login payload (email, password)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the address, the account, or the password cannot sign in
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
            throw new ValidationException($this->emailBelongsToAccount($email)
                ? self::NO_PASSWORD_MESSAGE
                : self::UNKNOWN_EMAIL_MESSAGE);
        }

        if (!$identity->verifyPassword($dto->password)) {
            throw new ValidationException(self::WRONG_PASSWORD_MESSAGE);
        }

        $identity->rehashPasswordIfNeeded($dto->password);

        $userId = $identity->userId;
        if ($userId === null) {
            throw new ValidationException(self::WRONG_PASSWORD_MESSAGE);
        }

        $this->agent->authenticateSession(
            Hilos::$rt->selfConnection->sessionToken,
            $userId,
            Hilos::$rt->selfConnection->acceptKey,
        );
    }

    /**
     * Reserves an email for a new account and sends the code that will create it.
     *
     * The submit no longer registers anybody (HIL-415). It validates, then holds the
     * address for a TTL and mails one confirmation code; the account appears when that
     * code comes back to {@see handleConfirmRegister()}. What the surface is told back is
     * where to go next, not whether a row was written:
     * - the address is free, or already held by an earlier submit of the same address:
     *   the code step, with the seconds until a re-send is allowed. The second case sends
     *   NO second letter - all the sessions registering that address converge on the one
     *   code that is already in the inbox;
     * - the address belongs to an account: not an error the person has to read and
     *   retype, but a move to the identifier step under the sign-in intent. Registration
     *   legitimately reveals a taken address (that is a login concern, not one here).
     *
     * The connection is parked as a waiter before returning, so it is reachable by the
     * converge broadcast whoever confirms first ({@see ChatAgent::convergeRegistration()}).
     *
     * @param RegisterActionDTO $dto Parsed register payload (email, password)
     * @return AuthFlowOutcome Where the surface goes next
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws EmptyValueException When email or password is empty
     * @throws InvalidFormatException When the email is not a valid address
     * @throws ValidationException When the password is too short
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When identity lookup, the reservation, or the runtime write fails
     */
    private function handleRegister(RegisterActionDTO $dto): AuthFlowOutcome
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $email = strtolower($dto->email);
        if ($email === '' || $dto->password === '') {
            throw new EmptyValueException('Email and password are required');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidFormatException('Enter a valid email address');
        }
        if (strlen($dto->password) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        if ($this->emailBelongsToAccount($email)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                self::IDENTIFIER_TAKEN_MESSAGE,
            );
        }

        new RegistrationReservationService()->reserve(IdentityType::PASSWORD, $email, $dto->password);

        Hilos::$rt->hilosRegistrationWaiters->actions->park($connection->acceptKey, $email, $connection->sessionToken);

        return AuthFlowOutcome::moveTo(
            AuthFlowStep::CODE,
            AuthFlowIntent::REGISTER,
            new VerificationService()->resendAllowedInSeconds(VerificationType::REGISTER_CONFIRM, $email),
        );
    }

    /**
     * Looks a typed identifier up and answers what the surface should offer for it.
     *
     * The read behind the single identifier field: the person types, and this says
     * whether that address or number signs in, registers, or is already waiting on a
     * code somebody asked for earlier. Nothing is written and nothing is sent, so it
     * is safe to ask on every keystroke the debounce lets through - what makes asking
     * expensive is the throttle window this action is listed in
     * ({@see self::THROTTLED_ACTIONS}), which is what stands in for the generic
     * answers this epic gave up.
     *
     * The methods it may name are this project's ({@see ChatAuthMethods}), not every
     * method the framework knows: naming one the demo has no handler for would put a
     * button on the surface whose submit is refused.
     *
     * @param DetectIdentifierActionDTO $dto Parsed lookup payload (identifier)
     * @return IdentifierDetection What is behind the identifier and what can be done with it
     * @throws InvalidFormatException When the identifier is neither an email address nor a phone number
     * @throws HilosException When the identity or reservation lookup fails
     */
    private function handleDetectIdentifier(DetectIdentifierActionDTO $dto): IdentifierDetection
    {
        return ChatAuthMethods::detector()->detect($dto->identifier);
    }

    /**
     * Whether an email already belongs to an account, by any method.
     *
     * The question the identifier-first surface asks before reserving: not "is there a
     * password identity" but "is this address somebody's". An account created through
     * OAuth carries the address as a verified identity of another type (HIL-405), and
     * offering to register it would either fail at the identity write or quietly build a
     * second account for the same person; the surface sends them to sign-in instead, and
     * the profile owns adding a password to an account that has none (HIL-406). A
     * password identity counts whether or not it is verified, since it is one somebody
     * signs in with either way.
     *
     * @param string $email Lowercased submitted email
     * @return bool True when an account already holds the address
     * @throws HilosException When the identity lookup fails
     */
    private function emailBelongsToAccount(string $email): bool
    {
        return Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email) !== null
            || Hilos::$db->identities->findUserIdByVerifiedEmail($email) !== null;
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
     * Issues a password-reset code, or says there is no password to reset.
     *
     * The second anti-enumeration stub the identifier-first epic removed
     * (HIL-414): an address with no password used to be answered with the same
     * silent success as a real one, so somebody whose account was built by a link,
     * a provider or a phone waited for a letter that was never sent. It refuses out
     * loud now, and the constant-time dummy hash that hid the difference went with
     * it. Nothing is disclosed by that which the lookup in front of the form does
     * not already answer.
     *
     * It answers the send gate's verdict (HIL-421): the seconds until the button
     * comes back, or a refusal when too many codes already went to this address.
     * The surface moves nowhere either way - the person is on the screen the code
     * belongs to.
     *
     * The connection is parked as a recovery waiter before returning (HIL-416), and
     * that is also what makes a second device cheap: the cooldown answers it with a
     * countdown off the code that is already in the inbox rather than mailing another,
     * and the same call puts it where the converge broadcast can reach it
     * ({@see ChatAgent::convergeRecovery()}). Nothing is parked for a send the cap
     * refused - no code went out for that session to wait on.
     *
     * @param RequestPasswordResetActionDTO $dto Parsed request payload (email)
     * @return AuthFlowOutcome Seconds until a re-send, or the cap refusal
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When no account at the address has a password
     * @throws HilosException When identity lookup, code issuing, or the runtime write fails
     * @throws RandomException When the platform CSPRNG cannot produce a code
     */
    private function handleRequestPasswordReset(RequestPasswordResetActionDTO $dto): AuthFlowOutcome
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $email = strtolower($dto->email);
        $outcome = new PasswordRecoveryService()->requestCode($email);
        if ($outcome === null) {
            throw new ValidationException(self::NO_PASSWORD_TO_RESET_MESSAGE);
        }

        if ($outcome->capReached) {
            return AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, self::SEND_CAP_MESSAGE);
        }

        Hilos::$rt->hilosRecoveryWaiters->actions->park($connection->acceptKey, $email, $connection->sessionToken);

        return AuthFlowOutcome::sent($outcome->resendInSeconds);
    }

    /**
     * Accepts a password-reset code and opens the password step for that session.
     *
     * Half of what this handler used to do left it with HIL-416: it no longer sets a
     * password, because the password is not on the wire yet. Recovery is two submits,
     * and this is the first - it proves the code WITHOUT spending it, so the grant it
     * hands out has something behind it when the second submit arrives
     * ({@see handleCompletePasswordReset()}).
     *
     * Three answers, and the middle one is why the code screen is not a dead end: a
     * recovery whose code is no longer live rolls the surface back to the address field
     * with a reason of its own, while a wrong code is an inline error that leaves the
     * person exactly where they are to try again. The order matters - a challenge that
     * is already gone is answered before a code is judged against it, so a stale screen
     * is never told it made a typo.
     *
     * The grant is written on the SESSION and not on this connection, so every tab of
     * this browser moves to the password step and no other device does; the tabs are
     * told by {@see ChatAgent::grantRecoveryToSession()}, since nobody in them submitted
     * anything. It is written for THIS address only - a session with a second tab parked
     * on another address must not have that one opened by a code proven here. The
     * connection is (re-)parked first, because a browser that reconnected between the
     * code and this submit lost the row its grant would be written on.
     *
     * @param ConfirmPasswordResetActionDTO $dto Parsed confirm payload (email, code)
     * @return AuthFlowOutcome The password step, or the rollback to the address field
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the submitted code does not match the live one
     * @throws HilosException When verification or the runtime write fails
     */
    private function handleConfirmPasswordReset(ConfirmPasswordResetActionDTO $dto): AuthFlowOutcome
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $email = strtolower($dto->email);
        $recovery = new PasswordRecoveryService();

        if (!$recovery->hasLiveCode($email)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESET_CODE_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::RECOVERY,
                self::RESET_CODE_EXPIRED_MESSAGE,
            );
        }

        if (!$recovery->acceptCode($email, $dto->code)) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        Hilos::$rt->hilosRecoveryWaiters->actions->park($connection->acceptKey, $email, $connection->sessionToken);
        Hilos::$rt->hilosRecoveryWaiters->actions->acceptCodeForSession($connection->sessionToken, $email);
        $this->agent->grantRecoveryToSession($email, $connection->sessionToken, $connection->acceptKey);

        return AuthFlowOutcome::moveTo(AuthFlowStep::SET_PASSWORD, AuthFlowIntent::RECOVERY);
    }

    /**
     * Saves the new password of an accepted recovery and returns the account whole.
     *
     * The second submit, and everything that ends a recovery happens here in an order
     * that is the mechanism rather than a preference: the address comes off the grant
     * (never off the payload - a password screen that could name an account would be a
     * way to reset somebody else's), the code is spent, the secret is written, this
     * session is signed in, the sessions still waiting on the address are told, and the
     * account's OTHER sessions are logged out.
     *
     * Two ways it does not go through, both answered by a move rather than an error: no
     * grant on this session - the code expired, or the browser came back to a screen
     * whose recovery is long over - and a grant that lost the race, which is what the
     * single-use code makes of a second device saving second. The winner has already
     * changed the password by then, so the loser is sent to sign in with it.
     *
     * The force-logout is the point of resetting a password at all: it is done when
     * access has leaked, so the reset takes the account back rather than adding one more
     * live session to it. The token it keeps is read after the sign-in, not before - the
     * login rotates the session onto a fresh token (HIL-582), and keeping the old one
     * would log this browser out along with the intruder.
     *
     * @param CompletePasswordResetActionDTO $dto Parsed complete payload (password)
     * @return AuthFlowOutcome The done step, or the rollback the losing session gets
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the new password is too short
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws HilosException When the secret write, the session writes, or the runtime write fails
     */
    private function handleCompletePasswordReset(CompletePasswordResetActionDTO $dto): AuthFlowOutcome
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;
        $sessionToken = $connection->sessionToken;

        $email = $this->grantedRecoveryIdentifier($sessionToken);
        if ($email === null) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESET_CODE_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::RECOVERY,
                self::RESET_CODE_EXPIRED_MESSAGE,
            );
        }

        if (strlen($dto->password) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        $userId = new PasswordRecoveryService()->complete($email, $dto->password);
        if ($userId === null) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_PASSWORD_ALREADY_CHANGED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                self::PASSWORD_ALREADY_CHANGED_MESSAGE,
            );
        }

        $this->agent->authenticateSession($sessionToken, $userId, $connection->acceptKey);
        $this->agent->convergeRecovery($email, $sessionToken, $connection->acceptKey);
        $this->agent->deauthenticateOtherSessions($userId, Hilos::$rt->selfConnection->sessionToken);

        return AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::RECOVERY);
    }

    /**
     * The address a session may currently set a password for, if any.
     *
     * The whole of what the password screen is allowed to act on. A session holds a
     * grant only after one of its tabs proved the code, and the address is read off
     * that row rather than off the submit, so the payload carries a password and
     * nothing that could point it at another account.
     *
     * @param string $sessionToken Session token asking to save a password
     * @return ?string Address whose recovery this session may finish, or null when it holds no grant
     * @throws HilosException When the runtime read fails
     */
    private function grantedRecoveryIdentifier(string $sessionToken): ?string
    {
        foreach (Hilos::$rt->hilosRecoveryWaiters->forSessionToken($sessionToken) as $waiter) {
            if ($waiter->codeAccepted) {
                return $waiter->identifier;
            }
        }

        return null;
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
     * It answers the send gate's verdict (HIL-421), on the SMS numbers: a message
     * costs money, so the cap that refuses here is the lower of the two. The
     * refusal says nothing about the number it refused for, exactly as the send
     * itself says nothing about who owns it.
     *
     * @param RequestSmsCodeActionDTO $dto Parsed request payload (phone)
     * @return AuthFlowOutcome Seconds until a re-send, or the cap refusal
     * @throws ValidationException When the phone number is malformed
     * @throws HilosException When code issuing fails
     * @throws RandomException When the platform CSPRNG cannot produce a code
     */
    private function handleRequestSmsCode(RequestSmsCodeActionDTO $dto): AuthFlowOutcome
    {
        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null) {
            throw new ValidationException(self::INVALID_PHONE_MESSAGE);
        }

        $outcome = new VerificationService()->issue(VerificationType::SMS_LOGIN, $phone, null);
        if ($outcome->capReached) {
            return AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, self::SEND_CAP_MESSAGE);
        }

        return AuthFlowOutcome::sent($outcome->resendInSeconds);
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
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws HilosException When verification, user/identity creation, or session promotion fails
     */
    private function handleConfirmSmsCode(ConfirmSmsCodeActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null
            || !new VerificationService()->verifyCode(VerificationType::SMS_LOGIN, $phone, $dto->code)) {
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

        $this->agent->authenticateSession(
            Hilos::$rt->selfConnection->sessionToken,
            $userId,
            Hilos::$rt->selfConnection->acceptKey,
        );
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
     * It is the last blind flow, so the send gate's verdict is deliberately NOT
     * passed on (HIL-421): the answer is always the nominal cooldown from the
     * configuration and never the cap code, whether the address is unknown, known,
     * or over the cap. Any difference in the number or the code would turn this
     * into the existence oracle the silent no-op exists to avoid - and the honest
     * remaining cooldown is the worst of them, being smaller for an address that
     * was mailed recently.
     *
     * @param RequestMagicLinkActionDTO $dto Parsed request payload (email)
     * @return AuthFlowOutcome The nominal cooldown, identically in every case
     * @throws HilosException When identity lookup or token issuing fails
     * @throws RandomException When the platform CSPRNG cannot produce a token
     */
    private function handleRequestMagicLink(RequestMagicLinkActionDTO $dto): AuthFlowOutcome
    {
        $email = strtolower($dto->email);
        $userId = $email !== ''
            ? Hilos::$db->identities->findUserIdByVerifiedEmail($email)
            : null;
        if ($userId !== null) {
            new VerificationService()->issue(VerificationType::MAGIC_LINK, $email, $userId);
        }

        return AuthFlowOutcome::sent(Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC));
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
            ? new VerificationService()->verify(VerificationType::MAGIC_LINK, $email, $dto->token)
            : null;
        if ($userId === null) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $this->agent->authenticateSession(
            Hilos::$rt->selfConnection->sessionToken,
            $userId,
            Hilos::$rt->selfConnection->acceptKey,
        );
    }

    /**
     * Re-sends the confirmation code of a pending registration.
     *
     * The resend button on the code screen (HIL-415). It is not a second registration:
     * the hold on the address is what decides whether there is anything to re-send, and
     * when it is gone the surface is rolled back to the identifier step under a code of
     * its own rather than told "no". A resend inside the cooldown sends nothing and
     * answers with the seconds still to wait - the countdown the screen draws.
     *
     * The hold is pushed out only when a code actually went out, so a button mashed
     * inside the cooldown moves nothing. What stops the patient caller - the one that
     * presses once per cooldown, forever, keeping the address held and its owner
     * mailed - is the window cap inside the send gate (HIL-421). It refuses out loud
     * here rather than counting down, because no wait short enough to draw would bring
     * the button back.
     *
     * Any waiting session may press it: the cooldown belongs to the address, not to the
     * session, and pressing re-parks the presser so a converge reaches it either way.
     *
     * @param RequestRegisterConfirmActionDTO $dto Parsed resend payload (email)
     * @return AuthFlowOutcome Where the surface goes next
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When the reservation, verification, or runtime write fails
     */
    private function handleRequestRegisterConfirm(RequestRegisterConfirmActionDTO $dto): AuthFlowOutcome
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $email = strtolower($dto->email);
        $reservations = new RegistrationReservationService();
        if ($reservations->findActive($email) === null) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::REGISTER,
                self::RESERVATION_EXPIRED_MESSAGE,
            );
        }

        $outcome = new VerificationService()->issue(VerificationType::REGISTER_CONFIRM, $email, null);
        if ($outcome->sent) {
            $reservations->extendTo($email);
        }

        // Parked before the cap is answered, unlike the expired hold above: a capped
        // resend leaves the person ON the code screen, so the converge still has to
        // reach them when somebody else redeems the address.
        Hilos::$rt->hilosRegistrationWaiters->actions->park($connection->acceptKey, $email, $connection->sessionToken);

        if ($outcome->capReached) {
            return AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, self::SEND_CAP_MESSAGE);
        }

        return AuthFlowOutcome::moveTo(AuthFlowStep::CODE, AuthFlowIntent::REGISTER, $outcome->resendInSeconds);
    }

    /**
     * Confirms a reserved registration: creates the account and signs the session in.
     *
     * The moment the account comes into being (HIL-415). The code is the proof of
     * ownership, so what it produces is a user, a password identity already VERIFIED
     * carrying the credential chosen at submit, the "registered in chat" event, and a
     * signed-in session - all of it here, none of it at the submit that only reserved.
     *
     * Four answers, and the difference between the middle two is the whole point of the
     * design: a wrong code is an inline error that leaves the person on the code screen
     * to try again, while a hold that ran out is not their mistake at all and rolls the
     * surface back to the address field with a reason of its own.
     *
     * The fourth is the address having become somebody's while it was held. The hold
     * keeps a SECOND registration off it, not an account arriving by another road - an
     * OAuth sign-in mints one from a verified email of its own type (HIL-405), and that
     * identity does not collide with the password one written here. So the question the
     * submit asked is asked again, and answered the same way: not an error to retype,
     * but a move to sign-in. Without it the code would build a second account for the
     * same person, or fail the identity write with a user already committed.
     *
     * The user is minted only after the code verified, so a wrong code can never leave an
     * account behind; the credential moves from the reservation into the identity inside
     * {@see RegistrationReservationService::confirmInto()} and never passes through here.
     * Every other session parked on this address is then signed in and moved to done.
     *
     * @param ConfirmRegisterActionDTO $dto Parsed confirm payload (email, code)
     * @return AuthFlowOutcome Where the surface goes next
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the code is wrong, expired, or exhausted
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws HilosException When the account, identity, event, or session write fails
     */
    private function handleConfirmRegister(ConfirmRegisterActionDTO $dto): AuthFlowOutcome
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $email = strtolower($dto->email);
        $reservations = new RegistrationReservationService();
        $reservation = $reservations->findActive($email);
        if ($reservation === null) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::REGISTER,
                self::RESERVATION_EXPIRED_MESSAGE,
            );
        }

        // Asked here too, not only at the submit: the hold blocks another registration
        // on the address, not an account that arrived by another road while it stood.
        if ($this->emailBelongsToAccount($email)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                self::IDENTIFIER_TAKEN_MESSAGE,
            );
        }

        if (!$reservations->verifyCode($email, $dto->code)) {
            throw new ValidationException(self::INVALID_CODE_MESSAGE);
        }

        $user = Hilos::$db->users->actions->createWithName($this->displayNameFromEmail($email));
        $userId = (int)$user->id;

        $reservations->confirmInto($reservation, $userId);

        // Announce the new member in the chat event stream. The notice moved here with
        // the account itself: at the submit there was nobody to announce yet.
        Hilos::$db->events->actions->addUserRegistered($userId);

        $this->agent->authenticateSession($connection->sessionToken, $userId, $connection->acceptKey);

        Hilos::$rt->hilosRegistrationWaiters->actions->release($connection->acceptKey);
        $this->agent->convergeRegistration($email, $userId, $connection->acceptKey);

        return AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::REGISTER);
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
     * @throws RandomException When the platform CSPRNG cannot produce a state nonce
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

        // Hand the verified op to the monopolistic OAuth agent point-to-point; the op has
        // exactly one consumer, so a synced agent signal — not a cross-process runtime
        // collection — is what carries it across the worker→agent process boundary (HIL-281).
        $this->agent->sendToAgent(
            HilosSignalConstants::HILOS_OAUTH_PENDING,
            new OAuthPendingLoginSignalData(
                $connection->acceptKey,
                $connection->sessionToken,
                $dto->provider,
                $dto->code,
                microtime(true) * TimeConstants::MS_PER_SECOND + ChatOAuthConfig::EXCHANGE_TTL_MS,
                $mode,
                $linkUserId,
            ),
        );
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
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
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
        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
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
     * The credential is labeled with the enrolling device, read off the client's
     * User-Agent ({@see PasskeyDeviceName}) so the profile can list "Chrome on
     * macOS" instead of a credential id (HIL-418). An unrecognized agent labels
     * nothing — the row simply reads "Passkey".
     *
     * @param PasskeyRegisterConfirmActionDTO $dto Parsed confirm payload (signed challenge, attestation object, client data, transports, user agent)
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
            $claims = new WebAuthnChallengeSigner($config->challengeSecret)->verify(
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
            $result = new AttestationVerifier($config)->verify($claims->challenge, $clientDataJson, $attestationObject);
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
                PasskeyDeviceName::fromUserAgent($dto->userAgent),
            );
        } catch (DuplicateValueException) {
            throw new ValidationException('This passkey is already registered');
        }
    }

    /**
     * Mints usernameless (discoverable) WebAuthn login options (HIL-400).
     *
     * The ONLY login-start entry since HIL-418 retired the username-first one,
     * public (anonymous-reachable): it names no account, so it resolves no user
     * and builds an EMPTY allowCredentials — the resident credential the OS picker
     * returns identifies the account on confirm. An empty allowCredentials is
     * identical for everyone, so there is nothing to enumerate and no dummy
     * descriptor is minted anymore. The stateless challenge is bound to the
     * session (no user, resolved on confirm) and, since `action_success` carries no
     * payload, delivered on the PASSKEY_OPTIONS signal (ceremony LOGIN) for
     * navigator.credentials.get().
     *
     * @param PasskeyDiscoverableLoginOptionsActionDTO $dto Parsed options request payload (no fields)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
     * @throws HilosException When WebAuthn env config fails
     */
    private function handlePasskeyDiscoverableLoginOptions(PasskeyDiscoverableLoginOptionsActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        $config = WebAuthnConfig::fromEnv();
        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
            WebAuthnChallengeSigner::PURPOSE_LOGIN,
            $connection->sessionToken,
            null,
            $config->challengeTtlSeconds,
        );

        // Discoverable: no email, no user resolution, and an EMPTY allowCredentials
        // — the resident credential the OS picker returns identifies the account on
        // confirm; an empty list is identical for everyone (nothing to enumerate).
        $publicKeyOptions = [
            'challenge' => $challenge->challenge,
            'rpId' => $config->rpId,
            'allowCredentials' => [],
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
     * A discoverable-login assertion (HIL-400) additionally carries the WebAuthn
     * user handle; when present it is cross-checked against the credential owner as
     * defense-in-depth (the credential id stays authoritative). An authenticator
     * that holds no handle sends an empty one, so the check is skipped when it is.
     *
     * @param PasskeyLoginConfirmActionDTO $dto Parsed confirm payload (signed challenge, credential id,
     *     authenticator data, client data, signature, optional user handle)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the challenge, credential, user handle, payload, or assertion is invalid
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
            $claims = new WebAuthnChallengeSigner($config->challengeSecret)->verify(
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

        // Discoverable login (HIL-400) carries the WebAuthn user handle; cross-check
        // it resolves to the asserted credential's owner (defense-in-depth — the
        // credential id stays authoritative). An authenticator holding no handle
        // sends an empty one, so validate only when present.
        if ($dto->userHandle !== null) {
            $userHandle = Base64Url::decode($dto->userHandle);
            if ($userHandle === null) {
                throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
            }
            $handleUserId = Hilos::$db->passkeyCredentials->findUserByUserHandle($userHandle);
            if ($handleUserId === null || $handleUserId !== $credential->userId) {
                throw new ValidationException(self::INVALID_PASSKEY_MESSAGE);
            }
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

        $this->agent->authenticateSession(
            $connection->sessionToken,
            $credential->userId,
            $connection->acceptKey,
        );
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
            if ($phase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED) {
                $this->notifyMessageRejected(
                    Hilos::$rt->selfConnection->userId,
                    $result->message,
                    $reason,
                );
            }

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

    /**
     * Notifies the author that moderation refused to publish their message.
     *
     * Only a verdict about the text notifies: an unavailable moderator is an
     * infrastructure failure, and there is nothing to tell the author about it. The
     * emit is best-effort with respect to the rejection - the action error raised
     * next reaches the author whatever happens to the notification.
     *
     * @param ?int $userId Author user id, or null when the connection carries none
     * @param string $message Rejected message text, kept so the author knows which one
     * @param string $reason Moderation reason
     */
    private function notifyMessageRejected(?int $userId, string $message, string $reason): void
    {
        if ($userId === null) {
            return;
        }

        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: ChatNotificationType::MESSAGE_REJECTED,
                title: 'Your message was not published',
                severity: NotificationSeverity::WARNING,
                body: 'Moderation rejected it: ' . $reason,
                data: [
                    'reason' => $reason,
                    'message' => $message,
                ],
            ));
        } catch (HilosException $e) {
            $this->logAgentError(
                "Message rejection notification failed for userId={$userId}: {$e->getMessage()}",
            );
        }
    }
}
