<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSortWhitelist;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sort gate both table boundaries run on (HIL-561, HIL-789, HIL-917, HIL-1095).
 *
 * The gate answers two questions with two methods. The map is what a boundary allows, so the
 * tests of {@see TableSortWhitelist::resolve()} are about what leaves it: an allowed field comes
 * back carrying the column it may order by, an unknown one takes its whole order down with it
 * plus a logged refusal, and a boundary that declares nothing does not get to be a filter by
 * accident. The tests of {@see TableSortWhitelist::holdComposite()} are about what was offered:
 * an order of more than one column exists only because a table declared it — or because it is
 * the mirror of one the table declared, every direction turned.
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
            TableSortOrderDTO::of(new TableSortDTO('promptPiece', TableConstants::ORDER_DESC)),
            self::ALLOWED,
            self::CONTEXT,
        );

        self::assertNotNull($resolved);
        $component = $resolved->last();
        // The wire name survives untouched: the in-memory branch still sorts rows by it.
        self::assertSame('promptPiece', $component->field);
        self::assertSame(TableConstants::ORDER_DESC, $component->direction);
        self::assertSame('prompt_piece', $component->column);
    }

    public function testEveryComponentOfAnOrderGainsItsOwnColumn(): void
    {
        $resolved = TableSortWhitelist::resolve(
            TableSortOrderDTO::of(
                new TableSortDTO('section', TableConstants::ORDER_DESC),
                new TableSortDTO('promptPiece', TableConstants::ORDER_DESC),
            ),
            self::ALLOWED,
            self::CONTEXT,
        );

        self::assertNotNull($resolved);
        self::assertSame(['section', 'prompt_piece'], array_map(
            static fn(TableSortDTO $component): ?string => $component->column,
            $resolved->components,
        ));
    }

    public function testAFieldOutsideTheMapIsDroppedAndTheRefusalIsLogged(): void
    {
        ob_start();
        $resolved = TableSortWhitelist::resolve(
            TableSortOrderDTO::of(new TableSortDTO('id` DESC, (SELECT 1)')),
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        self::assertNull($resolved);
        self::assertStringContainsString('Table sort order rejected', $logged);
        self::assertStringContainsString(self::CONTEXT, $logged);
        self::assertStringContainsString('(SELECT 1)', $logged);
    }

    public function testOneUnknownComponentDropsTheWholeOrder(): void
    {
        // Half of an order is an order nobody asked for: serving the allowed component alone
        // would hand back a window ordered some other way and say nothing about it.
        ob_start();
        $resolved = TableSortWhitelist::resolve(
            TableSortOrderDTO::of(
                new TableSortDTO('section'),
                new TableSortDTO('elsewhere'),
            ),
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        self::assertNull($resolved);
        self::assertStringContainsString('section:asc, elsewhere:asc', $logged);
    }

    public function testALongOrderCannotWriteTheLogALineAtATime(): void
    {
        ob_start();
        TableSortWhitelist::resolve(
            TableSortOrderDTO::of(new TableSortDTO(str_repeat('x', 5000))),
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        // A refusal is logged per window refresh, so the line stays a line however long
        // the client's order is; what identifies the mistake is its start.
        self::assertStringContainsString(str_repeat('x', 97) . '...', $logged);
        self::assertStringNotContainsString(str_repeat('x', 98), $logged);
        self::assertLessThan(300, strlen($logged));
    }

    public function testAResolvedOrderIsRecheckedByItsColumnsAtTheNextBoundary(): void
    {
        $atTheTable = TableSortWhitelist::resolve(
            TableSortOrderDTO::of(new TableSortDTO('promptPiece')),
            self::ALLOWED,
            self::CONTEXT,
        );

        // The SQL boundary knows columns, not wire names: it must recognize `prompt_piece`
        // as its own, and it never sees `promptPiece` at all.
        $atTheColumns = TableSortWhitelist::resolve(
            $atTheTable,
            ['prompt_piece' => 'prompt_piece'],
            self::CONTEXT,
        );

        self::assertNotNull($atTheColumns);
        self::assertSame('prompt_piece', $atTheColumns->last()->column);
    }

    public function testAWindowThatAskedForNoOrderingStaysUnordered(): void
    {
        self::assertNull(TableSortWhitelist::resolve(null, self::ALLOWED, self::CONTEXT));
    }

    public function testABoundaryThatDeclaresNothingPassesTheOrderThrough(): void
    {
        $order = TableSortOrderDTO::of(new TableSortDTO('anything', TableConstants::ORDER_DESC));

        // Same object, not an equal one: an empty map states no opinion, so there is
        // nothing for the gate to add or take away.
        self::assertSame($order, TableSortWhitelist::resolve($order, [], self::CONTEXT));
    }

    public function testAnOrderOfOneColumnIsNotHeldAgainstAnyDeclaration(): void
    {
        // Which single columns a table serves is what its field map already says, and a click
        // on a header asks for nothing more than one of them.
        $order = TableSortOrderDTO::of(new TableSortDTO('promptPiece', TableConstants::ORDER_DESC));

        self::assertSame($order, TableSortWhitelist::holdComposite($order, [], self::ALLOWED, self::CONTEXT));
    }

    public function testADeclaredCompositeOrderIsServed(): void
    {
        $order = TableSortOrderDTO::of(
            new TableSortDTO('section', TableConstants::ORDER_DESC),
            new TableSortDTO('promptPiece', TableConstants::ORDER_DESC),
        );

        $held = TableSortWhitelist::holdComposite(
            $order,
            ['sectionThenPiece' => $order],
            self::ALLOWED,
            self::CONTEXT,
        );

        self::assertSame($order, $held);
    }

    public function testTheMirrorOfADeclaredOrderIsServed(): void
    {
        $asked = TableSortOrderDTO::of(
            new TableSortDTO('section', TableConstants::ORDER_DESC),
            new TableSortDTO('promptPiece', TableConstants::ORDER_ASC),
        );

        ob_start();
        $held = TableSortWhitelist::holdComposite(
            $asked,
            ['sectionThenPiece' => TableSortOrderDTO::of(
                new TableSortDTO('section', TableConstants::ORDER_ASC),
                new TableSortDTO('promptPiece', TableConstants::ORDER_DESC),
            )],
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        // The same fields in the same sequence with every direction turned is the declared
        // order read backwards: the index under it serves both, so nothing is declared twice
        // and nothing is logged.
        self::assertSame($asked, $held);
        self::assertSame('', $logged);
    }

    public function testAnOrderWithOnlySomeDirectionsTurnedIsRejectedWholeAndLogged(): void
    {
        ob_start();
        $held = TableSortWhitelist::holdComposite(
            TableSortOrderDTO::of(
                new TableSortDTO('section', TableConstants::ORDER_DESC),
                new TableSortDTO('promptPiece', TableConstants::ORDER_DESC),
            ),
            ['sectionThenPiece' => TableSortOrderDTO::of(
                new TableSortDTO('section', TableConstants::ORDER_ASC),
                new TableSortDTO('promptPiece', TableConstants::ORDER_DESC),
            )],
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        // Half a mirror runs over a different index than the declared order does, so it is an
        // order nobody declared, and it is refused as one.
        self::assertNull($held);
        self::assertStringContainsString('Table sort order rejected', $logged);
        self::assertStringContainsString('section:desc, promptPiece:desc', $logged);
    }

    public function testTheMirrorOfADeclarationNamingAFieldOutsideTheMapIsRejected(): void
    {
        ob_start();
        $held = TableSortWhitelist::holdComposite(
            TableSortOrderDTO::of(
                new TableSortDTO('section', TableConstants::ORDER_DESC),
                new TableSortDTO('elsewhere', TableConstants::ORDER_DESC),
            ),
            ['unknown' => TableSortOrderDTO::of(new TableSortDTO('section'), new TableSortDTO('elsewhere'))],
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        // A declaration passed over as unusable takes its mirror with it: the mirror is served
        // by the index under the declaration, and there is none to read backwards.
        self::assertNull($held);
        self::assertStringContainsString('Table sort order declaration ignored', $logged);
        self::assertStringContainsString('Table sort order rejected', $logged);
    }

    public function testACompositeOrderNobodyDeclaredIsRejectedWholeAndLogged(): void
    {
        ob_start();
        $held = TableSortWhitelist::holdComposite(
            TableSortOrderDTO::of(new TableSortDTO('promptPiece'), new TableSortDTO('section')),
            ['sectionThenPiece' => TableSortOrderDTO::of(
                new TableSortDTO('section'),
                new TableSortDTO('promptPiece'),
            )],
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        // The same two columns the other way round is a different order and a different index.
        self::assertNull($held);
        self::assertStringContainsString('Table sort order rejected', $logged);
        self::assertStringContainsString('promptPiece:asc, section:asc', $logged);
    }

    public function testADeclarationMixingDirectionsIsServedLikeAnyOther(): void
    {
        $asked = TableSortOrderDTO::of(
            new TableSortDTO('section', TableConstants::ORDER_ASC),
            new TableSortDTO('promptPiece', TableConstants::ORDER_DESC),
        );

        ob_start();
        $held = TableSortWhitelist::holdComposite(
            $asked,
            ['mixed' => $asked],
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        // An index declaration now carries the direction of every column and the schema audit
        // holds it against the live index, so a mixed order is an order like any other: it is
        // served, and the silence is the point — neither a skipped declaration nor a rejection.
        self::assertSame($asked, $held);
        self::assertSame('', $logged);
    }

    public function testADeclarationNamingAFieldOutsideTheMapIsPassedOverAndSaidSo(): void
    {
        $asked = TableSortOrderDTO::of(new TableSortDTO('section'), new TableSortDTO('elsewhere'));

        ob_start();
        $held = TableSortWhitelist::holdComposite(
            $asked,
            ['unknown' => $asked],
            self::ALLOWED,
            self::CONTEXT,
        );
        $logged = (string) ob_get_clean();

        self::assertNull($held);
        self::assertStringContainsString('Table sort order declaration ignored', $logged);
        self::assertStringContainsString('unknown-field', $logged);
    }
}
