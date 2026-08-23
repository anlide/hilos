<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\DTO\PasskeyDiscoverableLoginOptionsActionDTO;
use Hilos\Auth\Library\DTO\PasskeyLoginConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\AttestationVerifier;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\DTO\PasskeyOptionsSignalData;
use Hilos\Auth\WebAuthn\Exception\WebAuthnChallengeException;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\PasskeyDeviceName;
use Hilos\Auth\WebAuthn\WebAuthnChallengeSigner;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Object\Item\PasskeyCredential;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * Signing in with what the device holds, and enrolling one (HIL-284, HIL-400, HIL-622).
 *
 * Each half is TWO submits, and the split is forced by the browser: the server mints
 * options and a challenge, the authenticator does its ceremony, and the result comes back
 * on a second action. Nothing is remembered between the two - the challenge travels as a
 * signed token - so no state of a half-finished ceremony can be left behind by a person
 * who closed the tab.
 *
 * The two halves face opposite ways and that is why they share a class rather than a
 * method. Enrolling adds a key to an account somebody is already signed into; logging in
 * names no account at all and lets the resident credential the picker returns say who this
 * is. The second is the only sign-in door here that a guest may knock on, and the whole of
 * what keeps it safe is that the credential id, not anything the browser claims, resolves
 * the account.
 */
final class PasskeyCommands extends AbstractLibraryCommands
{
    /**
     * COSE algorithm identifier for ES256, the one signature suite these ceremonies ask for.
     */
    private const int ALG_ES256 = -7;

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
     * @param string $acceptKey Accept key the action arrived on
     * @param PasskeyRegisterOptionsActionDTO $dto Parsed options request payload (no fields)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws InvalidArgumentException When the options signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
     * @throws HilosException When WebAuthn env config or credential lookup fails
     */
    public function registerOptions(string $acceptKey, PasskeyRegisterOptionsActionDTO $dto): void
    {
        $acting = $this->actingUser($acceptKey);

        $config = WebAuthnConfig::fromEnv();
        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
            WebAuthnChallengeSigner::PURPOSE_REGISTER,
            $acting->sessionToken,
            $acting->userId,
            $config->challengeTtlSeconds,
        );

        $publicKeyOptions = $this->buildRegistrationOptions(
            $config,
            $acting->userId,
            $challenge->challenge,
            Hilos::$db->passkeyCredentials->listByUser($acting->userId),
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
     * User-Agent ({@see PasskeyDeviceName}) so the profile can list "Chrome on
     * macOS" instead of a credential id (HIL-418). An unrecognized agent labels
     * nothing — the row simply reads "Passkey".
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param PasskeyRegisterConfirmActionDTO $dto Parsed confirm payload (signed challenge, attestation object, client data, transports, user agent)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the challenge, payload, or ceremony is invalid, or the passkey is already registered
     * @throws HilosException When WebAuthn env config, identity creation, or credential storage fails
     */
    public function registerConfirm(string $acceptKey, PasskeyRegisterConfirmActionDTO $dto): void
    {
        $acting = $this->actingUser($acceptKey);

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

        $transports = $dto->transports === [] ? null : implode(',', $dto->transports);

        try {
            $identity = Hilos::$db->identities->createPasskeyIdentity($acting->userId, $result->credentialId);
            Hilos::$db->passkeyCredentials->createFromRegistration(
                (int)$identity->id,
                $acting->userId,
                $result->credentialId,
                $result->publicKeyPem,
                $result->signCount,
                $transports,
                $result->aaguid,
                $this->passkeyUserHandle(
                    $config,
                    $acting->userId,
                    Hilos::$db->passkeyCredentials->listByUser($acting->userId),
                ),
                PasskeyDeviceName::fromUserAgent($dto->userAgent),
            );
        } catch (DuplicateValueException) {
            throw new ValidationException(AuthMessages::PASSKEY_ALREADY_REGISTERED);
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
     * @param string $acceptKey Accept key the action arrived on
     * @param PasskeyLoginConfirmActionDTO $dto Parsed confirm payload (signed challenge, credential id,
     *     authenticator data, client data, signature, optional user handle)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the challenge, credential, user handle, payload, or assertion is invalid
     * @throws InvalidArgumentException When the grant frame cannot be named or queued
     * @throws HilosException When WebAuthn env config, credential lookup, or counter persistence fails
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

        $this->library->grantSession($acting, $credential->userId);
    }

    /**
     * Builds the WebAuthn creation-options wire shape for a register ceremony.
     *
     * @param WebAuthnConfig $config Resolved WebAuthn configuration
     * @param int $userId Signed-in user the passkey is registered for
     * @param string $challenge base64url challenge value for the client
     * @param list<PasskeyCredential> $existing The user's existing passkeys (excluded from re-registration)
     * @return array<string, mixed> WebAuthn PublicKeyCredentialCreationOptions wire shape (spec-defined keys)
     * @throws HilosException When resolving the account's name fails
     */
    private function buildRegistrationOptions(
        WebAuthnConfig $config,
        int $userId,
        string $challenge,
        array $existing,
    ): array {
        $displayName = $this->library->displayNameOf($userId) ?? self::UNNAMED_ACCOUNT;

        return [
            'challenge' => $challenge,
            'rp' => ['id' => $config->rpId, 'name' => $config->rpName],
            'user' => [
                'id' => Base64Url::encode($this->passkeyUserHandle($config, $userId, $existing)),
                'name' => $displayName,
                'displayName' => $displayName,
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ALG_ES256]],
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
}
