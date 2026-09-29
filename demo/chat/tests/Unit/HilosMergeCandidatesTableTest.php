<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\Table\UserDetailBrowserTable;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Database\Object\Item\Identity;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use PHPUnit\Framework\TestCase;

/**
 * Project binding tests for the account-merge candidate and survivor-detail tables.
 *
 * The candidate window is the framework's own and is registered as it is; its rows are covered by
 * the framework integration suite (`HilosMergeCandidatesTableIntegrationTest`).
 */
final class HilosMergeCandidatesTableTest extends TestCase
{
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
            [HilosMergeCandidatesTable::FIELD_HAS_PASSWORD],
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
