<?php

declare(strict_types=1);

namespace Demo\Pmd\Tests\Integration;

use Demo\Pmd\Database\Database;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Read-only schema observers over the database migrated by test:db-reset. */
abstract class IntegrationTestCase extends TestCase
{
    /** Initializes the test connection and project facade once for this observer class. */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        Database::initialize();
    }

    /** Opens the ordinary handler lifecycle without write claims or fixture transactions. */
    protected function setUp(): void
    {
        parent::setUp();
        Database::handlerStart();
    }

    /** Ends the handler and reports any unfinished database work. */
    protected function tearDown(): void
    {
        $owed = Database::handlerEnd();
        parent::tearDown();

        if ($owed !== []) {
            self::fail(implode(PHP_EOL, array_map(static fn(Throwable $failure): string => $failure->getMessage(), $owed)));
        }
    }
}
