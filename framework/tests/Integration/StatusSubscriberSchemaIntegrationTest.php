<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\Entity\Item\StatusSubscriber;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Schema\EntitySchemaAudit;
use PHPUnit\Framework\Attributes\DataProvider;

/** DDL runs outside rollback scopes; every generated demo pair is exercised on a pre-upgrade table. */
final class StatusSubscriberSchemaIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Connects only to the framework test database and installs the preceding auth-block shape. */
    protected function setUp(): void
    {
        parent::setUp();
        $sql = file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_auth_block.sql');
        Database::sqlRun(str_replace("ENUM('ip', 'session', 'email')", "ENUM('ip', 'session')", $sql));
    }

    /** Removes the fixture tables even when one migration direction fails. */
    protected function tearDown(): void
    {
        try {
            Database::sqlRun('DROP TABLE IF EXISTS `hilos_status_subscriber`; DROP TABLE IF EXISTS `hilos_auth_block`;');
        } finally {
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{string}> Demo tracks that received both new pairs */
    public static function projects(): iterable
    {
        foreach (['chat', 'binance-btc-tracker', 'tasks', 'polls', 'ecommerce-shop', 'online-testing'] as $project) {
            yield $project => [$project];
        }
    }

    /** @param string $project Demo migration track */
    #[DataProvider('projects')]
    public function testGeneratedUpgradeAndDownPreserveIpAndSessionRows(string $project): void
    {
        $root = dirname(__DIR__, 3) . '/demo/' . $project . '/backend/Database/Migration/Schema/';
        $subscriberUp = glob($root . '*_framework_install_status_subscriber.sql');
        $scopeUp = glob($root . '*_framework_change_be7c208b2260f84bd9588c1650acd50da2ee9b04bc2bda228dfcb69c106a0fe6.sql');
        self::assertCount(1, $subscriberUp);
        self::assertCount(1, $scopeUp);
        $catalog = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog());
        foreach ([$subscriberUp[0], $scopeUp[0]] as $up) {
            $origin = $catalog->verifyPair(file_get_contents($up), file_get_contents(substr($up, 0, -4) . '_down.sql'));
            self::assertContains($origin->kind, [FrameworkMigrationOrigin::INSTALL, FrameworkMigrationOrigin::CHANGE]);
        }
        Database::sqlRun("INSERT INTO `hilos_auth_block` (`scope`, `identity`, `action`) VALUES ('ip', '127.0.0.1', 'subscribe'),"
            . " ('session', 'session-hash', 'subscribe');");
        Database::sqlRun(file_get_contents($subscriberUp[0]));
        Database::sqlRun(file_get_contents($scopeUp[0]));
        Database::sqlRun("INSERT INTO `hilos_auth_block` (`scope`, `identity`, `action`) VALUES ('email', 'email-hash', 'subscribe');");
        Database::sqlRun("INSERT INTO `hilos_status_subscriber` (`email`, `subscribed_at`) VALUES ('schema@example.org', 1000);");
        self::assertSame([], EntitySchemaAudit::audit([StatusSubscriber::class]));
        $row = Database::sql('SELECT * FROM `hilos_status_subscriber`')->first()->first();
        self::assertSame(0, (int)$row['confirmed']);
        self::assertNull($row['confirmation_token_hash']);
        self::assertNull($row['unsubscribe_token']);
        self::assertNull($row['confirmed_at']);
        Database::sqlRun(file_get_contents(substr($scopeUp[0], 0, -4) . '_down.sql'));
        $scopes = Database::sql('SELECT `scope` FROM `hilos_auth_block` ORDER BY `id`')->first()->rows();
        self::assertSame([['scope' => 'ip'], ['scope' => 'session']], $scopes);
        $column = Database::sql("SHOW COLUMNS FROM `hilos_auth_block` LIKE 'scope'")->first()->first();
        self::assertSame("enum('ip','session')", $column['Type']);
        Database::sqlRun(file_get_contents($scopeUp[0]));
        Database::sqlRun(file_get_contents(substr($scopeUp[0], 0, -4) . '_down.sql'));
        Database::sqlRun(file_get_contents(substr($subscriberUp[0], 0, -4) . '_down.sql'));
        self::assertSame([], Database::sql("SHOW TABLES LIKE 'hilos_status_subscriber'")->first()->rows());
    }
}
