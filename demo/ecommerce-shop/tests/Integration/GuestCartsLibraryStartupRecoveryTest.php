<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Testing\IntegrationTest;

/** A claimed guest carts owner keeps its recovery schedule when its first reads fail. */
#[IntegrationTest]
final class GuestCartsLibraryStartupRecoveryTest extends GuestCartsLibraryEnvironment
{
    /** Closes the primary before the rollback scope sends SQL, then reconnects without starting a new owner. */
    protected function setUpInTransaction(): void
    {
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        try {
            try {
                $this->carts()->onStart();
                self::fail('The cart preload must fail on the closed primary connection');
            } catch (DatabaseConnectionException) {
                // The worker reports the hook failure and keeps this instance and its claim.
            }
            try {
                $this->carts()->onTick();
            } catch (DatabaseConnectionException) {
                // The first recovery attempt may also run while the database is still unavailable.
            }
        } finally {
            Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }

        self::emptyCatalog();
    }

    /** The scheduled tick removes a stored line absent from this owner's still-partial mirror. */
    public function testTheSameOwnerRecoversAfterItsPreloadFails(): void
    {
        $sessionId = (int)Database::sql('SELECT COALESCE(MAX(id), 0) + 1 AS absent_id FROM hilos_session')->field('absent_id');
        Database::sql(
            'INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, 1)',
            [$sessionId, self::product(ProductKind::STOCK)],
        );
        $lineId = Database::lastInsertId();

        self::assertNotNull($this->carts()->armCronRuleForTest('guest_cart_sweep_recovery'));
        $this->carts()->onTick();

        self::assertSame([], Database::sql('SELECT id FROM session_product_cart WHERE id = ?', [$lineId])->rows());
        self::assertFalse(Hilos::$db->sessionProductCarts->getObjectCollection()?->isAllLoaded());
    }
}
