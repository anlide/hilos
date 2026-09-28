<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasskeyActionDTO;
use Hilos\Auth\Library\DTO\PasskeyDiscoverableLoginOptionsActionDTO;
use Hilos\Auth\Library\DTO\PasskeyLoginConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\Library\DTO\RegistrationPasskeyOptionsActionDTO;
use Hilos\Auth\Method\PasskeyAddressPolicy;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Registration\RegistrationConsent;
use Hilos\Auth\StepUp\DTO\StepUpOpeningReplyDTO;
use Hilos\Auth\StepUp\DTO\StepUpPasskeyAnswer;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\AttestationResult;
use Hilos\Auth\WebAuthn\AttestationVerifier;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\DTO\PasskeyOptionsSignalData;
use Hilos\Auth\WebAuthn\Exception\WebAuthnChallengeException;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Auth\WebAuthn\WebAuthnChallengeSigner;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Http\DeviceName;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Object\Item\PasskeyCredential;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * Signing in with what the device holds, enrolling one, and starting an account on one
 * (HIL-284, HIL-400, HIL-622, HIL-1104).
 *
 * Each ceremony is TWO submits, and the split is forced by the browser: the server mints
 * options and a challenge, the authenticator does its ceremony, and the result comes back
 * on a second action. Nothing is remembered between the two - the challenge travels as a
 * signed token - so no state of a half-finished ceremony can be left behind by a person
 * who closed the tab.
 *
 * The ceremonies face different ways and that is why they share a class rather than a
 * method. Enrolling adds a key to an account somebody is already signed into; logging in
 * names no account at all and lets the resident credential the picker returns say who this
 * is. The guest may knock on logging in, and the whole of what keeps it safe is that the
 * credential id, not anything the browser claims, resolves the account.
 *
 * The third is a guest's door too (HIL-1104): an account created on the key the device
 * just made, with no password. Two roads lead to it. An address this browser proved with a
 * code - the third ending of the password screen - lands as a confirmed address beside the
 * key. The other road asks for no address and is allowed only where the installation says so
 * ({@see PasskeyAddressPolicy}); a generated name labels both the account and its key.
 * The first submit picks the road and seals it into the signed challenge; the second reads
 * it back and does not pick again.
 */
final class PasskeyCommands extends AbstractLibraryCommands
{
    /**
     * Domain-separation prefix of a derived WebAuthn user handle, so the same secret cannot
     * produce a handle that collides with anything else derived from it.
     */
    private const string USER_HANDLE_PREFIX = 'passkey-user-handle:';

    /**
     * Name shown for an account the project has none for, so the OS picker draws something.
     */
    private const string UNNAMED_ACCOUNT = 'user';

    /**
     * Second domain-separation segment of the handle of an account that does not exist yet
     * (HIL-1104), so it can never equal the handle derived from a user id.
     */
    private const string NEW_ACCOUNT_HANDLE_SCOPE = 'new-account:';

    private const string NEW_ACCOUNT_NAME_PREFIX = 'User';

    private const string NEW_ACCOUNT_NAME_SCOPE = 'passkey-account-name:';

    private const int NEW_ACCOUNT_NAME_MIN_NUMBER = 100000;

    private const int NEW_ACCOUNT_NAME_NUMBER_RANGE = 900000;

    /**
     * Mints WebAuthn registration options for the signed-in user (HIL-284).
     *
     * The register-start entry, authenticated (see AUTH_ACTIONS): a passkey is
     * added to an already signed-in account. It builds the publicKey creation
     * options (the {@see PasskeyAlgorithm} set, resident key required, the user's
     * existing passkeys excluded) and a stateless challenge token bound to the
     * session and user synchronously (CPU-only, no I/O), then — the framework
     * `action_success` carries no domain payload — hands them to the browser on the
     * PASSKEY_OPTIONS signal for navigator.credentials.create().
     *
     * Enrolling is the add-a-way-in operation (HIL-1138): the confirmation is asked before the
     * options are minted, so a refusal reaches the browser before the device prompt opens.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param PasskeyRegisterOptionsActionDTO $dto Parsed options request payload (no fields)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the add is not confirmed
     * @throws InvalidArgumentException When the options signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
     * @throws HilosException When WebAuthn env config or credential lookup fails
     */
    public function registerOptions(string $acceptKey, PasskeyRegisterOptionsActionDTO $dto): void
    {
        $acting = $this->confirmedUser($acceptKey, StepUpOperationKey::ADD_SIGN_IN_METHOD);

        $config = WebAuthnConfig::fromEnv();
        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
            WebAuthnChallengeSigner::PURPOSE_REGISTER,
            $acting->sessionToken,
            $acting->userId,
            $config->challengeTtlSeconds,
        );

        $existing = Hilos::$db->passkeyCredentials->listByUser($acting->userId);
        $publicKeyOptions = $this->buildRegistrationOptions(
            $config,
            $this->passkeyUserHandle($config, $acting->userId, $existing),
            $this->library->displayNameOf($acting->userId) ?? self::UNNAMED_ACCOUNT,
            $challenge->challenge,
            $existing,
        );

        $this->library->sendToUser(
            HilosSignalConstants::HILOS_PASSKEY_OPTIONS,
            $acting->acceptKey,
            new PasskeyOptionsSignalData(
                $acting->acceptKey,
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
     * User-Agent ({@see DeviceName}) so the profile can list "Chrome on
     * macOS" instead of a credential id (HIL-418). An unrecognized agent labels
     * nothing — the row simply reads "Passkey".
     *
     * The second step of the add-a-way-in operation (HIL-1138): the confirmation is asked again
     * before the ceremony is read, since seconds pass between the two submits.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param PasskeyRegisterConfirmActionDTO $dto Parsed confirm payload (signed challenge, attestation object, client data, transports, user agent)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the add is not confirmed, the challenge, payload, or ceremony is invalid, or the passkey
     *     is already registered
     * @throws HilosException When WebAuthn env config, identity creation, or credential storage fails
     */
    public function registerConfirm(string $acceptKey, PasskeyRegisterConfirmActionDTO $dto): void
    {
        $acting = $this->confirmedUser($acceptKey, StepUpOperationKey::ADD_SIGN_IN_METHOD);

        $config = WebAuthnConfig::fromEnv();
        try {
            $claims = new WebAuthnChallengeSigner($config->challengeSecret)->verify(
                $dto->signedChallenge,
                WebAuthnChallengeSigner::PURPOSE_REGISTER,
                $acting->sessionToken,
            );
        } catch (WebAuthnChallengeException) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }
        if ($claims->userId !== $acting->userId) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }

        $attestationObject = Base64Url::decode($dto->attestationObject);
        $clientDataJson = Base64Url::decode($dto->clientDataJson);
        if ($attestationObject === null || $clientDataJson === null) {
            throw new ValidationException(AuthMessages::MALFORMED_PASSKEY_REGISTRATION);
        }

        try {
            $result = new AttestationVerifier($config)->verify($claims->challenge, $clientDataJson, $attestationObject);
        } catch (WebAuthnVerificationException $e) {
            throw new ValidationException(AuthMessages::PASSKEY_REGISTRATION_FAILED . ': ' . $e->getMessage());
        }

        $this->storeCredential(
            $acting->userId,
            $result,
            $dto->transports,
            $dto->userAgent,
            $this->passkeyUserHandle(
                $config,
                $acting->userId,
                Hilos::$db->passkeyCredentials->listByUser($acting->userId),
            ),
        );
    }

    /**
     * Mints the creation options of a key that starts a new account, or refuses before the device prompt (HIL-1104).
     *
     * A present address requires this browser's proven hold on exactly that address; an absent
     * address asks for the road without a code, open only while the installation allows it.
     * The road is sealed into the signed challenge and cannot change on the second submit.
     *
     * Every refusal is given HERE, as the action's own answer, before the browser opens the
     * device prompt: a refusal after navigator.credentials.create() would leave a key in the
     * person's keychain that this server never heard of. The same checks run again on the second
     * submit, because seconds pass between the two.
     *
     * A success answers nothing - the options travel on the PASSKEY_OPTIONS signal, as they do
     * for the other two ceremonies. The key is labeled with the address or a generated account
     * name, and bound to a user handle derived from the challenge because the account has no id yet.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param RegistrationPasskeyOptionsActionDTO $dto Options request with an optional address
     * @return ?AuthFlowOutcome The refusal to answer with, or null when the options went out on the signal
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws InvalidFormatException When the identifier is neither an email address nor a phone number
     * @throws InvalidArgumentException When the options signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
     * @throws HilosException When WebAuthn env config, the identity lookup, or the reservation lookup fails
     */
    public function registrationOptions(string $acceptKey, RegistrationPasskeyOptionsActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $normalized = null;
        if ($dto->identifier !== null) {
            $kind = IdentifierDetector::kindOf($dto->identifier);
            $normalized = IdentifierDetector::normalize($dto->identifier, $kind);
            if ($this->identifierBelongsToAccount($kind, $normalized)) {
                return $this->identifierTakenOutcome($kind);
            }
            if (new RegistrationReservationService()->findProvenForSession($acting->sessionToken)?->identifier !== $normalized) {
                return $this->reservationExpiredOutcome();
            }
        } elseif (!PasskeyAddressPolicy::allowsUnproven()) {
            return $this->addressUnprovenOutcome();
        }

        if ($normalized === null) {
            $refusal = RegistrationConsent::refusal($dto->acceptedRevisions);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        $config = WebAuthnConfig::fromEnv();
        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
            $normalized !== null ? WebAuthnChallengeSigner::PURPOSE_NEW_ACCOUNT_PROVEN : WebAuthnChallengeSigner::PURPOSE_NEW_ACCOUNT_UNPROVEN,
            $acting->sessionToken,
            null,
            $config->challengeTtlSeconds,
        );

        $publicKeyOptions = $this->buildRegistrationOptions(
            $config,
            $this->newAccountUserHandle($config, $challenge->challenge),
            $normalized ?? $this->newAccountName($config, $challenge->challenge),
            $challenge->challenge,
            [],
        );

        $this->library->sendToUser(
            HilosSignalConstants::HILOS_PASSKEY_OPTIONS,
            $acting->acceptKey,
            new PasskeyOptionsSignalData(
                $acting->acceptKey,
                PasskeyOptionsSignalData::CEREMONY_NEW_ACCOUNT,
                $publicKeyOptions,
                $challenge->token,
            ),
        );

        return null;
    }

    /**
     * Verifies the key the device just made and creates the account that signs in with it (HIL-1104).
     *
     * The second submit of the guest's passkey door. The road is the one the first submit sealed
     * into the challenge ({@see WebAuthnChallengeSigner::verifyOneOf()}), never chosen again: the
     * payload must carry an address exactly when that road requires one. A proven hold that ran
     * out answers "expired", rather than quietly becoming the road without a code.
     *
     * The first submit's refusals are asked once more, in the same words - the address may have
     * become somebody's, the hold may have gone, the setting may have been switched off - and
     * only then is the attestation checked, as the profile's enrollment checks it.
     *
     * The road with a code lands through {@see AbstractLibraryCommands::landRegistration()}: the
     * address off this browser's proven hold as a confirmed mailed-link identity, the way a
     * provider registration lands it (HIL-405), with the key written inside the same
     * transaction. The race between browsers proving one address is settled there, as for the
     * password. The road without a code lands through
     * {@see AbstractLibraryCommands::landAccountWithoutAddress()}: the key and no address,
     * with the same generated name the options gave the authenticator.
     *
     * The key is stored under the handle the options were minted with - the challenge is the
     * same on both submits, so is the handle derived from it. That makes a challenge that already
     * started an account a spent one, and it is refused as an invalid challenge is: the signed
     * token lives out its TTL, and a second key sent on it would start a second account under the
     * same handle - an account its own key could never open, since the handle names the first.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param CompleteRegistrationPasskeyActionDTO $dto Parsed completion payload (identifier, signed challenge,
     *     attestation object, client data, transports, user agent)
     * @return ?AuthFlowOutcome The refusal or the taken-address rollback to answer with, or null when the holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws InvalidFormatException When the identifier is neither an email address nor a phone number
     * @throws ValidationException When the challenge, payload, or ceremony is invalid, or the passkey is already registered
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws InvalidArgumentException When the landing or grant frame cannot be named or queued
     * @throws HilosException When WebAuthn env config, a lookup, or the account, identity, credential, project
     *     bookkeeping, or reservation write fails
     */
    public function completeRegistration(string $acceptKey, CompleteRegistrationPasskeyActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $config = WebAuthnConfig::fromEnv();
        try {
            $claims = new WebAuthnChallengeSigner($config->challengeSecret)->verifyOneOf(
                $dto->signedChallenge,
                [WebAuthnChallengeSigner::PURPOSE_NEW_ACCOUNT_PROVEN, WebAuthnChallengeSigner::PURPOSE_NEW_ACCOUNT_UNPROVEN],
                $acting->sessionToken,
            );
        } catch (WebAuthnChallengeException) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }
        $userHandle = $this->newAccountUserHandle($config, $claims->challenge);
        if (Hilos::$db->passkeyCredentials->findUserByUserHandle($userHandle) !== null) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }

        $proven = $claims->purpose === WebAuthnChallengeSigner::PURPOSE_NEW_ACCOUNT_PROVEN;
        if ($proven !== ($dto->identifier !== null)) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }
        $normalized = null;
        if ($dto->identifier !== null) {
            $kind = IdentifierDetector::kindOf($dto->identifier);
            $normalized = IdentifierDetector::normalize($dto->identifier, $kind);
            if ($this->identifierBelongsToAccount($kind, $normalized)) {
                return $this->identifierTakenOutcome($kind);
            }
            if (new RegistrationReservationService()->findProvenForSession($acting->sessionToken)?->identifier !== $normalized) {
                return $this->reservationExpiredOutcome();
            }
        } elseif (!PasskeyAddressPolicy::allowsUnproven()) {
            return $this->addressUnprovenOutcome();
        }

        if ($normalized === null) {
            $refusal = RegistrationConsent::refusal($dto->acceptedRevisions);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        $attestationObject = Base64Url::decode($dto->attestationObject);
        $clientDataJson = Base64Url::decode($dto->clientDataJson);
        if ($attestationObject === null || $clientDataJson === null) {
            throw new ValidationException(AuthMessages::MALFORMED_PASSKEY_REGISTRATION);
        }

        try {
            $result = new AttestationVerifier($config)->verify($claims->challenge, $clientDataJson, $attestationObject);
        } catch (WebAuthnVerificationException $e) {
            throw new ValidationException(AuthMessages::PASSKEY_REGISTRATION_FAILED . ': ' . $e->getMessage());
        }

        $storeKey = fn(int $userId) => $this->storeCredential(
            $userId,
            $result,
            $dto->transports,
            $dto->userAgent,
            $userHandle,
        );

        if ($normalized !== null) {
            return $this->landRegistration(
                $acting,
                $normalized,
                $this->displayNameFromEmail($normalized),
                landAs: IdentityType::MAGIC_LINK,
                withAccount: $storeKey,
            );
        }

        $this->landAccountWithoutAddress(
            $acting,
            IdentityType::PASSKEY . ':' . $result->credentialId,
            $this->newAccountName($config, $claims->challenge),
            $storeKey,
            $dto->acceptedRevisions,
        );

        return null;
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
     * @param string $acceptKey Accept key the action arrived on
     * @param PasskeyDiscoverableLoginOptionsActionDTO $dto Parsed options request payload (no fields)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws InvalidArgumentException When the options signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
     * @throws HilosException When WebAuthn env config fails
     */
    public function discoverableLoginOptions(
        string $acceptKey,
        PasskeyDiscoverableLoginOptionsActionDTO $dto,
    ): void {
        $acting = $this->acting($acceptKey);

        $config = WebAuthnConfig::fromEnv();
        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
            WebAuthnChallengeSigner::PURPOSE_LOGIN,
            $acting->sessionToken,
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

        $this->library->sendToUser(
            HilosSignalConstants::HILOS_PASSKEY_OPTIONS,
            $acting->acceptKey,
            new PasskeyOptionsSignalData(
                $acting->acceptKey,
                WebAuthnChallengeSigner::PURPOSE_LOGIN,
                $publicKeyOptions,
                $challenge->token,
            ),
        );
    }

    /**
     * Verifies a WebAuthn assertion and signs the resolved user in (HIL-284).
     *
     * The login-confirm arm, public: it re-derives the challenge from the signed
     * token, resolves the asserted credential by its id, verifies the assertion
     * against the credential's stored key and advances the clone-detection counter
     * ({@see PasskeyCredential::verifyAssertion()}), then asks the session holder to
     * raise the live anonymous session to the credential's owner. Every failure —
     * bad token, unknown credential, malformed payload, failed assertion — collapses
     * to one generic message.
     *
     * A discoverable-login assertion (HIL-400) additionally carries the WebAuthn
     * user handle; when present it is cross-checked against the credential owner as
     * defense-in-depth (the credential id stays authoritative). An authenticator
     * that holds no handle sends an empty one, so the check is skipped when it is.
     *
     * The credential's identity anchor has to be alive for the same reason (HIL-722):
     * unlink now takes both rows out together, but the credentials orphaned before it
     * did are still stored, and a ceremony that reads only the sidecar would keep
     * signing people in on a passkey their profile no longer shows.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param PasskeyLoginConfirmActionDTO $dto Parsed confirm payload (signed challenge, credential id,
     *     authenticator data, client data, signature, optional user handle)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the challenge, credential, user handle, payload, or assertion is invalid
     * @throws InvalidArgumentException When the grant frame cannot be named or queued
     * @throws HilosException When WebAuthn env config, credential or identity lookup, or counter persistence fails
     */
    public function loginConfirm(string $acceptKey, PasskeyLoginConfirmActionDTO $dto): void
    {
        $acting = $this->acting($acceptKey);

        $config = WebAuthnConfig::fromEnv();
        try {
            $claims = new WebAuthnChallengeSigner($config->challengeSecret)->verify(
                $dto->signedChallenge,
                WebAuthnChallengeSigner::PURPOSE_LOGIN,
                $acting->sessionToken,
            );
        } catch (WebAuthnChallengeException) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }

        $credential = Hilos::$db->passkeyCredentials->findByCredentialId($dto->credentialId);
        if ($credential === null) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }

        // A credential whose identity anchor is gone is not a way in (HIL-722). Since
        // HIL-1111 no such row can exist: a foreign key holds the credential to its
        // anchor, and the migration that set it deleted the orphans made before. The
        // check stays so the ceremony refuses in its own words rather than trusting
        // the schema of whichever project runs it.
        if (!isset(Hilos::$db->identities[$credential->identityId])) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }

        // Discoverable login (HIL-400) carries the WebAuthn user handle; cross-check
        // it resolves to the asserted credential's owner (defense-in-depth — the
        // credential id stays authoritative). An authenticator holding no handle
        // sends an empty one, so validate only when present.
        if ($dto->userHandle !== null) {
            $userHandle = Base64Url::decode($dto->userHandle);
            if ($userHandle === null) {
                throw new ValidationException(AuthMessages::INVALID_PASSKEY);
            }
            $handleUserId = Hilos::$db->passkeyCredentials->findUserByUserHandle($userHandle);
            if ($handleUserId === null || $handleUserId !== $credential->userId) {
                throw new ValidationException(AuthMessages::INVALID_PASSKEY);
            }
        }

        $authenticatorData = Base64Url::decode($dto->authenticatorData);
        $clientDataJson = Base64Url::decode($dto->clientDataJson);
        $signature = Base64Url::decode($dto->signature);
        if ($authenticatorData === null || $clientDataJson === null || $signature === null) {
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
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
            throw new ValidationException(AuthMessages::INVALID_PASSKEY);
        }

        $this->library->grantSession($acting, $credential->userId, provenBy: StepUpMethod::PASSKEY);
    }

    /**
     * Mints request options for proving the acting person with one of their device keys.
     *
     * @param ActingSession $acting Signed-in browser starting the proof
     * @return array{signedChallenge: string, publicKeyOptions: array<string, mixed>} Signed challenge and request options
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
     * @throws HilosException When WebAuthn configuration or credential lookup fails
     */
    public function stepUpOptions(ActingSession $acting): array
    {
        $config = WebAuthnConfig::fromEnv();
        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
            WebAuthnChallengeSigner::PURPOSE_STEP_UP,
            $acting->sessionToken,
            $acting->userId,
            $config->challengeTtlSeconds,
        );

        return [
            StepUpOpeningReplyDTO::signedChallenge => $challenge->token,
            StepUpOpeningReplyDTO::publicKeyOptions => [
                'challenge' => $challenge->challenge,
                'rpId' => $config->rpId,
                'allowCredentials' => array_map(
                    fn(PasskeyCredential $credential): array => $this->credentialDescriptor($credential),
                    Hilos::$db->passkeyCredentials->listByUser($acting->userId),
                ),
                'userVerification' => $config->userVerification,
                'timeout' => $config->timeoutMs,
            ],
        ];
    }

    /**
     * Verifies a device-key assertion for the acting person and advances its counter.
     *
     * @param ActingSession $acting Signed-in browser completing the proof
     * @param StepUpPasskeyAnswer $answer Browser assertion
     * @throws ValidationException When the challenge, credential, user handle, payload, or assertion is invalid
     * @throws HilosException When WebAuthn configuration, credential lookup, or counter persistence fails
     */
    public function assertStepUp(ActingSession $acting, StepUpPasskeyAnswer $answer): void
    {
        $config = WebAuthnConfig::fromEnv();
        try {
            $claims = new WebAuthnChallengeSigner($config->challengeSecret)->verify(
                $answer->signedChallenge,
                WebAuthnChallengeSigner::PURPOSE_STEP_UP,
                $acting->sessionToken,
            );
        } catch (WebAuthnChallengeException) {
            throw new ValidationException(StepUpMessages::PASSKEY_NOT_CONFIRMED);
        }
        if ($claims->userId !== $acting->userId) {
            throw new ValidationException(StepUpMessages::PASSKEY_NOT_CONFIRMED);
        }

        $credential = Hilos::$db->passkeyCredentials->findByCredentialId($answer->credentialId);
        if ($credential === null || $credential->userId !== $acting->userId) {
            throw new ValidationException(StepUpMessages::PASSKEY_NOT_CONFIRMED);
        }

        if ($answer->userHandle !== null) {
            $userHandle = Base64Url::decode($answer->userHandle);
            if ($userHandle === null
                || Hilos::$db->passkeyCredentials->findUserByUserHandle($userHandle) !== $acting->userId) {
                throw new ValidationException(StepUpMessages::PASSKEY_NOT_CONFIRMED);
            }
        }

        $authenticatorData = Base64Url::decode($answer->authenticatorData);
        $clientDataJson = Base64Url::decode($answer->clientDataJson);
        $signature = Base64Url::decode($answer->signature);
        if ($authenticatorData === null || $clientDataJson === null || $signature === null) {
            throw new ValidationException(StepUpMessages::PASSKEY_NOT_CONFIRMED);
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
            throw new ValidationException(StepUpMessages::PASSKEY_NOT_CONFIRMED);
        }
    }

    /**
     * Builds the WebAuthn creation-options wire shape for a register ceremony.
     *
     * The caller names the account: the profile's enrollment has a user, and the passkey door
     * of a new account has an address or a generated name and a handle derived from its challenge.
     *
     * @param WebAuthnConfig $config Resolved WebAuthn configuration
     * @param string $userHandle Raw WebAuthn user handle the key is bound to
     * @param string $name Name the device prompt shows for the account
     * @param string $challenge base64url challenge value for the client
     * @param list<PasskeyCredential> $existing The account's existing passkeys (excluded from re-registration)
     * @return array<string, mixed> WebAuthn PublicKeyCredentialCreationOptions wire shape (spec-defined keys)
     */
    private function buildRegistrationOptions(
        WebAuthnConfig $config,
        string $userHandle,
        string $name,
        string $challenge,
        array $existing,
    ): array {
        return [
            'challenge' => $challenge,
            'rp' => ['id' => $config->rpId, 'name' => $config->rpName],
            'user' => [
                'id' => Base64Url::encode($userHandle),
                'name' => $name,
                'displayName' => $name,
            ],
            'pubKeyCredParams' => PasskeyAlgorithm::credentialParameters(),
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

        return hash_hmac('sha256', self::USER_HANDLE_PREFIX . $userId, $config->challengeSecret, true);
    }

    /**
     * Stores a verified key as the thin `passkey` identity anchor and its crypto sidecar row.
     *
     * Shared by the profile's enrollment and the passkey door of a new account (HIL-1104). The
     * key is labeled with the registering device, read off the client's User-Agent
     * ({@see DeviceName}), so the profile lists "Chrome on macOS" instead of a credential id.
     *
     * A duplicate credential is turned into "already registered" HERE, and that matters on the
     * door of a new account: its landing reads a raw duplicate as the address having been taken
     * by another browser, and would answer a person whose key was the problem with "this email
     * already has an account".
     *
     * @param int $userId Account the key signs into
     * @param AttestationResult $result Verified attestation
     * @param list<string> $transports Reported authenticator transports
     * @param ?string $userAgent Registering device user agent, or null when the client sent none
     * @param string $userHandle Raw WebAuthn user handle the key was created under
     * @throws ValidationException When the passkey is already registered
     * @throws HilosException When identity creation or credential storage fails
     */
    private function storeCredential(
        int $userId,
        AttestationResult $result,
        array $transports,
        ?string $userAgent,
        string $userHandle,
    ): void {
        try {
            $identity = Hilos::$db->identities->createPasskeyIdentity($userId, $result->credentialId);
            Hilos::$db->passkeyCredentials->createFromRegistration(
                (int)$identity->id,
                $userId,
                $result->credentialId,
                $result->publicKeyPem,
                $result->algorithm,
                $result->signCount,
                $transports === [] ? null : implode(',', $transports),
                $result->aaguid,
                $userHandle,
                DeviceName::fromUserAgent($userAgent),
            );
        } catch (DuplicateValueException) {
            throw new ValidationException(AuthMessages::PASSKEY_ALREADY_REGISTERED);
        }
    }

    /**
     * Derives the WebAuthn user handle of an account that does not exist yet (HIL-1104).
     *
     * {@see passkeyUserHandle()} derives it from a user id, and the guest at the passkey door has
     * none. The challenge takes its place: it is random, signed into the token, and the same on
     * both submits, so the handle the device was given and the one stored agree. Keys added later
     * from the profile take the stored handle, so the account keeps one handle across its keys.
     *
     * @param WebAuthnConfig $config Resolved WebAuthn configuration (challenge secret)
     * @param string $challenge base64url challenge the options were minted with
     * @return string Raw WebAuthn user handle bytes
     */
    private function newAccountUserHandle(WebAuthnConfig $config, string $challenge): string
    {
        return hash_hmac(
            'sha256',
            self::USER_HANDLE_PREFIX . self::NEW_ACCOUNT_HANDLE_SCOPE . $challenge,
            $config->challengeSecret,
            true,
        );
    }

    /**
     * Derives the same non-unique name for the options and the account, without storing a draft.
     *
     * @param WebAuthnConfig $config Resolved WebAuthn configuration (challenge secret)
     * @param string $challenge Challenge shared by both submits
     * @return string Account name with a six-digit suffix
     */
    private function newAccountName(WebAuthnConfig $config, string $challenge): string
    {
        $digest = hash_hmac('sha256', self::NEW_ACCOUNT_NAME_SCOPE . $challenge, $config->challengeSecret, true);

        return self::NEW_ACCOUNT_NAME_PREFIX
            . (self::NEW_ACCOUNT_NAME_MIN_NUMBER + unpack('N', $digest)[1] % self::NEW_ACCOUNT_NAME_NUMBER_RANGE);
    }

    /**
     * The answer to a new account asked on an identifier somebody already has: go and sign in.
     *
     * @param string $kind Classification of the identifier (see IdentifierDetection::KIND_*)
     * @return AuthFlowOutcome Refusal that moves the surface to sign-in
     */
    private function identifierTakenOutcome(string $kind): AuthFlowOutcome
    {
        return AuthFlowOutcome::rejectTo(
            AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
            AuthFlowStep::IDENTIFIER,
            AuthFlowIntent::LOGIN,
            $kind === IdentifierDetection::KIND_PHONE ? AuthMessages::PHONE_TAKEN : AuthMessages::IDENTIFIER_TAKEN,
        );
    }

    /**
     * @return AuthFlowOutcome Refusal when the address no longer has this browser's proven hold
     */
    private function reservationExpiredOutcome(): AuthFlowOutcome
    {
        return AuthFlowOutcome::rejectTo(
            AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
            AuthFlowStep::IDENTIFIER,
            AuthFlowIntent::REGISTER,
            AuthMessages::RESERVATION_EXPIRED,
        );
    }

    /**
     * The road without an address is disabled; the next attempt must start by proving an address.
     *
     * @return AuthFlowOutcome Refusal that moves the surface back to the address step
     */
    private function addressUnprovenOutcome(): AuthFlowOutcome
    {
        return AuthFlowOutcome::rejectTo(
            AuthFlowOutcome::CODE_PASSKEY_ADDRESS_UNPROVEN,
            AuthFlowStep::IDENTIFIER,
            AuthFlowIntent::LOGIN,
            AuthMessages::PASSKEY_ADDRESS_UNPROVEN,
        );
    }
}
