<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the object layer refuses to search by (HIL-821).
 *
 * Both refusals are decided before a statement is assembled, which is why they can be asked here
 * rather than of a live database: the name in a declaration becomes an SQL identifier, so this
 * layer answers for it itself instead of trusting whoever built the query.
 */
final class ObjectsSearchScopeTest extends TestCase
{
    /**
     * @throws DatabaseException When the query reaches the database, which this case does not
     */
    public function testAFieldNamingNoColumnOfTheEntityIsRefused(): void
    {
        $this->expectException(TableSearchFieldUnknownException::class);

        SearchScopeTestObjects::initEmpty()->containsRow(
            new TableQueryDTO(search: 'beta', searchableFields: [SearchScopeTestEntity::label => 'no_such_column']),
            1,
        );
    }

    /**
     * @throws DatabaseException When the query reaches the database, which this case does not
     */
    public function testATermArrivingWithNothingDeclaredIsRefused(): void
    {
        // Unreachable through a table, whose own gate refuses first; here to keep a path that
        // skipped the gate from searching every row instead of saying so.
        $this->expectException(TableSearchNotSupportedException::class);

        SearchScopeTestObjects::initEmpty()->containsRow(new TableQueryDTO(search: 'beta'), 1);
    }
}

/**
 * Entity the refusals are asked about; no table of this name is ever raised.
 */
final class SearchScopeTestEntity extends Entity
{
    public const string id = 'id';
    public const string label = 'label';

    public const string _table = 'hilos_fw_test_search_scope';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::label,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::label => PhpType::STRING->value,
    ];

    public ?int $id = null;
    public string $label = '';
}

/**
 * Minimal stored row over that entity.
 */
final class SearchScopeTestObject extends Object_
{
    public const string ENTITY_CLASS = SearchScopeTestEntity::class;
}

/**
 * @extends Objects<SearchScopeTestObject>
 */
final class SearchScopeTestObjects extends Objects
{
    public const string OBJECT_CLASS = SearchScopeTestObject::class;
}
