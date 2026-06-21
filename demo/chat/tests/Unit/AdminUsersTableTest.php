<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;
use Demo\Chat\Tables\AdminUser\AdminUsersTable;
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
}
