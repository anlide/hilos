<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\Table\UserDetailBrowserTable;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\HilosUser\HilosMergeCandidatesTable;
use Demo\Chat\Tables\HilosUser\HilosUserTableRow;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Database\Object\Item\Identity;
use Hilos\Tables\Users\AbstractHilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosMergeCandidateTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Project binding tests for the account-merge candidate and survivor-detail tables.
 */
final class HilosMergeCandidatesTableTest extends TestCase
{
    public function testCandidateRowKeepsProjectUserFieldsAndFrameworkMergeFieldsInSeparateSlots(): void
    {
        $table = new HilosMergeCandidatesTable();
        $identity = [
            Identity::type => 'email',
            Identity::identifier => 'loser@example.test',
            Identity::provider => null,
            Identity::verified => true,
        ];

        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 7,
                BrowserPageSignalData::sources => [
                    AbstractHilosMergeCandidatesTable::SLOT_USER => [
                        HilosUserTableRow::id => 7,
                        HilosUserTableRow::admin => false,
                        HilosUserTableRow::block => false,
                        HilosUserTableRow::name => 'Loser',
                        HilosUserTableRow::lastActivity => null,
                    ],
                    AbstractHilosMergeCandidatesTable::SLOT_MERGE => [
                        AbstractHilosMergeCandidatesTable::FIELD_IDENTITIES => [$identity],
                        AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD => true,
                    ],
                ],
            ],
            $table->browserRow(new HilosMergeCandidateTableRow(
                userFields: [
                    HilosUserTableRow::id => 7,
                    HilosUserTableRow::admin => false,
                    HilosUserTableRow::block => false,
                    HilosUserTableRow::name => 'Loser',
                    HilosUserTableRow::lastActivity => null,
                ],
                identities: [$identity],
                hasPassword: true,
            )),
        );
    }

    public function testSurvivorPasswordPresenceDeclaresTheIdentitySource(): void
    {
        $identityRow = null;
        foreach (UserDetailBrowserTable::BROWSER[BrowserTableConfigKey::ROWS] as $row) {
            if (($row[BrowserTableFieldKey::SOURCE] ?? null) === ChatBrowserSource::DB_IDENTITIES) {
                $identityRow = $row;
                break;
            }
        }

        $this->assertNotNull($identityRow);
        $this->assertContains(
            ChatBrowserSource::DB_IDENTITIES,
            UserDetailBrowserTable::BROWSER[BrowserTableConfigKey::SOURCES],
        );
        $this->assertSame(Identity::userId, $identityRow[BrowserTableFieldKey::ROW_KEY]);
        $this->assertSame(
            [Identity::userId => ChatBrowserRef::TABLE_HILOS_USER_ID],
            $identityRow[BrowserTableFieldKey::WHERE],
        );
        $this->assertSame(
            [AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD],
            $identityRow[BrowserTableFieldKey::COMPUTED],
        );
    }

    public function testCandidateTableIsRegisteredOnTheSingleUserPage(): void
    {
        $this->assertSame(
            HilosMergeCandidatesTable::class,
            Hilos::TABLES[ChatTableContext::hilosMergeCandidates],
        );
        $this->assertArrayHasKey(
            ChatTableContext::hilosMergeCandidates,
            Hilos::PAGE_TABLES[UserPage::PAGE],
        );
    }
}
