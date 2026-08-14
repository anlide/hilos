<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;
use Demo\Chat\Tables\AdminUser\AdminUsersTable;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the admin users table viewport serialization.
 */
final class AdminUsersTableTest extends TestCase
{
    public function testBrowserRowSplitsIntoUserAndConnectionsSlots(): void
    {
        $table = new AdminUsersTable();
        $row = new AdminUserTableRow(
            id: 5,
            name: 'Ada',
            lastActivity: '2026-06-21 10:00',
            onlineSessionCount: 2,
            presence: 'online',
        );

        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 5,
                BrowserPageSignalData::sources => [
                    ChatDbContext::users => [
                        AdminUserTableRow::id => 5,
                        AdminUserTableRow::name => 'Ada',
                        AdminUserTableRow::lastActivity => '2026-06-21 10:00',
                    ],
                    ChatRtContext::connections => [
                        AdminUserTableRow::presence => 'online',
                        AdminUserTableRow::onlineSessionCount => 2,
                    ],
                ],
            ],
            $table->browserRow($row),
        );
    }

    public function testTheRenameActionRefusesAPayloadThatNamesNoUser(): void
    {
        // Read as zero the id would address user 0, and the rename would be
        // acknowledged as done to a row nobody edited.
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(AdminUserTableRow::id);

        AdminUserUpdateActionDTO::fromArray([AdminUserTableRow::name => 'Ada']);
    }

    public function testTheRenameActionRefusesAPayloadThatCarriesNoName(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(AdminUserTableRow::name);

        AdminUserUpdateActionDTO::fromArray([AdminUserTableRow::id => 5]);
    }

    public function testARowPayloadThatLostTheOnlineCountIsRefused(): void
    {
        // The row payload is the table's own toArray() output, so a missing field
        // is a constructor that drifted from it, not a row the browser may render.
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(AdminUserTableRow::onlineSessionCount);

        AdminUserTableRow::fromArray([
            AdminUserTableRow::id => 5,
            AdminUserTableRow::name => 'Ada',
            AdminUserTableRow::lastActivity => null,
            AdminUserTableRow::presence => null,
        ]);
    }
}
