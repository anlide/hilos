<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\LegalAcceptances as EntityLegalAcceptances;
use Hilos\Database\PhpType;

/**
 * One immutable acceptance, erased with the account that gave it (HIL-498).
 *
 * @method static EntityLegalAcceptances get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityLegalAcceptances getAll()
 */
final class LegalAcceptance extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string document = 'document';
    public const string revision_id = 'revision_id';
    public const string accepted_at = 'accepted_at';

    public const string _table = 'hilos_legal_acceptance';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::user_id, self::document, self::revision_id, self::accepted_at];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::document => PhpType::STRING->value,
        self::revision_id => PhpType::STRING->value,
        self::accepted_at => PhpType::DATETIME->value,
    ];
    public const array _indexes = [
        'uk_legal_acceptance_user_document_revision' => [
            Entity::INDEX_COLUMNS => [self::user_id, self::document, self::revision_id],
            Entity::INDEX_UNIQUE => true,
        ],
    ];
    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // Account ids, document/revision keys and timestamps identify no person on their own.
    public const array _pii = [];
    public const array _piiNotPersonal = [self::id, self::user_id, self::document, self::revision_id, self::accepted_at];

    public ?int $id = null;
    public int $user_id;
    public string $document;
    public string $revision_id;
    public string $accepted_at;
}
