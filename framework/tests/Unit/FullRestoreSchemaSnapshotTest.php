<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\FullRestoreSchemaSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FullRestoreSchemaSnapshotTest extends TestCase
{
    /**
     * @param string $database Actual selected schema
     * @param string $grant Direct SHOW GRANTS row
     * @param bool $expected Whether the row proves schema-wide TRIGGER
     */
    #[DataProvider('triggerGrants')]
    public function testOnlyDirectGlobalOrExactSchemaGrantsProveTriggerVisibility(string $database, string $grant, bool $expected): void
    {
        $this->assertSame($expected, new ReflectionMethod(FullRestoreSchemaSnapshot::class, 'hasSchemaPrivilege')
            ->invoke(null, [$grant], $database, 'TRIGGER'));
    }

    /** @return iterable<string, array{string, string, bool}> Literal and unsupported SHOW GRANTS spellings */
    public static function triggerGrants(): iterable
    {
        yield 'global trigger' => ['target', 'GRANT TRIGGER ON *.* TO `app`@`%`', true];
        yield 'global all' => ['target', 'GRANT ALL PRIVILEGES ON *.* TO `app`@`%`', true];
        yield 'schema all' => ['target', 'GRANT ALL PRIVILEGES ON `target`.* TO `app`@`%`', true];
        yield 'schema trigger' => ['target', 'GRANT SELECT, TRIGGER ON `target`.* TO `app`@`%`', true];
        yield 'unquoted' => ['target', 'GRANT TRIGGER ON target.* TO `app`@`%`', true];
        yield 'quoted backtick' => ['tar`get', 'GRANT TRIGGER ON `tar``get`.* TO `app`@`%`', true];
        yield 'escaped underscore' => ['tar_get', 'GRANT TRIGGER ON `tar\_get`.* TO `app`@`%`', true];
        yield 'escaped percent' => ['tar%get', 'GRANT TRIGGER ON `tar\%get`.* TO `app`@`%`', true];
        yield 'escaped backslash' => ['tar\get', 'GRANT TRIGGER ON `tar\\\\get`.* TO `app`@`%`', true];
        yield 'wrong schema' => ['target', 'GRANT TRIGGER ON `outside`.* TO `app`@`%`', false];
        yield 'table only' => ['target', 'GRANT TRIGGER ON `target`.`carried` TO `app`@`%`', false];
        yield 'select only' => ['target', 'GRANT SELECT ON `target`.* TO `app`@`%`', false];
        yield 'role' => ['target', 'GRANT `TRIGGER` TO `app`@`%`', false];
        yield 'wildcard only' => ['target', 'GRANT TRIGGER ON `tar%`.* TO `app`@`%`', false];
        yield 'underscore pattern' => ['tar_get', 'GRANT TRIGGER ON `tar_get`.* TO `app`@`%`', false];
        yield 'unparsed syntax' => ['target', 'GRANT TRIGGER ON DATABASE `target` TO `app`@`%`', false];
    }
}
