<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSortWhitelist;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sort-field whitelist both table gates run on (HIL-561).
 *
 * The map is what a boundary allows, so the tests are about what leaves it: an allowed
 * field comes back carrying the column it may order by, an unknown one comes back as no
 * sort at all plus a logged refusal, and a boundary that declares nothing does not get to
 * be a filter by accident.
 */
final class TableSortWhitelistTest extends TestCase
{
    /** Boundary name the rejection warning is expected to carry. */
    private const string CONTEXT = 'PromptPiecesTable';

    /**
     * A map whose two names differ, because that difference is why this is a map.
     *
     * @var array<string, string>
     */
    private const array ALLOWED = [
        'section' => 'section',
        'promptPiece' => 'prompt_piece',
    ];

    public function testAnAllowedFieldKeepsItsFieldAndDirectionAndGainsItsColumn(): void
    {
        $resolved = TableSortWhitelist::resolve(
            new TableSortDTO('promptPiece', TableConstants::ORDER_DESC),
            self::ALLOWED,
            self::CONTEXT,
        );

        self::assertNotNull($resolved);
        // The wire name survives untouched: the in-memory branch still sorts rows by it.
        self::assertSame('promptPiece', $resolved->field);
        self::assertSame(TableConstants::ORDER_DESC, $resolved->direction);
        self::assertSame('prompt_piece', $resolved->column);
    }

    public function testAFieldOutsideTheMapIsDroppedAndTheRefusalIsLogged(): void
    {
        ob_start();
        $resolved = TableSortWhitelist::resolve(
            new TableSortDTO('id` DESC, (SELECT 1)'),
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        self::assertNull($resolved);
        self::assertStringContainsString('Table sort field rejected', $logged);
        self::assertStringContainsString(self::CONTEXT, $logged);
        self::assertStringContainsString('(SELECT 1)', $logged);
    }

    public function testALongFieldNameCannotWriteTheLogALineAtATime(): void
    {
        ob_start();
        TableSortWhitelist::resolve(new TableSortDTO(str_repeat('x', 5000)), self::ALLOWED, self::CONTEXT);
        $logged = (string) ob_get_clean();

        // A refusal is logged per window refresh, so the line stays a line however long
        // the client's name is; what identifies the mistake is its start.
        self::assertStringContainsString(str_repeat('x', 97) . '...', $logged);
        self::assertStringNotContainsString(str_repeat('x', 98), $logged);
        self::assertLessThan(300, strlen($logged));
    }

    public function testAResolvedSortIsRecheckedByItsColumnAtTheNextBoundary(): void
    {
        $atTheTable = TableSortWhitelist::resolve(new TableSortDTO('promptPiece'), self::ALLOWED, self::CONTEXT);

        // The SQL boundary knows columns, not wire names: it must recognize `prompt_piece`
        // as its own, and it never sees `promptPiece` at all.
        $atTheColumns = TableSortWhitelist::resolve(
            $atTheTable,
            ['prompt_piece' => 'prompt_piece'],
            self::CONTEXT,
        );

        self::assertNotNull($atTheColumns);
        self::assertSame('prompt_piece', $atTheColumns->column);
    }

    public function testAWindowThatAskedForNoOrderingStaysUnordered(): void
    {
        self::assertNull(TableSortWhitelist::resolve(null, self::ALLOWED, self::CONTEXT));
    }

    public function testABoundaryThatDeclaresNothingPassesTheSortThrough(): void
    {
        $sort = new TableSortDTO('anything', TableConstants::ORDER_DESC);

        // Same object, not an equal one: an empty map states no opinion, so there is
        // nothing for the gate to add or take away.
        self::assertSame($sort, TableSortWhitelist::resolve($sort, [], self::CONTEXT));
    }
}
