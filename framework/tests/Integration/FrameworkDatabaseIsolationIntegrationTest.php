<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises consecutive fixture lifecycles in the same PHPUnit process. */
final class FrameworkDatabaseIsolationIntegrationTest extends TestCase
{
    /**
     * @return array<string, array{bool}> A live auxiliary link and an already closed one
     */
    public static function auxiliaryConnections(): array
    {
        return ['open secondary selected' => [false], 'closed secondary selected' => [true]];
    }

    /**
     * @param bool $closeSecondary Whether the first fixture closes its auxiliary link itself
     * @throws HilosException When fixture setup or the receipt callback fails
     */
    #[DataProvider('auxiliaryConnections')]
    public function testAuxiliaryConnectionDoesNotEnableTheNextFixturesJournal(bool $closeSecondary): void
    {
        $fixture = new FrameworkDatabaseIsolationHarness('fixture');
        $fixture->open();
        try {
            $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::configure(1, $primary->host, $primary->user, $primary->password,
                $primary->database, $primary->port, $primary->charset);
            Database::connect(1);
            Database::useConnection(1);
            if ($closeSecondary) {
                Database::close(1);
            }
        } finally {
            $fixture->close();
        }

        self::assertFalse(Database::isConnected(DatabaseConnectionDefaults::PRIMARY_INDEX));
        self::assertFalse(Database::isConnected(1));
        self::assertSame([], Database::getConfiguredIndices());
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());

        $fixture->open();
        try {
            self::assertSame([DatabaseConnectionDefaults::PRIMARY_INDEX], Database::getConfiguredIndices());
            self::assertSame('callback ran', JournalReceiptScope::run(
                JournalReceiptData::cron('fixture-sweep', '* * * * *', 'fixture'),
                static function (): string {
                    self::assertNull(JournalReceiptScope::currentId());
                    return 'callback ran';
                },
            ));
        } finally {
            $fixture->close();
        }
    }
}

/** Exposes the actual integration base lifecycle without running a second PHPUnit suite. */
final class FrameworkDatabaseIsolationHarness extends FrameworkIntegrationTestCase
{
    /** @throws HilosException When the primary connection cannot be prepared */
    public function open(): void
    {
        parent::setUp();
    }

    /** Closes the fixture as PHPUnit does after either a passing or a failing test. */
    public function close(): void
    {
        parent::tearDown();
    }
}
