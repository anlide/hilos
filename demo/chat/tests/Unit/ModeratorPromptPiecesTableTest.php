<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPieceTableRow;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the moderator prompt pieces table viewport serialization.
 */
final class ModeratorPromptPiecesTableTest extends TestCase
{
    public function testBrowserRowWrapsThePieceInItsEntitySlot(): void
    {
        $table = new ModeratorPromptPiecesTable();
        $row = new ModeratorPromptPieceTableRow(id: 7, section: 'name_rule', promptPiece: 'Be kind.');

        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 7,
                BrowserPageSignalData::sources => [
                    ChatDbContext::moderatorPromptPieces => [
                        ModeratorPromptPieceTableRow::id => 7,
                        ModeratorPromptPieceTableRow::section => 'name_rule',
                        ModeratorPromptPieceTableRow::promptPiece => 'Be kind.',
                    ],
                ],
            ],
            $table->browserRow($row),
        );
    }
}
