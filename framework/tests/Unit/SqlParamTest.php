<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\SqlBindType;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SqlBindType, SqlParam, and SqlParamCollection bind typing.
 */
final class SqlParamTest extends TestCase
{
    /**
     * SqlBindType backed values match mysqli bind_param specifiers.
     */
    public function testSqlBindTypeValuesMatchMysqliSpecifiers(): void
    {
        $this->assertSame('i', SqlBindType::INTEGER->value);
        $this->assertSame('d', SqlBindType::DOUBLE->value);
        $this->assertSame('s', SqlBindType::STRING->value);
        $this->assertSame('b', SqlBindType::BLOB->value);
    }

    /**
     * isNumeric() is true only for integer and double bind types.
     */
    public function testSqlBindTypeIsNumericOnlyForIntegerAndDouble(): void
    {
        $this->assertTrue(SqlBindType::INTEGER->isNumeric());
        $this->assertTrue(SqlBindType::DOUBLE->isNumeric());
        $this->assertFalse(SqlBindType::STRING->isNumeric());
        $this->assertFalse(SqlBindType::BLOB->isNumeric());
    }

    /**
     * SqlParam defaults to STRING bind type when type is omitted.
     */
    public function testSqlParamDefaultsToStringBindType(): void
    {
        $param = new SqlParam('hello');

        $this->assertSame('hello', $param->value);
        $this->assertSame(SqlBindType::STRING, $param->type);
    }

    /**
     * Named factories set the expected bind type and value.
     */
    public function testSqlParamFactoriesSetExpectedTypeAndValue(): void
    {
        $this->assertSame(SqlBindType::INTEGER, SqlParam::int(42)->type);
        $this->assertSame(42, SqlParam::int(42)->value);

        $this->assertSame(SqlBindType::DOUBLE, SqlParam::double(1.5)->type);
        $this->assertSame(1.5, SqlParam::double(1.5)->value);

        $this->assertSame(SqlBindType::STRING, SqlParam::string('text')->type);
        $this->assertSame('text', SqlParam::string('text')->value);

        $this->assertSame(SqlBindType::BLOB, SqlParam::blob("\x00blob")->type);
        $this->assertSame("\x00blob", SqlParam::blob("\x00blob")->value);
    }

    /**
     * bool() stores 0/1 as integer bind type.
     */
    public function testSqlParamBoolStoresZeroOrOneAsInteger(): void
    {
        $this->assertSame(SqlBindType::INTEGER, SqlParam::bool(true)->type);
        $this->assertSame(1, SqlParam::bool(true)->value);

        $this->assertSame(SqlBindType::INTEGER, SqlParam::bool(false)->type);
        $this->assertSame(0, SqlParam::bool(false)->value);
    }

    /**
     * auto() infers bind type from PHP value shape.
     */
    public function testSqlParamAutoInfersBindTypeFromValue(): void
    {
        $this->assertSame(SqlBindType::INTEGER, SqlParam::auto(7)->type);
        $this->assertSame(SqlBindType::DOUBLE, SqlParam::auto(2.5)->type);
        $this->assertSame(SqlBindType::INTEGER, SqlParam::auto(true)->type);
        $this->assertSame(SqlBindType::STRING, SqlParam::auto(null)->type);
        $this->assertSame(SqlBindType::STRING, SqlParam::auto('x')->type);
    }

    /**
     * SqlParamCollection::getTypeString() concatenates bind type chars in order.
     */
    public function testSqlParamCollectionGetTypeStringConcatenatesBindTypes(): void
    {
        $collection = SqlParamCollection::fromArray([
            SqlParam::int(1),
            SqlParam::string('a'),
            SqlParam::double(2.0),
        ]);

        $this->assertSame('isd', $collection->getTypeString());
    }

    /**
     * fromArray() preserves SqlParam instances and wraps scalars with auto().
     */
    public function testSqlParamCollectionFromArrayPreservesAndWrapsValues(): void
    {
        $existing = SqlParam::blob('raw');
        $collection = SqlParamCollection::fromArray([$existing, 10, 'q']);

        $this->assertCount(3, $collection);
        $this->assertSame($existing, $collection[0]);
        $this->assertSame(SqlBindType::INTEGER, $collection[1]->type);
        $this->assertSame(10, $collection[1]->value);
        $this->assertSame(SqlBindType::STRING, $collection[2]->type);
        $this->assertSame('q', $collection[2]->value);
        $this->assertSame('bis', $collection->getTypeString());
    }
}
