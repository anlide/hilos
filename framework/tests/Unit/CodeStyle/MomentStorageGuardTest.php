<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Database\Entity\Item\AuthBlock;
use Hilos\Database\Entity\Item\UserRename;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Testing\MomentStorageBaseline;
use Hilos\Tests\CodeStyle\MomentStorage\MomentStorageSourceRule;
use Hilos\Tests\CodeStyle\MomentStorage\MomentStorageSources;
use PHPUnit\Framework\TestCase;

/** Full production source pass; physical schema checks have their own live coverage. */
final class MomentStorageGuardTest extends TestCase
{
    /** Refuse new writes and require removal of paid-off exact historical source records. */
    public function testProductionMomentWritesMatchTheClosedHistoricalBaseline(): void
    {
        $repositoryRoot = dirname(__DIR__, 4);
        $autoloaders = spl_autoload_functions();
        $inputs = MomentStorageSources::forRepository($repositoryRoot);
        self::assertSame($autoloaders, spl_autoload_functions(), 'The metadata loader must unregister after discovery.');
        self::assertContains([
            'entity' => AuthBlock::class, 'declaration' => AuthBlock::class,
            'table' => 'hilos_auth_block', 'column' => 'updated_at',
            'mapped' => false, 'frameworkOwned' => true, 'projectStorage' => null,
        ], $inputs['catalog'], 'DB-only Entity moments remain semantic source inputs.');
        self::assertContains([
            'entity' => null, 'declaration' => FrameworkTablesWithoutEntity::class,
            'table' => 'hilos_migration_claim', 'column' => 'claimed_at',
            'mapped' => false, 'frameworkOwned' => true, 'projectStorage' => null,
        ], $inputs['catalog'], 'Bootstrap provider moments remain semantic source inputs.');
        foreach ([
            ['chat', 'Demo\\Chat\\Database\\Entity\\Item\\Event', 'event', 'timestamp', null],
            ['chat', 'Demo\\Chat\\Database\\Entity\\Item\\UserRename', 'hilos_user_rename', 'renamed_at', UserRename::class],
            ['polls', 'Demo\\Polls\\Database\\Entity\\Item\\Guest', 'guest', 'created_at', null],
            ['tasks', 'Demo\\Tasks\\Database\\Entity\\Item\\Guest', 'guest', 'created_at', null],
        ] as [$demo, $entity, $table, $column, $frameworkDeclaration]) {
            if (in_array('demo/' . $demo . '/backend', $inputs['roots'], true)) {
                self::assertContains([
                    'entity' => $entity, 'declaration' => $frameworkDeclaration ?? $entity, 'table' => $table, 'column' => $column,
                    'mapped' => true, 'frameworkOwned' => $frameworkDeclaration !== null, 'projectStorage' => null,
                ], $inputs['catalog'], 'Project declarations stay distinct; inherited framework moments keep their canonical owner.');
            }
        }
        $report = MomentStorageSourceRule::forRoots($repositoryRoot, $inputs['roots'], $inputs['catalog'], $inputs['excludedPaths']);
        $baseline = MomentStorageBaseline::fromJson(file_get_contents($repositoryRoot . '/framework/tests/CodeStyle/moment-storage-baseline.json'));
        $covered = MomentStorageSources::coveredSubjects($report['subjects'], $baseline->subjects(), $inputs['roots'], $inputs['excludedPaths']);

        $this->assertSame([], $baseline->reconcile($report['reported'], $covered),
            'Moment writes must use signed epoch milliseconds. Only exact historical records with an owning migration are allowed.');
    }
}
