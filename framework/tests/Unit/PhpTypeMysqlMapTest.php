<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\PhpType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the strict PhpType-to-MySQL reference table
 * ({@see PhpType::forMysqlType()}) used by the Entity schema audit (HIL-503).
 */
final class PhpTypeMysqlMapTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<PhpType>}> Raw MySQL type and its accepted PhpTypes
     */
    public static function mysqlTypeProvider(): iterable
    {
        yield 'tinyint(1) is boolean' => ['tinyint(1)', [PhpType::BOOLEAN]];
        yield 'wider tinyint is integer' => ['tinyint(3) unsigned', [PhpType::INTEGER]];
        yield 'double accepts float or double' => ['double', [PhpType::FLOAT, PhpType::DOUBLE]];
        yield 'timestamp is datetime' => ['timestamp', [PhpType::DATETIME]];
        yield 'varbinary is binary' => ['varbinary(64)', [PhpType::BINARY]];
        yield 'longtext is text or json (MariaDB JSON alias)' => ['longtext', [PhpType::TEXT, PhpType::JSON]];
        yield 'enum is string' => ["enum('a','b')", [PhpType::STRING]];
        yield 'unknown type maps to nothing' => ['geometry', []];
    }

    /**
     * @param string $mysqlType Raw MySQL column type
     * @param list<PhpType> $expected Accepted PhpTypes
     */
    #[DataProvider('mysqlTypeProvider')]
    public function testForMysqlTypeMapsToAcceptedPhpTypes(string $mysqlType, array $expected): void
    {
        $this->assertSame($expected, PhpType::forMysqlType($mysqlType));
    }
}
