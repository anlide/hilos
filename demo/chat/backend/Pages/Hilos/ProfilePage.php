<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\DTO\PasswordUpdatedSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\ConfirmAddPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\ConfirmSmsAddCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\LinkOAuthStartActionDTO;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestAddPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestSmsAddCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\SetPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\UnlinkIdentityActionDTO;
use Demo\Chat\Pages\MainPage;
use Hilos\Auth\OAuth\DTO\OAuthAuthorizeSignalData;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationChannelPreferenceActionDTO;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Notification\NotificationSeverity;
use Hilos\Notification\NotificationSignalName;
use Hilos\Pages\AbstractHilosProfilePage;
use Hilos\Push\DTO\PushSubscribeActionDTO;
use Hilos\Push\DTO\PushUnsubscribeActionDTO;
use Hilos\Push\PushSubscriptionAction;
use Random\RandomException;

/**
 * Chat demo implementation of the framework current-user profile page.
 *
 * The framework owns the page identity (key, route, subscription signal); this
 * concrete binds the chat agent, the self-connection browser data, and the
 * user-initiated rename action. The page is served by the chat agent because the
 * rename runs through the connection runtime (moderation phase) the chat agent
 * is the truth source for. A rename failure surfaces through the framework
 * action_error contract (the default onActionException, reached for both the
 * synchronous validation and the async moderation reject that PageSignalRouter
 * routes back); success is state-driven — the renamed user fans out over the
 * self-connection data, so no explicit success ack is sent.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::RENAME => RenameActionDTO::class,
        ChatSignalConstants::UNLINK_IDENTITY => UnlinkIdentityActionDTO::class,
        ChatSignalConstants::SET_PASSWORD => SetPasswordActionDTO::class,
        HilosSignalConstants::HILOS_LINK_OAUTH_START => LinkOAuthStartActionDTO::class,
        ChatSignalConstants::ADD_SMS_REQUEST => RequestSmsAddCodeActionDTO::class,
        ChatSignalConstants::ADD_SMS_CONFIRM => ConfirmSmsAddCodeActionDTO::class,
        ChatSignalConstants::ADD_PASSWORD_REQUEST => RequestAddPasswordActionDTO::class,
        ChatSignalConstants::ADD_PASSWORD_CONFIRM => ConfirmAddPasswordActionDTO::class,
        NotificationPreferenceAction::CHANNEL_SET => NotificationChannelPreferenceActionDTO::class,
        PushSubscriptionAction::SUBSCRIBE => PushSubscribeActionDTO::class,
        PushSubscriptionAction::UNSUBSCRIBE => PushUnsubscribeActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            ChatSignalConstants::RENAME_MODERATION_RESULT => RenameModerationResultSignalData::class,
        ],
    ];

    /**
     * Routes profile actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When an unlink is refused, a password change/add is refused (weak,
     *     wrong current, or no verified email), an OAuth link provider is unknown, an SMS-add is
     *     refused (invalid phone, invalid/expired code, or the phone already in use), an
     *     add-password-via-email is refused (invalid email, email already in use, weak password, or
     *     invalid/expired code), a notification channel toggle carries no channel name, or a push
     *     subscribe/unsubscribe carries no endpoint or key
     * @throws RandomException When minting an OAuth link state, an SMS-add code, or an add-password email code cannot draw from the CSPRNG
     * @throws HilosException When rename moderation setup fails, an identity delete query fails, a
     *     password read/write query fails, an SMS-add or add-password verification/identity query
     *     fails, a notification-preference write or channel-map read query fails, or a push
     *     subscription write fails
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::RENAME:
                if (!$dto instanceof RenameActionDTO) {
                    throw new InvalidActionPayloadException($action, RenameActionDTO::class, $dto);
                }
                $this->handleRename($acceptKey, $dto);

                break;

            case ChatSignalConstants::UNLINK_IDENTITY:
                if (!$dto instanceof UnlinkIdentityActionDTO) {
                    throw new InvalidActionPayloadException($action, UnlinkIdentityActionDTO::class, $dto);
                }
                $this->handleUnlinkIdentity($acceptKey, $dto);

                break;

            case ChatSignalConstants::SET_PASSWORD:
                if (!$dto instanceof SetPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, SetPasswordActionDTO::class, $dto);
                }
                $this->handleSetPassword($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_LINK_OAUTH_START:
                if (!$dto instanceof LinkOAuthStartActionDTO) {
                    throw new InvalidActionPayloadException($action, LinkOAuthStartActionDTO::class, $dto);
                }
                $this->handleLinkOAuthStart($dto);

                break;

            case ChatSignalConstants::ADD_SMS_REQUEST:
                if (!$dto instanceof RequestSmsAddCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestSmsAddCodeActionDTO::class, $dto);
                }
                $this->handleRequestSmsAddCode($acceptKey, $dto);

                break;

            case ChatSignalConstants::ADD_SMS_CONFIRM:
                if (!$dto instanceof ConfirmSmsAddCodeActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmSmsAddCodeActionDTO::class, $dto);
                }
                $this->handleConfirmSmsAddCode($acceptKey, $dto);

                break;

            case ChatSignalConstants::ADD_PASSWORD_REQUEST:
                if (!$dto instanceof RequestAddPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, RequestAddPasswordActionDTO::class, $dto);
                }
                $this->handleRequestAddPassword($acceptKey, $dto);

                break;

            case ChatSignalConstants::ADD_PASSWORD_CONFIRM:
                if (!$dto instanceof ConfirmAddPasswordActionDTO) {
                    throw new InvalidActionPayloadException($action, ConfirmAddPasswordActionDTO::class, $dto);
                }
                $this->handleConfirmAddPassword($acceptKey, $dto);

                break;

            case NotificationPreferenceAction::CHANNEL_SET:
                if (!$dto instanceof NotificationChannelPreferenceActionDTO) {
                    throw new InvalidActionPayloadException($action, NotificationChannelPreferenceActionDTO::class, $dto);
                }
                $this->handleSetNotificationChannel($acceptKey, $dto);

                break;

            case PushSubscriptionAction::SUBSCRIBE:
                if (!$dto instanceof PushSubscribeActionDTO) {
                    throw new InvalidActionPayloadException($action, PushSubscribeActionDTO::class, $dto);
                }
                $this->handlePushSubscribe($acceptKey, $dto);

                break;

            case PushSubscriptionAction::UNSUBSCRIBE:
                if (!$dto instanceof PushUnsubscribeActionDTO) {
                    throw new InvalidActionPayloadException($action, PushUnsubscribeActionDTO::class, $dto);
                }
                $this->handlePushUnsubscribe($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Routes profile-page agent signals to rename moderation handling.
     *
     * @param AgentSignalData $data Wrapped rename moderation result payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this page
     * @throws LogicException When the rename moderation payload type does not match the signal contract
     * @throws ValidationException When moderation rejects the requested display name
     * @throws AgentException When moderation result does not match an active rename request
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::RENAME_MODERATION_RESULT:
                if (!$data->data instanceof RenameModerationResultSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::RENAME_MODERATION_RESULT
                        . ' payload must be ' . RenameModerationResultSignalData::class,
                    );
                }
                $this->handleRenameModerationResult($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Contributes the signed-in user's notification preferences as the profile's
     * page-data section (HIL-485).
     *
     * The profile is a self-only surface with no route params: the recipient is the
     * session user, read from the self-connection (never a client value), so an
     * anonymous or session-less subscribe contributes no section and leaves the
     * browser identities snapshot to run alone. The section is a computed projection
     * ({@see NotificationChannelPreferenceProjector::sectionData()}), not a browser
     * snapshot, so it rides the one-shot subscription payload rather than the
     * reactive browser data.
     *
     * @param PageRouteParams $params Route params for the profile subscription (unused; profile has none)
     * @return ?PagePayload Notification-section payload, or null outside a signed-in session
     * @throws DatabaseException When a preference or address lookup query fails
     */
    protected function buildPagePayload(PageRouteParams $params): ?PagePayload
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            return null;
        }

        return new PagePayload(data: [
            self::NOTIFICATION_SECTION => new NotificationChannelPreferenceProjector()
                ->sectionData(Hilos::$rt->selfConnection->userId)
                ->toArray(),
        ]);
    }

    /**
     * Starts moderation for a user-initiated rename action.
     *
     * @param string $acceptKey Accept key
     * @param RenameActionDTO $dto Rename DTO
     * @throws EmptyValueException When name is empty
     * @throws ItemNotFoundForUpdateException When user session is missing
     * @throws ValidationException When another rename is already being moderated
     * @throws HilosException On runtime update failure
     */
    private function handleRename(string $acceptKey, RenameActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            $this->logAgentError("Empty new name (acceptKey={$acceptKey})");
            throw new EmptyValueException('User name cannot be empty');
        }

        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        if (
            Hilos::$rt->selfConnection->renameModerationPhase
            === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Another rename is already being moderated');
        }

        Hilos::$rt->selfConnection->actions->startRenameModeration($dto->newName);
    }

    /**
     * Unlinks one of the signed-in user's login identities (HIL-377).
     *
     * The thin demo half of the unlink: it resolves the session user from the
     * self-connection and delegates to the framework identity primitive, which
     * owns the server-authoritative guards (ownership, last-identity refusal) and
     * the delete. Success is state-driven — the delete broadcasts DB_SYNC_DELETED,
     * re-emitting the owner's identities projection so the row disappears from
     * every connection; a rejected unlink surfaces through the default framework
     * action_error contract.
     *
     * @param string $acceptKey Accept key
     * @param UnlinkIdentityActionDTO $dto Unlink DTO carrying the identity id
     * @throws ValidationException When the id is missing, not owned by the user, or is their last identity
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws DatabaseException When the identity lookup or delete query fails
     */
    private function handleUnlinkIdentity(string $acceptKey, UnlinkIdentityActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Identity id is required');
        }

        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        Hilos::$db->identities->deleteIdentity(Hilos::$rt->selfConnection->userId, $dto->identityId);
    }

    /**
     * Adds or changes the signed-in user's password from the profile (HIL-402).
     *
     * Server-authoritative and self-only: the user id is read from the session, never
     * the client. Two in-scope flows, chosen from the user's own identities (never from
     * a client flag): a CHANGE (the user already has a `password` identity) re-auths the
     * current password before rewriting the secret; an ADD (no password yet) attaches a
     * `password` identity to the user's already-proven email — no email code, since the
     * email is verified — and marks it verified. A user with no verified email (SMS-only
     * or legacy OAuth) is out of scope here and refused (that path is the email+code
     * branch, HIL-406). On success a {@see ChatSignalConstants::PASSWORD_UPDATED} signal
     * is fanned to all the user's connections, because a change moves nothing in the
     * identity projection that could confirm it; a refusal surfaces through the default
     * framework action_error contract.
     *
     * Which password it changes is the framework's answer and no longer this page's own
     * search (HIL-692): an account holds one, and asking for it by account is what makes
     * "your password is changed" true even for data written before the rule.
     *
     * @param string $acceptKey Accept key
     * @param SetPasswordActionDTO $dto Set-password DTO (new password + optional current)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the new password is too weak, the current password is wrong, or the user has no verified email
     * @throws HilosException When an identity read or secret write query fails
     */
    private function handleSetPassword(string $acceptKey, SetPasswordActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        if (strlen($dto->newPassword) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        $passwordIdentity = Hilos::$db->identities->findPasswordByUser($userId);
        if ($passwordIdentity !== null) {
            if ($dto->currentPassword === '' || !$passwordIdentity->verifyPassword($dto->currentPassword)) {
                throw new ValidationException('Current password is incorrect');
            }
            $passwordIdentity->setPassword($dto->newPassword);
            $mode = PasswordUpdatedSignalData::MODE_CHANGED;
        } else {
            $email = Hilos::$db->identities->findVerifiedEmailByUser($userId);
            if ($email === null) {
                throw new ValidationException('Confirm an email address first');
            }
            Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->newPassword)->markVerified();
            $mode = PasswordUpdatedSignalData::MODE_ADDED;
        }

        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $this->sendToUser(
                ChatSignalConstants::PASSWORD_UPDATED,
                $connection->acceptKey,
                new PasswordUpdatedSignalData($mode),
            );
        }
    }

    /**
     * Begins linking an OAuth provider to the signed-in account (HIL-401).
     *
     * The link-mode analog of the login start action: authenticated (the whole
     * profile page is an AUTHENTICATED surface), it mints a link-mode authorize URL
     * whose signed `state` carries mode=link so the callback binds the identity to
     * this session's user instead of resolving an account. The initiator's user id
     * is not carried here — it is read server-side from the session at callback time
     * ({@see MainPage::handleOauthCallback()}), so a client can
     * never link into another account. The URL rides the OAUTH_AUTHORIZE signal
     * (the framework `action_success` carries no domain payload); the SPA navigates
     * there. An unknown provider is a synchronous rejection.
     *
     * @param LinkOAuthStartActionDTO $dto Parsed link-start payload (provider)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the provider is not configured
     * @throws RandomException When the platform CSPRNG cannot produce a state nonce
     */
    private function handleLinkOAuthStart(LinkOAuthStartActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError('User not found for OAuth link start');
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        try {
            $authorizeUrl = ChatOAuthConfig::buildService()
                ->beginAuthorization($dto->provider, $connection->sessionToken, OAuthStateSigner::MODE_LINK);
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
     * Step 1 of adding a phone identity: issues an OTP to the submitted number (HIL-403).
     *
     * Server-authoritative and self-only: the owning user is read from the session,
     * never the client, and carried on the challenge so step 2 can assert the code
     * was minted for this user. The phone is normalized to E.164 (a malformed number
     * is refused synchronously); the code is issued through the framework
     * VerificationService, whose send gate can drop the request silently — the resend
     * cooldown for a repeat pressed too soon, and the per-window cap once too many
     * codes have gone to that number (HIL-421). Either way the step answers
     * `action_success` and the wizard advances to the code step, so a capped number
     * reaches a code screen for a message that is not coming until the window turns
     * over; there is no resend control here to say so. No duplicate-phone check
     * here: enumeration is avoided by only checking uniqueness on confirm, after the
     * code proves possession.
     *
     * @param string $acceptKey Accept key
     * @param RequestSmsAddCodeActionDTO $dto Add-phone request DTO (phone)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the phone is not a valid number
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When the verification query fails
     */
    private function handleRequestSmsAddCode(string $acceptKey, RequestSmsAddCodeActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null) {
            throw new ValidationException('Enter a valid phone number');
        }

        // The send gate's verdict - cooldown hold, cap refusal or a real send - is
        // deliberately dropped: the profile has no resend control to hand a countdown
        // or a refusal to, and a repeat here is a repeated modal submit (HIL-421).
        new VerificationService()->issue(VerificationType::SMS_ADD, $phone, $userId);
    }

    /**
     * Step 2 of adding a phone identity: verifies the OTP and attaches the identity (HIL-403).
     *
     * Server-authoritative and self-only: the owning user is read from the session.
     * The submitted code is verified against the `sms_add` challenge; a
     * missing/expired/wrong code — or a challenge minted for a different user than
     * this session (defence in depth against a swapped phone) — is refused with the
     * same generic message. On success a verified `sms` identity is attached to the
     * session user; the new row reaches every connection through the identities
     * projection re-emit (no bespoke success signal). A phone already used by any
     * identity is refused ('phone already used') and the existing link is never
     * moved.
     *
     * @param string $acceptKey Accept key
     * @param ConfirmSmsAddCodeActionDTO $dto Add-phone confirm DTO (phone, code)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the phone/code is invalid or the phone is already in use
     * @throws HilosException When a verification or identity query fails
     */
    private function handleConfirmSmsAddCode(string $acceptKey, ConfirmSmsAddCodeActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        $phone = PhoneNumber::normalize($dto->phone);
        $verifiedUserId = $phone === null
            ? null
            : new VerificationService()->verify(VerificationType::SMS_ADD, $phone, $dto->code);
        if ($phone === null || $verifiedUserId === null || $verifiedUserId !== $userId) {
            throw new ValidationException('Invalid or expired code');
        }

        try {
            Hilos::$db->identities->createSmsIdentity($userId, $phone);
        } catch (DuplicateValueException) {
            throw new ValidationException('That phone number is already in use');
        } catch (EmptyValueException) {
            throw new ValidationException('Enter a valid phone number');
        }
    }

    /**
     * Step 1 of adding a password to a user with no verified email: issues an email code (HIL-406).
     *
     * Server-authoritative and self-only: the owning user is read from the session,
     * never the client, and carried on the challenge so step 2 can assert the code
     * was minted for this user. The email is lowercased and format-checked (a
     * malformed address is refused synchronously). Unlike the SMS-add step, uniqueness
     * IS checked here: because the code is mailed to the entered address, an email
     * already verified by ANOTHER account is refused without sending anything (never
     * mail a stranger's verified address); a free email — or one already the user's
     * own — issues a code through the framework VerificationService, whose send gate
     * can drop the request silently: the resend cooldown for a repeat pressed too
     * soon, and the per-window cap once too many codes have gone to that address
     * (HIL-421). Either way the step answers `action_success` and the wizard advances
     * to the code step, with no resend control here to report the difference.
     *
     * @param string $acceptKey Accept key
     * @param RequestAddPasswordActionDTO $dto Add-password request DTO (email)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the email is malformed or already verified by another account
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When a verification or identity query fails
     */
    private function handleRequestAddPassword(string $acceptKey, RequestAddPasswordActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        $email = strtolower($dto->email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Enter a valid email address');
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $userId) {
            throw new ValidationException('That email is already in use');
        }

        // Same as the phone step: no resend control on the profile, so neither the
        // countdown nor the cap refusal has anywhere to go (HIL-421).
        new VerificationService()->issue(VerificationType::EMAIL_ADD, $email, $userId);
    }

    /**
     * Step 2 of adding a password: verifies the email code and writes the identity (HIL-406).
     *
     * Server-authoritative and self-only: the owning user is read from the session.
     * The new password is length-checked FIRST so a weak password never burns the
     * code, and an account that already HAS a password is refused next, for the same
     * reason and in its own words (HIL-692): this flow adds a password, an account holds
     * one, and the answer does not depend on which address was typed - so spending the
     * code to find that out would burn it over a question already settled. The old
     * refusal, "that email is already in use", was about the wrong thing entirely; the
     * address may be perfectly free. The submitted code is then verified against the
     * `email_add` challenge; a missing/expired/wrong code — or a challenge minted for a
     * different user than this session — is refused with the same generic message.
     * Uniqueness is re-checked after verify (a magic_link-verified collision on the same
     * email would slip past createPasswordIdentity's password-scoped duplicate guard)
     * before the write. On success a verified `password`
     * identity is attached to the session user on the now-proven email and a
     * {@see ChatSignalConstants::PASSWORD_UPDATED} signal (MODE_ADDED, reusing the
     * HIL-402 success signal) is fanned to all the user's connections, which clears
     * the form and flips the section to change-mode; the new identity also arrives
     * over the identities projection re-emit.
     *
     * @param string $acceptKey Accept key
     * @param ConfirmAddPasswordActionDTO $dto Add-password confirm DTO (email, code, new password)
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws ValidationException When the password is too weak, the account already has a
     *     password, the code is invalid/expired, or the email is already in use
     * @throws HilosException When a verification or identity query fails
     */
    private function handleConfirmAddPassword(string $acceptKey, ConfirmAddPasswordActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        if (strlen($dto->newPassword) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        if (Hilos::$db->identities->findPasswordByUser($userId) !== null) {
            throw new ValidationException('This account already has a password');
        }

        $email = strtolower($dto->email);
        $verifiedUserId = new VerificationService()->verify(VerificationType::EMAIL_ADD, $email, $dto->code);
        if ($verifiedUserId === null || $verifiedUserId !== $userId) {
            throw new ValidationException('Invalid or expired code');
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $userId) {
            throw new ValidationException('That email is already in use');
        }

        try {
            Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->newPassword)->markVerified();
        } catch (DuplicateValueException) {
            throw new ValidationException('That email is already in use');
        } catch (EmptyValueException) {
            throw new ValidationException('Enter a valid email address');
        }

        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $this->sendToUser(
                ChatSignalConstants::PASSWORD_UPDATED,
                $connection->acceptKey,
                new PasswordUpdatedSignalData(PasswordUpdatedSignalData::MODE_ADDED),
            );
        }
    }

    /**
     * Applies the signed-in user's opt in/out for one notification channel (HIL-485).
     *
     * Server-authoritative and self-only: the acting user is read from the session,
     * never the payload, so a client can only ever change its own preferences. The
     * write goes through the framework preferences action (enabling deletes the
     * sparse muted row, muting upserts one); the new full channel → allowed map is
     * then fanned as {@see NotificationSignalName::PREFERENCES_CHANGED} to every one
     * of the user's connections so all their devices reflect the same state (as
     * NOTIFICATION_READ does in HIL-102). A missing channel name is refused through
     * the default framework action_error contract.
     *
     * @param string $acceptKey Accept key
     * @param NotificationChannelPreferenceActionDTO $dto Channel toggle DTO (channel, desired state)
     * @throws ValidationException When the channel name is missing
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws DatabaseException When the preference write or channel-map read query fails
     */
    private function handleSetNotificationChannel(string $acceptKey, NotificationChannelPreferenceActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Notification channel is required');
        }

        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        Hilos::$db->notificationPreferences->actions->setChannel($userId, $dto->channel, $dto->enabled);

        $channels = new NotificationChannelPreferenceProjector()->channelPreferenceMap($userId);
        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $this->sendToUser(
                NotificationSignalName::PREFERENCES_CHANGED,
                $connection->acceptKey,
                new NotificationPreferencesChangedSignalData($channels),
            );
        }
    }

    /**
     * Registers the acting device's web-push subscription (HIL-199).
     *
     * Server-authoritative and self-only: the acting user is read from the session,
     * never the payload, so a device can only ever subscribe for its own user. The
     * write upserts by endpoint (rotated keys or a new owner reuse the same row). No
     * signal is fanned - a subscription is per-device durable state the toggle reads
     * back from the browser's own getSubscription(), not shared cross-connection state
     * (unlike the channel preference in {@see handleSetNotificationChannel()}). A
     * payload missing the endpoint or a key is refused through the default framework
     * action_error contract.
     *
     * @param string $acceptKey Accept key
     * @param PushSubscribeActionDTO $dto Subscription DTO (endpoint, keys, user agent)
     * @throws ValidationException When the endpoint or a key is missing
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws DatabaseException When the subscription write query fails
     * @throws EmptyValueException When the endpoint is empty
     */
    private function handlePushSubscribe(string $acceptKey, PushSubscribeActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Push subscription endpoint and keys are required');
        }

        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $userId = Hilos::$rt->selfConnection->userId;

        Hilos::$db->pushSubscriptions->actions->subscribe(
            $userId,
            $dto->endpoint,
            $dto->p256dh,
            $dto->auth,
            $dto->userAgent,
        );
    }

    /**
     * Removes the acting device's web-push subscription (HIL-199).
     *
     * The opt-out half of the toggle: the row is deleted by endpoint (endpoints are
     * globally unique). Self-only - an authenticated session is required so an
     * anonymous client cannot prune subscriptions - though the endpoint alone
     * identifies the row. As with subscribe, no signal is fanned.
     *
     * @param string $acceptKey Accept key
     * @param PushUnsubscribeActionDTO $dto Unsubscribe DTO (endpoint)
     * @throws ValidationException When the endpoint is missing
     * @throws ItemNotFoundForUpdateException When the user session is missing
     * @throws DatabaseException When the subscription delete query fails
     */
    private function handlePushUnsubscribe(string $acceptKey, PushUnsubscribeActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new ValidationException('Push subscription endpoint is required');
        }

        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            $this->logAgentError("User not found for acceptKey={$acceptKey}");
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        Hilos::$db->pushSubscriptions->actions->unsubscribe($dto->endpoint);
    }

    /**
     * Applies an approved rename moderation result or exposes a retryable failure.
     *
     * Stale connection results fail the agent-signal contract and never rename a user.
     *
     * @param RenameModerationResultSignalData $result Moderation result for a requested display name
     * @throws ValidationException When moderation rejects the display name or is unavailable
     * @throws AgentException When result does not match an active connection rename request
     * @throws HilosException On database, runtime, truth-source, or signal failure
     */
    private function handleRenameModerationResult(RenameModerationResultSignalData $result): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new AgentException('Rename moderation result connection is stale');
        }

        if (
            Hilos::$rt->selfConnection->acceptKey !== $result->acceptKey
            || Hilos::$rt->selfConnection->userId !== $result->userId
            || Hilos::$rt->selfConnection->renameModerationPhase
            !== ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING
            || Hilos::$rt->selfConnection->renameModerationName !== $result->newName
        ) {
            throw new AgentException('Rename moderation result does not match active request');
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE
                : ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED;
            Hilos::$rt->selfConnection->actions->failRenameModeration(
                $phase,
                $reason,
            );
            if ($phase === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED) {
                $this->notifyRenameRejected($result->userId, $result->newName, $reason);
            }

            throw new ValidationException($reason);
        }

        $user = Hilos::$db->users[$result->userId] ?? null;
        if ($user === null) {
            Hilos::$rt->selfConnection->actions->failRenameModeration(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE,
                'user_not_found',
            );
            throw new ItemNotFoundForUpdateException('User not found for rename');
        }

        $oldName = $user->name;
        Hilos::$rt->selfConnection->actions->clearRenameModeration();
        $user->actions->rename($result->newName);

        Hilos::$db->events->actions->addUserRenamed(
            userId: $result->userId,
            oldName: $oldName,
            newName: $result->newName,
        );
    }

    /**
     * Notifies the user that moderation refused the display name they asked for.
     *
     * Only a verdict about the name notifies: an unavailable moderator is an
     * infrastructure failure, and there is nothing to tell the user about it. The
     * emit is best-effort with respect to the rejection - the action error raised
     * next reaches the user whatever happens to the notification.
     *
     * @param int $userId User who asked to be renamed
     * @param string $newName Rejected display name
     * @param string $reason Moderation reason
     */
    private function notifyRenameRejected(int $userId, string $newName, string $reason): void
    {
        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: ChatNotificationType::RENAME_REJECTED,
                title: 'Your new name was not accepted',
                severity: NotificationSeverity::WARNING,
                body: 'Moderation rejected it: ' . $reason,
                data: [
                    'reason' => $reason,
                    'newName' => $newName,
                ],
            ));
        } catch (HilosException $e) {
            $this->logAgentError(
                "Rename rejection notification failed for userId={$userId}: {$e->getMessage()}",
            );
        }
    }
}
