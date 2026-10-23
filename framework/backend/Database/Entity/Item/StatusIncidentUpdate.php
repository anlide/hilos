<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusIncidentUpdates as EntityStatusIncidentUpdates;
use Hilos\Database\PhpType;

/**
 * Immutable timeline of published incident events and team updates.
 *
 * @method static EntityStatusIncidentUpdates get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusIncidentUpdates getAll()
 */
class StatusIncidentUpdate extends Entity
{
    public const string id = 'id';
    public const string incident_id = 'incident_id';
    public const string kind = 'kind';
    public const string severity = 'severity';
    public const string text = 'text';
    public const string at = 'at';
    public const string request_id = 'request_id';

    public const string _table = 'hilos_status_incident_update';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::incident_id,
        self::kind,
        self::severity,
        self::text,
        self::at,
        self::request_id,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::incident_id => PhpType::INTEGER->value,
        self::kind => PhpType::STRING->value,
        self::severity => PhpType::STRING->value,
        self::text => PhpType::TEXT->value,
        self::at => PhpType::INTEGER->value,
        self::request_id => PhpType::STRING->value,
    ];
    public const array _foreign = [self::incident_id => StatusIncident::_table];
    public const array _indexes = [
        'idx_status_incident_update_timeline' => [Entity::INDEX_COLUMNS => [self::incident_id, self::at, self::id]],
        'uk_status_incident_update_request' => [
            Entity::INDEX_COLUMNS => [self::incident_id, self::request_id],
            Entity::INDEX_UNIQUE => true,
        ],
    ];
    public const string _setVia = self::incident_id;
    public const bool _setRoot = false;
    public const array _pii = [self::text => AnonymizationStrategy::MASK];
    public const array _piiNotPersonal = [
        self::id,
        self::incident_id,
        self::kind,
        self::severity,
        self::at,
        self::request_id,
    ];

    public ?int $id = null;
    public int $incident_id;
    public string $kind;
    public string $severity;
    public string $text;
    public int $at;
    public ?string $request_id = null;
}
