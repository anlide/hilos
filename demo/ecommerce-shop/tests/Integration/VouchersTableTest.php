<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\Voucher\VouchersTable;
use Demo\EcommerceShop\Tables\Voucher\VoucherTableRow;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The vouchers table over the real shop schema: its window and code order, search, the Active facet
 * and its counts, and the row a voucher fact redraws. The rollback scope proves reads of written
 * rows, not commit. Vouchers are written through the library, under the frame the environment holds.
 */
#[IntegrationTest]
final class VouchersTableTest extends VouchersLibraryEnvironment
{
    /**
     * The default window orders this case's codes from A to Z and keeps one that is switched off.
     *
     * A row carries four fields of their stored types, and the browser envelope puts that row under the voucher slot.
     */
    public function testTheWindowOrdersCodesFromAToZIncludingOneSwitchedOff(): void
    {
        $prefix = RandomHelper::hex(8);
        $early = Hilos::$db->vouchers->actions->create($prefix . 'a', 11, true);
        $off = Hilos::$db->vouchers->actions->create($prefix . 'm', 22, false);
        $late = Hilos::$db->vouchers->actions->create($prefix . 'z', 33, true);
        $table = self::vouchersTable();

        $rows = $table->getPage(self::windowQuery($table, $prefix))->rows;
        $offRow = $rows[1] ?? null;
        self::assertInstanceOf(VoucherTableRow::class, $offRow);
        $offPayload = $offRow->toArray();

        self::assertSame(
            [VoucherTableRow::id, VoucherTableRow::code, VoucherTableRow::discountPercent, VoucherTableRow::active],
            array_keys($offPayload),
        );
        self::assertIsInt($offPayload[VoucherTableRow::id]);
        self::assertIsString($offPayload[VoucherTableRow::code]);
        self::assertIsInt($offPayload[VoucherTableRow::discountPercent]);
        self::assertIsBool($offPayload[VoucherTableRow::active]);
        self::assertFalse($offPayload[VoucherTableRow::active]);
        self::assertSame([self::row($early), self::row($off), self::row($late)], self::payloads($rows));
        self::assertSame([
            BrowserPageSignalData::rowKey => $offRow->getRowKey(),
            BrowserPageSignalData::sources => [
                VouchersTable::ROW_SLOT => $offPayload,
            ],
        ], $table->browserRow($offRow));
    }

    /**
     * A term in another case than the stored code matches at its start or in its middle.
     *
     * A term this case never stored matches nothing. An empty search leaves the case's vouchers in place.
     */
    public function testASearchKeepsVouchersWhoseCodeHoldsTheTerm(): void
    {
        $prefix = RandomHelper::hex(8);
        $lead = Hilos::$db->vouchers->actions->create($prefix . 'lead', 10, true);
        $rest = Hilos::$db->vouchers->actions->create($prefix . 'rest', 15, true);
        $middle = Hilos::$db->vouchers->actions->create('qq' . $prefix . 'tail', 20, false);
        $leadId = self::voucherId($lead);
        $restId = self::voucherId($rest);
        $middleId = self::voucherId($middle);
        $ordered = [$leadId, $restId, $middleId];
        $table = self::vouchersTable();
        $keysFor = static fn (string $term): array => self::keys($table, self::windowQuery($table, $term));

        self::assertSame($ordered, $keysFor($prefix));
        self::assertSame([$leadId], $keysFor($prefix . 'le'));
        self::assertSame([$middleId], $keysFor('q' . $prefix));
        self::assertSame([], $keysFor($prefix . 'none'));
        self::assertSame($ordered, self::caseKeys(self::keys($table, self::windowQuery($table, '')), $ordered));
    }

    /**
     * Active true, false and Any narrow alone and with the prefix search.
     *
     * A non-canonical value empties the window. Each Active option is counted with the search kept and
     * only that option in place of the current filter, and the count is exact.
     */
    public function testActiveNarrowsAloneAndWithTheSearchAndAWrongValueEmptiesTheWindow(): void
    {
        $prefix = RandomHelper::hex(8);
        $first = Hilos::$db->vouchers->actions->create($prefix . 'a', 10, true);
        $second = Hilos::$db->vouchers->actions->create($prefix . 'b', 20, true);
        $third = Hilos::$db->vouchers->actions->create($prefix . 'c', 30, false);
        $fourth = Hilos::$db->vouchers->actions->create($prefix . 'd', 40, false);
        Hilos::$db->vouchers->actions->create('x' . RandomHelper::hex(8), 50, true);
        $all = [self::voucherId($first), self::voucherId($second), self::voucherId($third), self::voucherId($fourth)];
        $active = [$all[0], $all[1]];
        $inactive = [$all[2], $all[3]];
        $table = self::vouchersTable();
        $keysFor = static fn (array $filter): array => self::keys($table, self::windowQuery($table, $prefix, $filter));
        $alone = static fn (array $filter): array => self::caseKeys(
            self::keys($table, self::windowQuery($table, null, $filter)),
            $all,
        );

        self::assertSame($all, $alone([]));
        self::assertSame($all, $alone([VouchersTable::FILTER_ACTIVE => null]));
        self::assertSame($all, $alone([VouchersTable::FILTER_ACTIVE => '']));
        self::assertSame($active, $alone([VouchersTable::FILTER_ACTIVE => true]));
        self::assertSame($inactive, $alone([VouchersTable::FILTER_ACTIVE => false]));

        self::assertSame($all, $keysFor([]));
        self::assertSame($all, $keysFor([VouchersTable::FILTER_ACTIVE => null]));
        self::assertSame($all, $keysFor([VouchersTable::FILTER_ACTIVE => '']));
        self::assertSame($active, $keysFor([VouchersTable::FILTER_ACTIVE => true]));
        self::assertSame($inactive, $keysFor([VouchersTable::FILTER_ACTIVE => false]));
        foreach (['true', 'false', 1, 0] as $value) {
            self::assertSame([], $keysFor([VouchersTable::FILTER_ACTIVE => $value]), var_export($value, true));
            self::assertSame(
                [],
                self::keys($table, self::windowQuery($table, null, [VouchersTable::FILTER_ACTIVE => $value])),
                var_export($value, true),
            );
        }

        $facets = $table->facetCounts(
            $table->scopeSearch(new TableQueryDTO(
                search: $prefix,
                filter: [VouchersTable::FILTER_ACTIVE => true],
            )),
            [VouchersTable::FILTER_ACTIVE => [true, false]],
        );

        self::assertEquals([
            VouchersTable::FILTER_ACTIVE => self::facet(4, ['true' => 2, 'false' => 2]),
        ], $facets);
    }

    /**
     * Creating, renaming and switching a voucher redraws that same row, and the window follows the new code.
     *
     * An edit is an update. The row carries the current code, discount and flag, including fields the call
     * left as they were. Switched off, the voucher leaves an Active window; switched back on, it enters.
     * A fact of another source is ignored.
     */
    public function testAVoucherCreatedRenamedAndSwitchedMovesItsRow(): void
    {
        $prefix = RandomHelper::hex(8);
        $voucher = Hilos::$db->vouchers->actions->create($prefix . 'a', 10, true);
        $other = Hilos::$db->vouchers->actions->create($prefix . 'm', 20, true);
        $voucherId = self::voucherId($voucher);
        $otherId = self::voucherId($other);
        $table = self::vouchersTable();
        $byCode = self::windowQuery($table, $prefix);
        $active = self::windowQuery($table, $prefix, [VouchersTable::FILTER_ACTIVE => true]);
        $createdCode = VoucherFields::code($prefix . 'a');
        $renamedCode = VoucherFields::code($prefix . 'z');

        self::assertVoucherMutation(
            $table->buildMutationForSourceEvent(SourceChange::dbCreated(
                EcommerceShopDbContext::vouchers,
                (string) $voucherId,
                [
                    EntityVoucher::id => $voucherId,
                    EntityVoucher::code => $createdCode,
                    EntityVoucher::discount_percent => 10,
                    EntityVoucher::active => true,
                ],
            )),
            TableMutationType::Create,
            $voucherId,
            $createdCode,
            10,
            true,
        );
        self::assertSame([$voucherId, $otherId], self::keys($table, $byCode));

        $voucher->actions->update($prefix . 'z', 45, true);
        self::assertVoucherMutation(
            $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
                EcommerceShopDbContext::vouchers,
                (string) $voucherId,
                [
                    EntityVoucher::code => $renamedCode,
                    EntityVoucher::discount_percent => 45,
                ],
                previous: [
                    EntityVoucher::code => $createdCode,
                    EntityVoucher::discount_percent => 10,
                ],
            )),
            TableMutationType::Update,
            $voucherId,
            $renamedCode,
            45,
            true,
        );
        self::assertSame([$otherId, $voucherId], self::keys($table, $byCode));

        $voucher->actions->setActive(false);
        self::assertVoucherMutation(
            $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
                EcommerceShopDbContext::vouchers,
                (string) $voucherId,
                [EntityVoucher::active => false],
                previous: [EntityVoucher::active => true],
            )),
            TableMutationType::Update,
            $voucherId,
            $renamedCode,
            45,
            false,
        );
        self::assertFalse($table->containsRow($voucherId, $active));
        self::assertTrue($table->containsRow($otherId, $active));

        $voucher->actions->setActive(true);
        self::assertVoucherMutation(
            $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
                EcommerceShopDbContext::vouchers,
                (string) $voucherId,
                [EntityVoucher::active => true],
                previous: [EntityVoucher::active => false],
            )),
            TableMutationType::Update,
            $voucherId,
            $renamedCode,
            45,
            true,
        );
        self::assertTrue($table->containsRow($voucherId, $active));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::products,
            (string) $voucherId,
            [],
        )));
    }

    /** A DB Delete still identifies the table row after the voucher is gone. */
    public function testDeletionBuildsAMutationForAnAbsentVoucher(): void
    {
        $code = 'ZZE2EV' . strtoupper(RandomHelper::hex(12));
        $voucher = Hilos::$db->vouchers->actions->create($code, 15, true);
        $voucherId = self::voucherId($voucher);
        $voucher->actions->deleteFixture();
        self::assertFalse(isset(Hilos::$db->vouchers[$voucherId]));

        $table = self::vouchersTable();
        $mutation = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(EcommerceShopDbContext::vouchers, (string) $voucherId));
        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Delete, $mutation->type);
        self::assertSame($voucherId, $mutation->rowKey);
        self::assertNull($mutation->row);
    }

    /** @return VouchersTable Vouchers table registered on the shop */
    private static function vouchersTable(): VouchersTable
    {
        $table = Hilos::$table->vouchers;
        self::assertInstanceOf(VouchersTable::class, $table);

        return $table;
    }

    /**
     * @param ?string $search Term, empty when the window asked for none
     * @param array<string, mixed> $filter Active filter, empty when the window asked for any voucher
     * @return TableQueryDTO Window ordered by the table's default sort
     */
    private static function windowQuery(VouchersTable $table, ?string $search, array $filter = []): TableQueryDTO
    {
        return new TableQueryDTO(search: $search, sort: $table->defaultSort(), filter: $filter);
    }

    /**
     * @param VouchersTable $table Table to read
     * @param TableQueryDTO $query Window query
     * @return list<int|string> Row keys of the window, in its order
     */
    private static function keys(VouchersTable $table, TableQueryDTO $query): array
    {
        return array_map(
            static fn (AbstractTableRow $row): int|string => $row->getRowKey(),
            $table->getPage($query)->rows,
        );
    }

    /**
     * @param list<int|string> $window Row keys in the window's order
     * @param list<int> $voucherIds Ids this case wrote
     * @return list<int|string> Those ids, still in the window's order
     */
    private static function caseKeys(array $window, array $voucherIds): array
    {
        return array_values(array_filter(
            $window,
            static fn (int|string $rowKey): bool => in_array($rowKey, $voucherIds, true),
        ));
    }

    /**
     * @param Voucher $voucher Created voucher
     * @return int Persisted id
     */
    private static function voucherId(Voucher $voucher): int
    {
        $id = $voucher->id;
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @param Voucher $voucher Voucher as the library stored it
     * @return array{id: int, code: string, discountPercent: int, active: bool} Row payload the table should carry
     */
    private static function row(Voucher $voucher): array
    {
        return [
            VoucherTableRow::id => self::voucherId($voucher),
            VoucherTableRow::code => $voucher->code,
            VoucherTableRow::discountPercent => $voucher->discountPercent,
            VoucherTableRow::active => $voucher->active,
        ];
    }

    /**
     * @param list<AbstractTableRow> $rows Rows of a window
     * @return list<array<string, mixed>> Their payloads, in the window's order
     */
    private static function payloads(array $rows): array
    {
        return array_map(static fn (AbstractTableRow $row): array => $row->toArray(), $rows);
    }

    /**
     * @param int $count Vouchers the set without the filter holds
     * @param array<array-key, int> $options Vouchers each option leaves
     * @return array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>} Exact counts of one filter
     */
    private static function facet(int $count, array $options): array
    {
        return [
            TableConstants::FACET_KEY_ANY => new TableFacetCountDTO($count, true),
            TableConstants::FACET_KEY_OPTIONS => array_map(
                static fn (int $option): TableFacetCountDTO => new TableFacetCountDTO($option, true),
                $options,
            ),
        ];
    }

    /**
     * @param ?TableRowMutationDTO $mutation Mutation the fact produced
     * @param TableMutationType $type Create or update
     * @param int $voucherId Voucher the fact names
     * @param string $code Stored code
     * @param int $discountPercent Stored whole percentage
     * @param bool $active Stored flag
     */
    private static function assertVoucherMutation(
        ?TableRowMutationDTO $mutation,
        TableMutationType $type,
        int $voucherId,
        string $code,
        int $discountPercent,
        bool $active,
    ): void {
        self::assertNotNull($mutation);
        self::assertSame($type, $mutation->type);
        self::assertSame($voucherId, $mutation->rowKey);
        self::assertSame([
            VoucherTableRow::id => $voucherId,
            VoucherTableRow::code => $code,
            VoucherTableRow::discountPercent => $discountPercent,
            VoucherTableRow::active => $active,
        ], $mutation->row?->toArray());
    }
}
