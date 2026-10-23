<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusIncidents as EntityStatusIncidents;
use Hilos\Database\PhpType;
use Hilos\Database\SqlSortDirection;

/**
 * Durable candidate and incident history owned by the cluster Status agent.
 *
 * @method static EntityStatusIncidents get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusIncidents getAll()
 */
class StatusIncident extends Entity
{
    public const string id = 'id';
    public const string component_key = 'component_key';
    public const string component_name = 'component_name';
    public const string active_component_key = 'active_component_key';
    public const string severity = 'severity';
    public const string peak_severity = 'peak_severity';
    public const string detected_at = 'detected_at';
    public const string publish_at = 'publish_at';
    public const string published_at = 'published_at';
    public const string suppressed_at = 'suppressed_at';
    public const string resolved_at = 'resolved_at';
    public const string outcome = 'outcome';

    public const string _table = 'hilos_status_incident';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::component_key,
        self::component_name,
        self::active_component_key,
        self::severity,
        self::peak_severity,
        self::detected_at,
        self::publish_at,
        self::published_at,
        self::suppressed_at,
        self::resolved_at,
        self::outcome,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::component_key => PhpType::STRING->value,
        self::component_name => PhpType::STRING->value,
        self::active_component_key => PhpType::STRING->value,
        self::severity => PhpType::STRING->value,
        self::peak_severity => PhpType::STRING->value,
        self::detected_at => PhpType::INTEGER->value,
        self::publish_at => PhpType::INTEGER->value,
        self::published_at => PhpType::INTEGER->value,
        self::suppressed_at => PhpType::INTEGER->value,
        self::resolved_at => PhpType::INTEGER->value,
        self::outcome => PhpType::STRING->value,
    ];
    public const array _foreign = [];
    public const array _indexes = [
        'uk_status_incident_active_component' => [
            Entity::INDEX_COLUMNS => [self::active_component_key],
            Entity::INDEX_UNIQUE => true,
        ],
        'idx_status_incident_component_detected' => [
            Entity::INDEX_COLUMNS => [
                self::component_key,
                [Entity::INDEX_COLUMN => self::detected_at, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
                [Entity::INDEX_COLUMN => self::id, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
            ],
        ],
        'idx_status_incident_published' => [Entity::INDEX_COLUMNS => [self::published_at, self::id]],
        'idx_status_incident_resolved' => [Entity::INDEX_COLUMNS => [self::resolved_at, self::id]],
    ];
    public const string _setVia = self::id;
    public const bool _setRoot = true;
    public const array _pii = [];
    public const array _piiNotPersonal = [
        self::id,
        self::component_key,
        self::component_name,
        self::active_component_key,
        self::severity,
        self::peak_severity,
        self::detected_at,
        self::publish_at,
        self::published_at,
        self::suppressed_at,
        self::resolved_at,
        self::outcome,
    ];

    public ?int $id = null;
    public string $component_key;
    public string $component_name;
    public ?string $active_component_key = null;
    public string $severity;
    public ?string $peak_severity = null;
    public int $detected_at;
    public int $publish_at;
    public ?int $published_at = null;
    public ?int $suppressed_at = null;
    public ?int $resolved_at = null;
    public ?string $outcome = null;
}
