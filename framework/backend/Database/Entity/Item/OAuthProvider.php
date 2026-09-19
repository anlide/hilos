<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\OAuthProviders as EntityOAuthProviders;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\Database\PhpType;

/**
 * OAuthProvider Entity - represents hilos_oauth_provider table row.
 *
 * What the administrator entered for one OAuth sign-in provider (HIL-286): the client
 * id and the requested scope, keyed by the provider key the project declares. A NULL
 * column is not configured here, and the provider falls back to its env value and then
 * to its recipe. Framework holds the contract; projects activate the table thinly
 * (copy the migration stub) and the framework DbContext exposes the collection.
 *
 * The `client_secret` column is DB-only: it is intentionally absent from _columns and
 * from the object/view ORM layer, and is read and written only through the provider
 * layer's own primitives ({@see ObjectOAuthProvider::hasClientSecret()},
 * {@see ObjectOAuthProvider::readClientSecret()}, {@see ObjectOAuthProvider::writeClientSecret()}).
 * The secret never crosses the object, view, frontend, or cross-worker sync boundary.
 *
 * @object-exclude client_secret
 *
 * @method static EntityOAuthProviders get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityOAuthProviders getAll()
 */
final class OAuthProvider extends Entity
{
    public const string id = 'id';
    public const string provider_key = 'provider_key';
    public const string client_id = 'client_id';
    /** DB-only secret column (see @object-exclude); referenced by the provider layer's queries, never ORM-mapped. */
    public const string client_secret = 'client_secret';
    public const string scope = 'scope';
    /** DB-only stamps written by the database itself; named here so the PII verdict can name them. */
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';

    public const string _table = 'hilos_oauth_provider';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::provider_key,
        self::client_id,
        self::scope,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::provider_key => PhpType::STRING->value,
        self::client_id => PhpType::STRING->value,
        self::scope => PhpType::STRING->value,
    ];

    public const array _indexes = [
        'uk_oauth_provider_key' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::provider_key]],
    ];

    // Installation configuration is cut by nobody's key.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    // The secret is the installation's credential with its provider, and a restored copy
    // has no business signing anyone in with it.
    public const array _pii = [
        self::client_secret => AnonymizationStrategy::NULLIFY,
    ];

    // The client id is what the provider issued to the installation and names nobody; the
    // key and the scope describe the provider rather than a person.
    public const array _piiNotPersonal = [
        self::id,
        self::provider_key,
        self::client_id,
        self::scope,
        self::created_at,
        self::updated_at,
    ];

    public ?int $id = null;
    public string $provider_key;
    public ?string $client_id = null;
    public ?string $scope = null;
}
