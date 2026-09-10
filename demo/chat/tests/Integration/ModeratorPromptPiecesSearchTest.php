<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\HilosException;

/**
 * Integration test: a window searches the columns this table declared, and no others (HIL-821).
 *
 * The prompt pieces are the one table today whose rows are windowed by the database, so the
 * declaration here has to be proven against a real statement rather than against a walk over an
 * array: what the map turns into is an identifier inside a WHERE, and only a server can say which
 * rows came back for it.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class ModeratorPromptPiecesSearchTest extends IntegrationTestCase
{
    /** Section of the piece this case writes; one of the two the column allows, and digit-free. */
    private const string SECTION = 'name_rule';

    /** Text of that piece; the word below is what the searching cases look for. */
    private const string PROMPT_PIECE = 'Refuse the shouting and keep it kind';

    /**
     * @throws HilosException When the piece cannot be written or the window cannot be served
     */
    public function testTheWindowFindsAPieceByEachDeclaredField(): void
    {
        $pieceId = (int) Hilos::$db->moderatorPromptPieces->actions->create(self::SECTION, self::PROMPT_PIECE)->id;

        self::assertContains($pieceId, $this->found('shouting'));
        self::assertContains($pieceId, $this->found(self::SECTION));
    }

    /**
     * @throws HilosException When the piece cannot be written or the window cannot be served
     */
    public function testAColumnTheTableDidNotDeclareIsNotSearched(): void
    {
        $pieceId = (int) Hilos::$db->moderatorPromptPieces->actions->create(self::SECTION, self::PROMPT_PIECE)->id;

        // The id is a column of the same entity, and until this leaf the search read every one of
        // them: the row would come back for the digits of its own key, which nobody typed looking
        // for it. Neither field this table declares carries a digit, so nothing else can match.
        self::assertNotContains($pieceId, $this->found((string) $pieceId));
    }

    /**
     * Serves one window over the pieces for a search term and reads the ids it delivered.
     *
     * @param string $search Term the window searches for
     * @return list<int> Ids of the rows the window holds
     * @throws HilosException When the window cannot be served
     */
    private function found(string $search): array
    {
        $snapshot = (new ModeratorPromptPiecesTable())->getPage(new TableQueryDTO(search: $search));

        return array_map(static fn(AbstractTableRow $row): int => (int) $row->getRowKey(), $snapshot->rows);
    }
}
