<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use PHPUnit\Framework\TestCase;

/**
 * Project binding test for the account-merge candidate table.
 *
 * The candidate window is the framework's own and is registered as it is; its rows are covered by
 * the framework integration suite (`HilosMergeCandidatesTableIntegrationTest`). The survivor's card
 * beside it is the framework's too, and its declaration is pinned by `HilosUserDetailBrowserTableTest`.
 */
final class HilosMergeCandidatesTableTest extends TestCase
{
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
