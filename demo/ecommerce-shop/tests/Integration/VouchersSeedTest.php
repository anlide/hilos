<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Backup\BackupReferenceRegistry;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;

/** Mounts the shop and opens the files door before the managed rollback scope; no library, nothing written. */
abstract class VouchersSeedEnvironment extends RollbackIntegrationTestCase
{
    /** Uses the migrated and seeded test database without taking over an already mounted project context. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::initFiles();
    }
}

/**
 * The three example vouchers that Migration/Seed/002_hilos_vouchers.sql leaves after a database reset.
 *
 * The rows are found by code through the voucher iterator. The case writes nothing and does not
 * reseed, so other rows may stand beside the three. Ids and the size of the table are not checked.
 * The same case checks that voucher is a member of the shop catalog.
 */
#[IntegrationTest]
final class VouchersSeedTest extends VouchersSeedEnvironment
{
    /** Example vouchers of the seed, by code: the discount percent, then whether the code is active. */
    private const array EXAMPLES = [
        'SPRING10' => [10, true],
        'WEDDING15' => [15, true],
        'WELCOME5' => [5, false],
    ];

    /** The three example codes stand with the discount and the active flag the seed named. */
    public function testTheThreeExampleVouchersStandAsTheSeedNamedThem(): void
    {
        $foundCodes = [];
        foreach (Hilos::$db->vouchers as $voucher) {
            if (!isset(self::EXAMPLES[$voucher->code])) {
                continue;
            }
            [$discountPercent, $active] = self::EXAMPLES[$voucher->code];
            $foundCodes[$voucher->code] = true;
            self::assertSame($discountPercent, $voucher->discountPercent, $voucher->code);
            self::assertSame($active, $voucher->active, $voucher->code);
        }
        foreach (array_keys(self::EXAMPLES) as $code) {
            self::assertArrayHasKey($code, $foundCodes, $code);
        }
    }

    /** Connection 0 of the shop catalog lists voucher; the rest of the catalog and its order stay unchecked. */
    public function testTheShopCatalogListsVoucherOnConnectionZero(): void
    {
        self::assertContains('voucher', BackupReferenceRegistry::fromCatalog()->tablesForConnection(0));
    }
}
