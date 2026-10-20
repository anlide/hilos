<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests;

use Hilos\Database\Database;
use Hilos\Testing\RollbackIntegrationTestCase;

abstract class Environment extends RollbackIntegrationTestCase
{
    protected function prepareIntegrationEnvironment(): void
    {
        SchemaHelper::create();
    }

    protected function releaseIntegrationEnvironment(): void
    {
        Database::sqlRun('DROP TABLE fixture');
    }

    protected function setUpInTransaction(): void
    {
        Database::sqlRun('INSERT INTO fixture VALUES (1)');
    }

    protected function inheritedHelper(): void
    {
        Database::sqlRun('TRUNCATE TABLE fixture');
    }
}

abstract class MiddleEnvironment extends Environment
{
}

final class SchemaHelper
{
    public static function create(): void
    {
        Database::sqlRun('CREATE TABLE fixture (id INT)');
    }
}
