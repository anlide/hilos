<?php

declare(strict_types=1);

namespace Hilos\Auth\Identity;

use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AddressablePerson;
use Hilos\Users\DTO\IdentityAddData;
use Hilos\Users\DTO\OAuthIdentityAddData;
use Hilos\Users\DTO\PasskeyIdentityAddData;
use Hilos\Users\DTO\PasswordIdentityAddData;
use Hilos\Users\DTO\SmsIdentityAddData;
use JsonException;
use Throwable;

/** Ordinary identity creation runs only in the personal owner's execution frame. */
final class IdentityPersonEdits
{
    /** @param int $userId Personal owner of every created row */
    public function __construct(private readonly int $userId)
    {
    }

    /**
     * @param IdentityAddData $identity Caller-verified final material
     * @return int Committed identity id
     * @throws HilosException When the person, reservation, local uniqueness or write refuses the addition
     * @throws JsonException When a saved merge reservation is malformed
     */
    public function add(IdentityAddData $identity): int
    {
        AddressablePerson::require($this->userId);
        AccountMergeAccess::requireProtectionChange($this->userId);
        Database::transactionStart();
        try {
            if ($identity instanceof PasswordIdentityAddData) {
                if (Hilos::$db->identities->findPasswordByUser($this->userId) !== null) {
                    throw new ValidationException(AuthMessages::ALREADY_HAS_PASSWORD);
                }
                $owner = Hilos::$db->identities->findUserIdByVerifiedEmail($identity->identifier);
                if ($owner !== null && $owner !== $this->userId) {
                    throw new ValidationException(AuthMessages::EMAIL_IN_USE);
                }
                $created = Hilos::$db->identities->createPasswordIdentityWithHash(
                    $this->userId,
                    $identity->identifier,
                    $identity->passwordHash,
                    verified: true,
                );
            } elseif ($identity instanceof SmsIdentityAddData) {
                $created = Hilos::$db->identities->createSmsIdentity($this->userId, $identity->identifier);
            } elseif ($identity instanceof OAuthIdentityAddData) {
                $created = Hilos::$db->identities->createOauthIdentity(
                    $this->userId,
                    $identity->provider,
                    $identity->subject,
                    $identity->providerName,
                );
            } elseif ($identity instanceof PasskeyIdentityAddData) {
                $created = Hilos::$db->identities->createPasskeyIdentity($this->userId, $identity->credentialId);
                Hilos::$db->passkeyCredentials->createFromRegistration(
                    (int)$created->id,
                    $this->userId,
                    $identity->credentialId,
                    $identity->publicKeyPem,
                    PasskeyAlgorithm::from($identity->algorithm),
                    $identity->signCount,
                    $identity->transports === [] ? null : implode(',', $identity->transports),
                    $identity->aaguid,
                    Base64Url::decode($identity->userHandle),
                    $identity->deviceName,
                );
            } else {
                throw new InvalidFormatException('Unknown identity addition material');
            }
            $id = (int)$created->id;
            Database::transactionCommit();
        } catch (DuplicateValueException $failure) {
            Database::transactionRollbackSilent();
            throw match (true) {
                $identity instanceof PasswordIdentityAddData => new ValidationException(AuthMessages::EMAIL_IN_USE),
                $identity instanceof SmsIdentityAddData => new ValidationException(AuthMessages::PHONE_IN_USE),
                $identity instanceof PasskeyIdentityAddData => new ValidationException(AuthMessages::PASSKEY_ALREADY_REGISTERED),
                default => $failure,
            };
        } catch (EmptyValueException $failure) {
            Database::transactionRollbackSilent();
            throw match (true) {
                $identity instanceof PasswordIdentityAddData => new ValidationException(AuthMessages::INVALID_EMAIL),
                $identity instanceof SmsIdentityAddData => new ValidationException(AuthMessages::INVALID_PHONE),
                default => $failure,
            };
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }

        return $id;
    }
}
