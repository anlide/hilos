<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Hilos;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountErasureProgressResolver;
use PHPUnit\Framework\TestCase;

/** Erasure progress's wire form and the column verdicts seen by an admin view mode viewer. */
final class AccountErasureProgressTest extends TestCase
{
    public function testToArrayKeepsTheStartMomentAndComposition(): void
    {
        self::assertSame([
            'startedAt' => 1790416800123,
            'parts' => [[
                'participant' => 'personal',
                'owner' => HilosAgentType::HILOS_USER,
                'userId' => 43,
                'done' => false,
            ]],
            'waits' => [['participant' => 'delivery:mail', 'userId' => 42]],
            'refusals' => [],
        ], self::progress()->toArray());
    }

    public function testRefusalsTravelInTheirOrderAndKeepAnUnnamedTable(): void
    {
        $refusals = [
            [AccountErasureProgress::userId => 43, AccountErasureProgress::table => 'hilos_access_log'],
            [AccountErasureProgress::userId => 42, AccountErasureProgress::table => null],
        ];

        self::assertSame($refusals, self::progress($refusals)->toArray()['refusals']);
    }

    public function testWireFieldsUseThePeopleIdVerdictAndOpenComputedFacts(): void
    {
        self::assertEquals([
            'startedAt' => WireField::notPersonal(),
            'parts' => WireField::each([
                'participant' => WireField::notPersonal(),
                'owner' => WireField::notPersonal(),
                'userId' => WireField::column(HilosDbContext::users, ObjectUser::id),
                'done' => WireField::notPersonal(),
            ]),
            'waits' => WireField::each([
                'participant' => WireField::notPersonal(),
                'userId' => WireField::column(HilosDbContext::users, ObjectUser::id),
            ]),
            'refusals' => WireField::each([
                'userId' => WireField::column(HilosDbContext::users, ObjectUser::id),
                'table' => WireField::notPersonal(),
            ]),
        ], AccountErasureProgress::wireFields());
    }

    public function testHideForViewerKeepsTheWholeBlockAndNullWithoutHiddenMarks(): void
    {
        $previousDb = Hilos::$db;
        Hilos::$db = new class extends HilosDbContext {};
        Hilos::$db->configure();
        try {
            $browser = new class extends BrowserContext {
                /** @return ?PiiRegistry The people table's actual nonpersonal column verdicts */
                protected function viewerPiiRegistry(): ?PiiRegistry
                {
                    return new PiiRegistry([DatabaseConnectionDefaults::PRIMARY_INDEX => [
                        EntityUser::_table => EntityUser::_pii,
                    ]], [DatabaseConnectionDefaults::PRIMARY_INDEX => [
                        EntityUser::_table => EntityUser::_piiNotPersonal,
                    ]]);
                }
            };
            $fields = ['accountErasure' => WireField::each(AccountErasureProgress::wireFields())];
            $payload = ['accountErasure' => self::progress()->toArray()];

            self::assertSame($payload, $browser->hideForViewer($payload, $fields));
            self::assertSame(['accountErasure' => null], $browser->hideForViewer(['accountErasure' => null], $fields));
        } finally {
            Hilos::$db = $previousDb;
        }
    }

    public function testWithoutADatabaseThereIsNoProgress(): void
    {
        $previousDb = Hilos::$db;
        Hilos::$db = null;
        AccountErasureProgressResolver::forgetAll();
        try {
            self::assertNull(AccountErasureProgressResolver::of(43));
            self::assertNull(AccountErasureProgressResolver::of(43));
        } finally {
            AccountErasureProgressResolver::forgetAll();
            Hilos::$db = $previousDb;
        }
    }

    /**
     * @param list<array{userId: int, table: ?string}> $refusals Standing refusals of the erasure's finals
     * @return AccountErasureProgress One expected owner and one participant waiting for its owner
     */
    private static function progress(array $refusals = []): AccountErasureProgress
    {
        return new AccountErasureProgress(1790416800123, [[
            AccountErasureProgress::participant => AccountErasureParticipantCatalog::PERSONAL,
            AccountErasureProgress::owner => HilosAgentType::HILOS_USER,
            AccountErasureProgress::userId => 43,
            AccountErasureProgress::done => false,
        ]], [[
            AccountErasureProgress::participant => 'delivery:mail',
            AccountErasureProgress::userId => 42,
        ]], $refusals);
    }
}
