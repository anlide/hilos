<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseSql;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\MysqlClientErrorCode;
use Hilos\Utils\Helpers\RandomHelper;
use mysqli;

/** Kills the owner's live primary outside the rollback harness, using a connection that survives the fault. */
final class GuestCartsLibraryConnectionRecoveryTest extends IntegrationTestCase
{
    private const string RECOVERY_RULE = 'guest_cart_sweep_recovery';

    /** TCP port with no listener in the test container. */
    private const int CLOSED_PORT = 1;

    public function testSameOwnerResumesItsPendingPageAfterPrimaryLossAndRefusedOpen(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $observer = new mysqli($primary->host, $primary->user, $primary->password, $primary->database, $primary->port);
        $carts = new GuestCartsLibraryAgent();
        $first = (int)$observer->query(
            'SELECT GREATEST((SELECT COALESCE(MAX(id), 0) FROM hilos_session),'
            . ' (SELECT COALESCE(MAX(session_id), 0) FROM session_product_cart),'
            . ' (SELECT COALESCE(MAX(session_id), 0) FROM session_voucher_cart)) + 1',
        )->fetch_row()[0];
        $last = $first + 25;
        $late = $last + 1;
        $productId = (int)$observer->query('SELECT id FROM product ORDER BY id LIMIT 1')->fetch_row()[0];
        $observer->execute_query('INSERT INTO voucher (code, discount_percent, active) VALUES (?, 10, 1)', [RandomHelper::hex(8)]);
        $voucherId = (int)$observer->insert_id;
        $restorePrimary = false;

        try {
            for ($sessionId = $first; $sessionId <= $last; $sessionId++) {
                self::insertCart($observer, $sessionId, $productId, $voucherId);
            }
            Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::sessionProductCarts, EcommerceShopDbContext::sessionVoucherCarts);
            $this->underAgent($carts, fn () => $this->startAgent($carts));
            $rule = $carts->armCronRuleForTest(self::RECOVERY_RULE);
            self::assertNotNull($rule);
            $rule->pauseSchedule(self::class);
            $this->underAgent($carts, $carts->onTick(...));
            self::assertCartKeys($observer, $first, $late, [$last]);
            self::insertCart($observer, $late, $productId, $voucherId);

            $threadId = self::threadId(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $returns = Database::returnsAfterLoss();
            $observer->query("KILL {$threadId}");
            try {
                $this->runScheduledTick($carts);
                self::fail('The tick must report the killed link instead of replaying the page');
            } catch (DatabaseRuntimeException $failure) {
                self::assertTrue(MysqlClientErrorCode::isConnectionLost($failure->getCode()));
                self::assertStringContainsString('session_product_cart', $failure->getQuery());
            }
            self::assertFalse(Database::isConnected(), 'The failed query must leave an empty slot');
            self::assertSame($returns, Database::returnsAfterLoss());
            self::assertCartKeys($observer, $first, $late, [$last, $late]);

            $restorePrimary = true;
            self::configurePrimary($primary, '127.0.0.1', self::CLOSED_PORT);
            try {
                $this->runScheduledTick($carts);
                self::fail('The tick must report the refused open');
            } catch (CantConnectToMysqlServerException) {
                self::assertFalse(Database::isConnected());
            }
            $this->underAgent($carts, $carts->onTick(...));
            self::assertCartKeys($observer, $first, $late, [$last, $late]);

            self::configurePrimary($primary, $primary->host, $primary->port);
            $restorePrimary = false;
            $this->runScheduledTick($carts);
            self::assertSame($returns + 1, Database::returnsAfterLoss());
            self::assertCartKeys($observer, $first, $late, [$late]);
        } finally {
            if ($restorePrimary) {
                self::configurePrimary($primary, $primary->host, $primary->port);
            }
            TruthSourceRegistry::unregisterAgent($carts->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($carts->getId()));
            $observer->execute_query('DELETE FROM session_product_cart WHERE product_id = ? AND session_id BETWEEN ? AND ?', [
                $productId, $first, $late,
            ]);
            $observer->execute_query('DELETE FROM session_voucher_cart WHERE voucher_id = ?', [$voucherId]);
            $observer->execute_query('DELETE FROM voucher WHERE id = ?', [$voucherId]);
            $observer->close();
            Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::sessionProductCarts, EcommerceShopDbContext::sessionVoucherCarts);
        }
    }

    /** The receipt pings through primary even when the separate journal link is unavailable. */
    public function testReceiptPingReportsLossBeforeRunningItsCallback(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $observer = new mysqli($primary->host, $primary->user, $primary->password, $primary->database, $primary->port);
        $primaryThread = self::threadId(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $journalThread = self::threadId(ChangeLogDatabase::CONNECTION_INDEX);
        self::assertNotSame($primaryThread, $journalThread);
        try {
            $observer->query("KILL {$journalThread}");
            $pingJournal = static fn () => Database::withoutReconnectRetries(
                static fn () => Database::sql(DatabaseSql::PING, index: ChangeLogDatabase::CONNECTION_INDEX),
                ChangeLogDatabase::CONNECTION_INDEX,
            );
            self::assertLostPing($pingJournal);
            self::assertFalse(Database::isConnected(ChangeLogDatabase::CONNECTION_INDEX));
            self::assertSame($primaryThread, self::threadId(DatabaseConnectionDefaults::PRIMARY_INDEX));

            $observer->query("KILL {$primaryThread}");
            $receipt = JournalReceiptData::agent(self::RECOVERY_RULE, GuestCartsLibraryAgent::AGENT_TYPE);
            $runReceipt = static fn () => Database::withoutReconnectRetries(
                static fn () => JournalReceiptScope::run($receipt, static function (): int {
                    $receiptId = JournalReceiptScope::currentId();
                    self::assertNotNull($receiptId);
                    self::assertSame($receiptId, (int)Database::sql('SELECT @hilos_receipt AS receipt')->field('receipt'));

                    return $receiptId;
                }),
                DatabaseConnectionDefaults::PRIMARY_INDEX,
                ChangeLogDatabase::CONNECTION_INDEX,
            );
            self::assertLostPing($runReceipt);
            self::assertFalse(Database::isConnected());
            self::assertNull(JournalReceiptScope::currentId());

            self::assertIsInt($runReceipt());
            self::assertNotSame($primaryThread, self::threadId(DatabaseConnectionDefaults::PRIMARY_INDEX));
            self::assertNull(JournalReceiptScope::currentId());
            self::assertFalse(Database::isConnected(ChangeLogDatabase::CONNECTION_INDEX));
            $pingJournal();
            self::assertNotSame($journalThread, self::threadId(ChangeLogDatabase::CONNECTION_INDEX));
        } finally {
            $observer->close();
        }
    }

    private static function assertLostPing(callable $operation): void
    {
        try {
            $operation();
            self::fail('The ping must report the killed link before continuing its operation');
        } catch (DatabaseRuntimeException $failure) {
            self::assertTrue(MysqlClientErrorCode::isConnectionLost($failure->getCode()));
            self::assertSame(DatabaseSql::PING, $failure->getQuery());
        }
    }

    private static function threadId(int $index): int
    {
        return (int)Database::sql('SELECT CONNECTION_ID() AS thread_id', index: $index)->field('thread_id');
    }

    private function runScheduledTick(GuestCartsLibraryAgent $carts): void
    {
        self::assertNotNull($carts->armCronRuleForTest(self::RECOVERY_RULE));
        $this->underAgent($carts, $carts->onTick(...));
    }

    private static function insertCart(mysqli $connection, int $sessionId, int $productId, int $voucherId): void
    {
        $connection->execute_query(
            'INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, 1)',
            [$sessionId, $productId],
        );
        $connection->execute_query('INSERT INTO session_voucher_cart (session_id, voucher_id) VALUES (?, ?)', [$sessionId, $voucherId]);
    }

    /** @param list<int> $expected Remaining fixture session keys in each table */
    private static function assertCartKeys(mysqli $connection, int $first, int $last, array $expected): void
    {
        foreach (['session_product_cart', 'session_voucher_cart'] as $table) {
            $rows = $connection->execute_query(
                "SELECT session_id FROM {$table} WHERE session_id BETWEEN ? AND ? ORDER BY session_id",
                [$first, $last],
            )->fetch_all();
            self::assertSame($expected, array_column($rows, 0), $table);
        }
    }

    private static function configurePrimary(DatabaseConnectionConfig $original, string $host, int $port): void
    {
        Database::configure(
            host: $host,
            user: $original->user,
            password: $original->password,
            database: $original->database,
            port: $port,
            charset: $original->charset,
            socket: $original->socket,
            reconnectAttempts: $original->reconnectAttempts,
            reconnectDelay: $original->reconnectDelay,
        );
    }
}
