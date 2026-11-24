<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Hilos;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Utils\Helpers\RandomHelper;
use mysqli;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** The catalog seed uses TRUNCATE, so its database and files must be disposable. */
final class HilosFlowersSeedRerunTest extends TestCase
{
    /** A fresh process keeps the offline seed's connection, migration, and filesystem configuration local to this test. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRerunningTheCatalogSeedClearsProductCartsAndKeepsVouchers(): void
    {
        self::assertSame(AppEnv::TEST->value, Hilos::$env[EnvConstants::APP_ENV]->string());
        $database = 'hilos-flowers-seed-' . RandomHelper::hex(8);
        $filesRoot = sys_get_temp_dir() . '/' . $database;
        $host = Hilos::$env[EnvConstants::DB_HOST]->string();
        $port = Hilos::$env[EnvConstants::DB_PORT]->int();
        $password = Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string();
        $server = new mysqli($host, DatabaseConnectionDefaults::USER, $password, '', $port);
        $createdSchemas = [];
        try {
            foreach ([$database, ChangeLogDatabase::name($database)] as $schema) {
                $identifier = ChangeLogDatabase::identifier($schema);
                $server->query('CREATE DATABASE ' . $identifier . ' ' . DatabaseConnectionDefaults::createDatabaseCharsetClause());
                $createdSchemas[] = $identifier;
            }

            Database::configure(
                host: $host,
                user: DatabaseConnectionDefaults::USER,
                password: $password,
                database: $database,
                port: $port,
            );
            Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $migrationRoot = dirname(__DIR__, 2) . '/backend/Database/Migration';
            Migration::setMigrationListPath($migrationRoot);
            Migration::setMigrationName('Schema');
            Migration::migrateUp();
            Seed::setSeedPath($migrationRoot . '/Seed');

            Hilos::$fs = new class ($filesRoot) extends FsContext {
                /** @param string $root Temporary directory owned by this test */
                public function __construct(private readonly string $root)
                {
                }

                /** Registers only the directories used by the offline seed. */
                public function configure(): void
                {
                    $this->setTmpPath($this->root . '/tmp', DirectoryScope::CLUSTER);
                    $this->registerDirectory(self::FILES, $this->root . '/files', DirectoryScope::CLUSTER);
                }
            };
            Hilos::$fs->configure();
            Hilos::$files = null;

            self::assertTrue(Seed::applyOne('001'));
            $catalog = Database::sql('SELECT id, name FROM product ORDER BY id')->rows();
            $productId = Database::sql('SELECT id FROM product WHERE name = ?', ['Morning meadow'])->field('id');
            self::assertNotNull($productId);

            Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Flower shopper']);
            $userId = Database::lastInsertId();
            // The guest cart stores a soft session reference.
            $sessionId = 1;
            Database::sql('INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, 1)', [$sessionId, $productId]);
            Database::sql('INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, 1)', [$userId, $productId]);
            self::assertCount(1, Database::sql('SELECT id FROM session_product_cart')->rows(), 'Guest cart before rerun');
            self::assertCount(1, Database::sql('SELECT id FROM user_product_cart')->rows(), 'Account cart before rerun');

            Database::sql('INSERT INTO voucher (code, discount_percent, active) VALUES (?, 10, 1)', ['BOUQUET10']);
            $voucherId = Database::lastInsertId();
            Database::sql('INSERT INTO session_voucher_cart (session_id, voucher_id) VALUES (?, ?)', [$sessionId, $voucherId]);
            Database::sql('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $voucherId]);

            self::assertTrue(Seed::applyOne('001'));

            self::assertSame([], Database::sql('SELECT id FROM session_product_cart')->rows(), 'Guest cart after rerun');
            self::assertSame([], Database::sql('SELECT id FROM user_product_cart')->rows(), 'Account cart after rerun');
            self::assertSame($catalog, Database::sql('SELECT id, name FROM product ORDER BY id')->rows());
            self::assertSame('BOUQUET10', Database::sql('SELECT code FROM voucher WHERE id = ?', [$voucherId])->field('code'));
            self::assertSame(
                (string)$voucherId,
                Database::sql('SELECT voucher_id FROM session_voucher_cart WHERE session_id = ?', [$sessionId])->field('voucher_id'),
            );
            self::assertSame(
                (string)$voucherId,
                Database::sql('SELECT voucher_id FROM user_voucher_cart WHERE user_id = ?', [$userId])->field('voucher_id'),
            );
        } finally {
            try {
                Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
                foreach (array_reverse($createdSchemas) as $identifier) {
                    $server->query('DROP DATABASE ' . $identifier);
                }
            } finally {
                $server->close();
                self::removeSeedFiles($filesRoot);
            }
        }
    }

    /** @param string $root The temporary root for seed staging and published pictures */
    private static function removeSeedFiles(string $root): void
    {
        foreach ([$root . '/tmp', $root . '/files'] as $directory) {
            if (is_dir($directory)) {
                foreach (FsPath::entries($directory) as $file) {
                    FsPath::delete($directory . '/' . $file);
                }
                FsPath::removeDirectory($directory);
            }
        }
        FsPath::removeDirectory($root);
    }
}
