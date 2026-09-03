<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\PasskeyCredentials as EntityPasskeyCredentials;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\PasskeyCredential as ObjectPasskeyCredential;
use Hilos\Database\PhpType;

/**
 * PasskeyCredential Entity - represents hilos_passkey_credential table row.
 *
 * WebAuthn/passkey credential sidecar (HIL-284): a passkey is a thin
 * `hilos_identity` anchor row (type=passkey, identifier=credential_id) plus one
 * crypto row here, linked by `identity_id`. Framework holds the contract; projects
 * activate the table thinly (copy the migration stub) and the framework DbContext
 * exposes the collection.
 *
 * Unlike the identity/verification tables there is no DB-only secret to hide: the
 * `public_key` (PEM) is public material, so every column is ORM-mapped. The
 * WebAuthn signature verification reads `public_key`, `algorithm` and `sign_count`
 * through the object layer ({@see ObjectPasskeyCredential}). The `algorithm`
 * column carries the COSE identifier the key was enrolled with (HIL-658) and has
 * no default: an RSA key does not say which scheme signed it, so a row that
 * forgot its algorithm would be guessed at rather than verified.
 *
 * @method static EntityPasskeyCredentials get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityPasskeyCredentials getAll()
 */
final class PasskeyCredential extends Entity
{
    public const string id = 'id';
    public const string identity_id = 'identity_id';
    public const string user_id = 'user_id';
    public const string credential_id = 'credential_id';
    public const string public_key = 'public_key';
    public const string algorithm = 'algorithm';
    public const string sign_count = 'sign_count';
    public const string transports = 'transports';
    public const string aaguid = 'aaguid';
    public const string user_handle = 'user_handle';
    public const string label = 'label';
    public const string last_used_at = 'last_used_at';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_passkey_credential';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::identity_id,
        self::user_id,
        self::credential_id,
        self::public_key,
        self::algorithm,
        self::sign_count,
        self::transports,
        self::aaguid,
        self::user_handle,
        self::label,
        self::last_used_at,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::identity_id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::credential_id => PhpType::STRING->value,
        self::public_key => PhpType::TEXT->value,
        self::algorithm => PhpType::INTEGER->value,
        self::sign_count => PhpType::INTEGER->value,
        self::transports => PhpType::STRING->value,
        self::aaguid => PhpType::STRING->value,
        self::user_handle => PhpType::BINARY->value,
        self::label => PhpType::STRING->value,
        self::last_used_at => PhpType::DATETIME->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_passkey_credential_id' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::credential_id]],
        'idx_passkey_identity' => [Entity::INDEX_COLUMNS => [self::identity_id]],
        'idx_passkey_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    // The owner is identity_id and not user_id: the credential is tied to the hilos_identity
    // anchor row, and user_id beside it is a short path to that same owner.
    public const string _setVia = self::identity_id;
    public const bool _setRoot = false;

    // A credential is a public key bound to one person's authenticator; masking it
    // would leave a usable-looking credential nobody can authenticate with.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $identity_id;
    public int $user_id;
    public string $credential_id;
    public string $public_key;
    public int $algorithm;
    public int $sign_count = 0;
    public ?string $transports = null;
    public ?string $aaguid = null;
    public string $user_handle;
    public ?string $label = null;
    public ?string $last_used_at = null;
    public string $created_at;
}
