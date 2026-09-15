<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\SqlIndexType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the method that reads the declared index type of an index definition.
 */
final class EntityIndexTypeTest extends TestCase
{
    public function testAnIndexOmittingTypeDefaultsToBtree(): void
    {
        $this->assertSame(SqlIndexType::BTREE, Entity::indexType([Entity::INDEX_COLUMNS => ['channel']]));
    }

    public function testAnIndexDeclaringFulltextReadsAsFulltext(): void
    {
        $this->assertSame(
            SqlIndexType::FULLTEXT,
            Entity::indexType([
                Entity::INDEX_COLUMNS => ['message'],
                Entity::INDEX_TYPE => SqlIndexType::FULLTEXT,
            ]),
        );
    }

    public function testAnIndexTypeOutsideTheVocabularyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Index type must be BTREE, FULLTEXT, HASH or RTREE, got 'GIN'");

        Entity::indexType([
            Entity::INDEX_COLUMNS => ['data'],
            Entity::INDEX_TYPE => 'GIN',
        ]);
    }
}
