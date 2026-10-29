<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPart;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration\FrameworkSchemaUnit;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** Source metadata and provenance only: temporary files, no database or second process. */
final class FrameworkMigrationSourcesTest extends TestCase
{
    private const string EARLY = '20261019120000000000_HIL-1518_early';
    private const string LATE = '20261020120000000000_HIL-1518_late';
    private const string LAST = '20261021120000000000_HIL-1518_last';

    private string $root;
    private FrameworkSchemaCatalog $schema;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-migration-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/Change', 0775, true);
        mkdir($this->root . '/Stub');
        $this->schema = new MigrationSourceSchema($this->root);
        $genesis = [];
        foreach ($this->schema->allUnits() as $unit) {
            if (!$unit->bootstrapOwned) {
                file_put_contents($unit->upPath, "CREATE {$unit->key};\n");
                file_put_contents($unit->downPath, "DROP {$unit->key};\n");
            }
            $genesis[] = [
                'unit' => $unit->key,
                'upSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->upPath)),
                'downSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->downPath)),
            ];
        }
        $this->writeJson('/catalog.json', ['format' => 1, 'units' => $genesis]);
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testRealGenesisAndChangesCoverEveryUnitAndCurrentStubIncludingBootstrapAndJournal(): void
    {
        $schema = new FrameworkSchemaCatalog();
        $catalog = new FrameworkMigrationCatalog($schema);
        $catalog->verifyCurrentStubs();
        $this->assertCount(49, $schema->allUnits());
        $this->assertSame(
            [
                '20261021021039718057_HIL-1580_add_user_time_zone/user',
                '20261022124515804554_HIL-1582_browser_time_zones/session',
                '20261022124515804554_HIL-1582_browser_time_zones/user',
            ],
            array_map(
                static fn (FrameworkMigrationPart $part): string => $part->key(),
                $catalog->selectedParts(MigrationSourceCoreHilos::class),
            ),
        );
        $this->assertSame(1, $schema->unit('change_log')->connection);
        $this->assertNull($schema->unit('migration')->upPath);
    }

    public function testSelectionUsesUnitUnionAndDoesNotCareAboutFeatureFolder(): void
    {
        $this->change(self::LATE, ['shared', 'extra', 'user']);
        $catalog = $this->catalog();
        $this->assertSame(['user'], array_column($catalog->selectedParts(MigrationSourceCoreHilos::class), 'unit'));
        $this->assertSame(
            ['shared', 'user'],
            array_column($catalog->selectedParts(MigrationSourceFeaturesHilos::class), 'unit'),
        );
        $this->assertSame(['shared'], array_column($catalog->partsForUnits(['shared', 'shared']), 'unit'));
    }

    public function testStableTopologicalOrderUsesExplicitRequiresEvenAgainstTimestampOrder(): void
    {
        $this->change(self::EARLY, ['shared'], [[self::LATE, 'user']]);
        $this->change(self::LATE, ['user']);
        $this->change(self::LAST, ['extra']);
        $parts = $this->catalog()->partsForUnits(['shared', 'user', 'extra']);
        $this->assertSame(
            [self::LATE . '/user', self::EARLY . '/shared', self::LAST . '/extra'],
            array_map(static fn (FrameworkMigrationPart $part): string => $part->key(), $parts),
        );
    }

    public function testUnselectedMissingDependencyDoesNotPoisonCore(): void
    {
        $this->change(self::EARLY, ['extra'], [[self::LATE, 'shared']]);
        $catalog = $this->catalog();
        $this->assertSame([], $catalog->selectedParts(MigrationSourceCoreHilos::class));
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('missing or unselected');
        $catalog->partsForUnits(['extra']);
    }

    public function testExistingDependencyInUnselectedUnitIsRefused(): void
    {
        $this->change(self::EARLY, ['user']);
        $this->change(self::LATE, ['shared'], [[self::EARLY, 'user']]);
        $this->expectException(DatabaseException::class);
        $this->catalog()->partsForUnits(['shared']);
    }

    public function testDependencyCycleIsRefused(): void
    {
        $this->change(self::EARLY, ['user'], [[self::LATE, 'shared']]);
        $this->change(self::LATE, ['shared'], [[self::EARLY, 'user']]);
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('cycle');
        $this->catalog()->partsForUnits(['user', 'shared']);
    }

    public function testLatestStubComesFromDependencyOrderRatherThanLargestId(): void
    {
        $this->change(self::LATE, ['user']);
        $this->change(self::EARLY, ['user'], [[self::LATE, 'user']]);
        file_put_contents($this->schema->unit('user')->upPath, "LATEST UP\n");
        file_put_contents($this->schema->unit('user')->downPath, "LATEST DOWN\n");
        $this->editManifest(self::EARLY, static function (array &$data): void {
            $data['parts'][0]['afterStubUpSha256'] = hash('sha256', "LATEST UP\n");
            $data['parts'][0]['afterStubDownSha256'] = hash('sha256', "LATEST DOWN\n");
        });
        $catalog = $this->catalog();
        $catalog->verifyCurrentStubs(['user']);
        $this->assertSame([self::LATE, self::EARLY], array_column($catalog->partsForUnits(['user']), 'changeId'));
    }

    public function testTransitiveOrderAcrossAnotherUnitResolvesSameUnitParts(): void
    {
        $this->change(self::EARLY, ['user']);
        $this->change(self::LATE, ['shared'], [[self::EARLY, 'user']]);
        $this->change(self::LAST, ['user'], [[self::LATE, 'shared']]);
        $this->catalog()->verifyCurrentStubs(['user', 'shared']);
        $this->assertCount(3, $this->catalog()->partsForUnits(['user', 'shared']));
    }

    public function testIndependentBranchesOfOneUnitAreRefusedEvenWhenHashesAgree(): void
    {
        $this->change(self::EARLY, ['user']);
        $this->change(self::LATE, ['user']);
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Ambiguous');
        $this->catalog()->verifyCurrentStubs(['user']);
    }

    #[DataProvider('directions')]
    public function testGenesisDetectsStubDrift(string $direction): void
    {
        file_put_contents($this->root . '/Stub/user' . $direction . '.sql', "CHANGED\n");
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('stub drift');
        $this->catalog()->verifyCurrentStubs();
    }

    public static function directions(): array
    {
        return [[''], ['_down']];
    }

    public function testBootstrapPartHasNullAfterHashesAndNoStub(): void
    {
        $this->change(self::EARLY, ['migration']);
        $catalog = $this->catalog();
        $catalog->verifyCurrentStubs(['migration']);
        $this->assertNull($catalog->part(self::EARLY, 'migration')->afterStubUpSha256);
        $this->assertNull($catalog->part(self::EARLY, 'migration')->afterStubDownSha256);
    }

    #[DataProvider('invalidManifestCases')]
    public function testMalformedManifestIsRefused(string $case): void
    {
        $this->change(self::EARLY, ['user']);
        $this->editManifest(self::EARLY, static function (array &$data) use ($case): void {
            switch ($case) {
                case 'format': $data['format'] = 2; break;
                case 'duplicate': $data['parts'][] = $data['parts'][0]; break;
                case 'unknown-unit': $data['parts'][0]['unit'] = 'unknown'; break;
                case 'id': $data['id'] = '2026102012000000000_HIL-1518_short'; break;
                case 'date': $data['id'] = '20261320120000000000_HIL-1518_date'; break;
                case 'hash': $data['parts'][0]['afterStubUpSha256'] = null; break;
                case 'missing-down': unset($data['parts'][0]['down']); break;
                case 'duplicate-requires':
                    $data['parts'][0]['requires'] = array_fill(0, 2, ['changeId' => self::LATE, 'unit' => 'user']);
                    break;
                case 'requires-object': $data['parts'][0]['requires'] = (object) []; break;
                case 'empty-parts': $data['parts'] = []; break;
            }
        });
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    public static function invalidManifestCases(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'format', 'duplicate', 'unknown-unit', 'id', 'date', 'hash', 'missing-down',
            'duplicate-requires', 'requires-object', 'empty-parts',
        ]);
    }

    public function testSameGroupIdInTwoManifestsIsRefused(): void
    {
        $this->change(self::EARLY, ['user']);
        $this->change(self::LATE, ['shared']);
        $this->editManifest(self::LATE, static function (array &$data): void { $data['id'] = self::EARLY; });
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Duplicate framework change id');
        $this->catalog();
    }

    #[DataProvider('badPaths')]
    public function testSqlPathsCannotLeaveTheirGroup(string $path): void
    {
        $this->change(self::EARLY, ['user']);
        $this->editManifest(self::EARLY, static function (array &$data) use ($path): void { $data['parts'][0]['up'] = $path; });
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    public static function badPaths(): array
    {
        return [['../outside.sql'], ['/tmp/outside.sql'], ['C:/outside.sql'], ['auth/../../outside.sql'],
            ['auth\\outside.sql'], ['auth//user.sql'], ['./auth/user.sql'], ['auth/absent.sql']];
    }

    public function testSymlinkCannotEscapeGroup(): void
    {
        $this->change(self::EARLY, ['user']);
        $path = $this->root . '/Change/' . self::EARLY . '/unrelated-folder/user.sql';
        unlink($path);
        symlink($this->schema->unit('user')->upPath, $path);
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    #[DataProvider('badGenesisCases')]
    public function testInvalidGenesisIsRefused(string $case): void
    {
        $data = json_decode(file_get_contents($this->root . '/catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        switch ($case) {
            case 'format': $data['format'] = 10; break;
            case 'missing': array_pop($data['units']); break;
            case 'duplicate': $data['units'][] = $data['units'][0]; break;
            case 'bootstrap-hash': $data['units'][0]['upSha256'] = str_repeat('a', 64); break;
            case 'unknown': $data['units'][0]['unit'] = 'unknown'; break;
            case 'bad-hash': $data['units'][1]['downSha256'] = 'bad'; break;
            case 'json': file_put_contents($this->root . '/catalog.json', '{'); break;
        }
        if ($case !== 'json') {
            $this->writeJson('/catalog.json', $data);
        }
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    public static function badGenesisCases(): array
    {
        return [['format'], ['missing'], ['duplicate'], ['bootstrap-hash'], ['unknown'], ['bad-hash'], ['json']];
    }

    public function testUnitAfterGenesisIsIntroducedByItsPartAndDeliveredAsItsCurrentInstall(): void
    {
        $this->leaveOutOfGenesis('extra');
        $this->introduce(self::EARLY, 'extra');
        $this->catalog()->verifyCurrentStubs(['extra']);
        $this->change(self::LATE, ['extra'], [[self::EARLY, 'extra']]);
        file_put_contents($this->schema->unit('extra')->upPath, "CREATE extra WITH column;\n");
        $this->editManifest(self::LATE, static function (array &$data): void {
            $data['parts'][0]['afterStubUpSha256'] = hash('sha256', "CREATE extra WITH column;\n");
        });
        $catalog = $this->catalog();
        $catalog->verifyCurrentStubs();
        $parts = $catalog->partsForUnits(['extra']);
        $this->assertSame([self::EARLY, self::LATE], array_column($parts, 'changeId'));
        $this->assertSame([true, false], array_column($parts, 'introducesUnit'));
        $this->assertSame(['changeId', 'unit', 'upSha256', 'downSha256'], array_keys($parts[0]->toArray()));
        $origin = FrameworkMigrationOrigin::forBodies('install', ['extra'], $parts, 'CREATE extra WITH column;', 'DROP extra;');
        $verified = $catalog->verifyPair(...$this->pair($origin, 'CREATE extra WITH column;', 'DROP extra;'));
        $this->assertSame([], $catalog->missingParts(['extra'], [$verified]));
    }

    #[DataProvider('directions')]
    public function testUnitAfterGenesisDetectsDriftFromItsIntroducedStub(string $direction): void
    {
        $this->leaveOutOfGenesis('extra');
        $this->introduce(self::EARLY, 'extra');
        file_put_contents($this->root . '/Stub/extra' . $direction . '.sql', "CHANGED\n");
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Framework stub drift for unit extra');
        $this->catalog()->verifyCurrentStubs(['extra']);
    }

    #[DataProvider('nonBoolIntroductions')]
    public function testIntroducesUnitIsOnlyABool(mixed $value): void
    {
        $this->leaveOutOfGenesis('extra');
        $this->introduce(self::EARLY, 'extra');
        $this->editManifest(self::EARLY, static function (array &$data) use ($value): void {
            $data['parts'][0]['introducesUnit'] = $value;
        });
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Malformed framework migration part');
        $this->catalog();
    }

    public static function nonBoolIntroductions(): array
    {
        return [[null], [1], [0], ['true'], [[]]];
    }

    #[DataProvider('invalidIntroductionCases')]
    public function testInvalidIntroductionIsRefused(string $case, string $message): void
    {
        $this->leaveOutOfGenesis('extra');
        switch ($case) {
            case 'twice':
                $this->introduce(self::EARLY, 'extra');
                $this->introduce(self::LATE, 'extra', [[self::EARLY, 'extra']]);
                break;
            case 'not-first':
                $this->change(self::EARLY, ['extra']);
                $this->introduce(self::LATE, 'extra', [[self::EARLY, 'extra']]);
                break;
            case 'ordinary-only':
                $this->change(self::EARLY, ['extra']);
                $this->change(self::LATE, ['extra'], [[self::EARLY, 'extra']]);
                break;
            case 'genesis-unit':
                $this->introduce(self::LAST, 'extra');
                $this->introduce(self::EARLY, 'user');
                break;
            case 'genesis-bootstrap':
                $this->introduce(self::LAST, 'extra');
                $this->introduce(self::EARLY, 'migration');
                break;
            case 'bootstrap':
                $this->introduce(self::LAST, 'extra');
                $this->leaveOutOfGenesis('migration');
                $this->introduce(self::EARLY, 'migration');
                break;
            case 'branch':
                $this->introduce(self::EARLY, 'extra');
                $this->change(self::LATE, ['extra']);
                break;
            case 'cycle':
                $this->introduce(self::EARLY, 'extra', [[self::LATE, 'extra']]);
                $this->change(self::LATE, ['extra'], [[self::EARLY, 'extra']]);
                break;
            case 'lost-dependency':
                $this->introduce(self::EARLY, 'extra');
                $this->change(self::LATE, ['extra'], [[self::EARLY, 'extra'], [self::LAST, 'user']]);
                break;
            case 'lost-hash':
                $this->introduce(self::EARLY, 'extra');
                $this->editManifest(self::EARLY, static function (array &$data): void {
                    $data['parts'][0]['afterStubDownSha256'] = null;
                });
                break;
        }
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage($message);
        $this->catalog();
    }

    public static function invalidIntroductionCases(): array
    {
        return [
            ['twice', 'Framework unit extra is introduced by more than one part'],
            ['not-first', 'Framework unit extra must open its chain with ' . self::LATE . '/extra, not ' . self::EARLY . '/extra'],
            ['ordinary-only', 'Framework genesis misses schema unit extra'],
            ['genesis-unit', 'cannot introduce genesis unit user'],
            ['genesis-bootstrap', 'cannot introduce genesis unit migration'],
            ['bootstrap', 'cannot introduce bootstrap-owned unit migration'],
            ['branch', 'Ambiguous framework change branches for unit extra'],
            ['cycle', 'Framework migration part dependency cycle'],
            ['lost-dependency', 'requires missing or unselected part ' . self::LAST . '/user'],
            ['lost-hash', 'Unit extra requires both stub hashes'],
        ];
    }

    public function testChangeOriginChecksBothBodiesAgainstImmutableSource(): void
    {
        $this->change(self::EARLY, ['user']);
        $catalog = $this->catalog();
        $part = $catalog->part(self::EARLY, 'user');
        $origin = FrameworkMigrationOrigin::forBodies('change', ['user'], [$part], $part->upSql, $part->downSql);
        $this->assertSame($origin->toArray(), $catalog->verifyPair(...$this->pair($origin, $part->upSql, $part->downSql))->toArray());
        $changed = FrameworkMigrationOrigin::forBodies('change', ['user'], [$part], 'ALTERED', $part->downSql);
        $this->expectException(DatabaseException::class);
        $catalog->verifyPair(...$this->pair($changed, 'ALTERED', $part->downSql));
    }

    public function testChangingPublishedSourceInvalidatesOldChangeOrigin(): void
    {
        $this->change(self::EARLY, ['user']);
        $part = $this->catalog()->part(self::EARLY, 'user');
        $origin = FrameworkMigrationOrigin::forBodies('change', ['user'], [$part], $part->upSql, $part->downSql);
        file_put_contents($this->root . '/Change/' . self::EARLY . '/unrelated-folder/user_down.sql', 'TAMPERED');
        $this->expectException(DatabaseException::class);
        $this->catalog()->verifyPair(...$this->pair($origin, $part->upSql, $part->downSql));
    }

    public function testOldInstallKeepsItsOwnBodyAndCoverageAfterStubAndCatalogAdvance(): void
    {
        $this->change(self::EARLY, ['user']);
        $oldPart = $this->catalog()->part(self::EARLY, 'user');
        $origin = FrameworkMigrationOrigin::forBodies('install', ['user'], [$oldPart], 'OLD STUB', 'OLD DOWN');
        $this->change(self::LATE, ['user'], [[self::EARLY, 'user']]);
        file_put_contents($this->schema->unit('user')->upPath, 'NEW STUB');
        file_put_contents($this->schema->unit('user')->downPath, 'NEW DOWN');
        $this->editManifest(self::LATE, static function (array &$data): void {
            $data['parts'][0]['afterStubUpSha256'] = hash('sha256', 'NEW STUB');
            $data['parts'][0]['afterStubDownSha256'] = hash('sha256', 'NEW DOWN');
        });
        $catalog = $this->catalog();
        $catalog->verifyCurrentStubs(['user']);
        $verified = $catalog->verifyPair(...$this->pair($origin, 'OLD STUB', 'OLD DOWN'));
        $this->assertSame([self::LATE], array_column($catalog->missingParts(['user'], [$verified]), 'changeId'));
    }

    public function testExactCoverageDoesNotTreatAnEarlierIdAsCoveredByALaterId(): void
    {
        $this->change(self::LATE, ['shared']);
        $late = $this->catalog()->part(self::LATE, 'shared');
        $origin = FrameworkMigrationOrigin::forBodies('install', ['shared'], [$late], 'up', 'down');
        $this->change(self::EARLY, ['user']);
        $catalog = $this->catalog();
        $this->assertSame([self::EARLY], array_column($catalog->missingParts(['user', 'shared'], [$origin]), 'changeId'));
    }

    public function testPartIdentityIncludesUnitWithinOneGroupAndCompositeBaseline(): void
    {
        $this->change(self::EARLY, ['user', 'shared']);
        $catalog = $this->catalog();
        $parts = $catalog->partsForUnits(['user', 'shared']);
        $origin = FrameworkMigrationOrigin::forBodies('baseline', ['user', 'shared'], $parts, 'COMPOSITE', 'ROLLBACK');
        $verified = $catalog->verifyPair(...$this->pair($origin, 'COMPOSITE', 'ROLLBACK'));
        $this->assertSame(['shared', 'user'], $verified->units);
        $this->assertCount(2, $catalog->coveredParts([$verified]));
        $this->assertSame([], $catalog->missingParts(['user', 'shared'], [$verified]));
    }

    public function testDuplicatePartProvenanceAcrossOriginsIsRefused(): void
    {
        $this->change(self::EARLY, ['user']);
        $part = $this->catalog()->part(self::EARLY, 'user');
        $origin = FrameworkMigrationOrigin::forBodies('change', ['user'], [$part], $part->upSql, $part->downSql);
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Duplicate framework part provenance');
        $this->catalog()->coveredParts([$origin, $origin]);
    }

    public function testDuplicateInstallationWithoutPartsIsRefused(): void
    {
        $origin = FrameworkMigrationOrigin::forBodies('install', ['user'], [], 'up', 'down');
        $this->expectException(DatabaseException::class);
        $this->catalog()->coveredParts([$origin, $origin]);
    }

    public function testGenesisAdoptionAcceptsTheVerifiedProjectComposition(): void
    {
        $origin = FrameworkMigrationOrigin::forBodies('adopt', array_column($this->schema->allUnits(), 'key'), [], 'up', 'down');
        $this->assertSame([], $this->catalog()->coveredParts([$origin]));
        $limited = FrameworkMigrationOrigin::forBodies('adopt', ['user'], [], 'up', 'down');
        $this->assertSame([], $this->catalog()->coveredParts([$limited]));
        $this->assertSame(['user'], $limited->units);
    }

    public function testAdoptionCannotOverlapAnotherInstallation(): void
    {
        $adopt = FrameworkMigrationOrigin::forBodies('adopt', ['user'], [], 'up', 'down');
        $install = FrameworkMigrationOrigin::forBodies('install', ['user'], [], 'up', 'down');
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Duplicate framework installation provenance for unit user');
        $this->catalog()->coveredParts([$adopt, $install]);
    }

    public function testCanonicalizationIsSharedBySourceHashesAndPairWriterAndParser(): void
    {
        $up = "SELECT 'café';\r\nSELECT 2;\r";
        $down = "DROP;\r\n";
        $origin = FrameworkMigrationOrigin::forBodies('install', ['user'], [], $up, $down);
        $this->assertSame(hash('sha256', "SELECT 'café';\nSELECT 2;\n"), $origin->upSha256);
        $this->assertSame($origin->toArray(), FrameworkMigrationOrigin::parsePair(...$this->pair($origin, $up, $down))->toArray());
        $this->assertNotSame(FrameworkMigrationOrigin::bodyHash('a'), FrameworkMigrationOrigin::bodyHash("a\n"));
    }

    #[DataProvider('invalidPairCases')]
    public function testInvalidOriginOrPairIsRefused(string $case): void
    {
        $origin = FrameworkMigrationOrigin::forBodies('install', ['user'], [], 'up', 'down');
        $data = $origin->toArray();
        switch ($case) {
            case 'format': $data['format'] = 2; break;
            case 'kind': $data['kind'] = 'future'; break;
            case 'duplicate-unit': $data['units'][] = 'user'; break;
            case 'unknown-unit': $data['units'] = ['unknown']; break;
            case 'missing-field': unset($data['parts']); break;
            case 'object-list': $data['parts'] = (object) []; break;
            case 'change-empty': $data['kind'] = 'change'; break;
            case 'hash': $data['upSha256'] = 'bad'; break;
            case 'unknown-field': $data['future'] = true; break;
        }
        $header = FrameworkMigrationOrigin::PREFIX . json_encode($data, JSON_THROW_ON_ERROR);
        $up = $header . "\nup";
        $down = $header . "\ndown";
        switch ($case) {
            case 'body': $up .= 'tamper'; break;
            case 'pair': $down = str_replace('install', 'baseline', $down); break;
            case 'duplicate-header': $up .= "\n" . $header; break;
            case 'not-first': $up = "\n" . $up; break;
            case 'json': $up = $down = FrameworkMigrationOrigin::PREFIX . "{\n"; break;
            case 'bom': $up = "\xEF\xBB\xBF" . $up; break;
            case 'utf8': $up .= "\xFF"; break;
        }
        $this->expectException(DatabaseException::class);
        $this->catalog()->verifyPair($up, $down);
    }

    public static function invalidPairCases(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'format', 'kind', 'duplicate-unit', 'unknown-unit', 'missing-field', 'object-list', 'change-empty', 'hash',
            'body', 'pair', 'duplicate-header', 'not-first', 'json', 'bom', 'utf8', 'unknown-field',
        ]);
    }

    public function testDuplicateAndForeignPartReferencesAreRefused(): void
    {
        $this->change(self::EARLY, ['user']);
        $part = $this->catalog()->part(self::EARLY, 'user');
        $this->expectException(DatabaseException::class);
        new FrameworkMigrationOrigin('baseline', ['user'], [$part, $part], hash('sha256', 'up'), hash('sha256', 'down'));
    }

    public function testReadyIndependentPartsSortBeforeARequiredLaterId(): void
    {
        $this->change(self::EARLY, ['shared'], [[self::LAST, 'user']]);
        $this->change(self::LATE, ['extra']);
        $this->change(self::LAST, ['user']);
        $this->assertSame(
            [self::LATE, self::LAST, self::EARLY],
            array_column($this->catalog()->partsForUnits(['user', 'shared', 'extra']), 'changeId'),
        );
    }

    public function testDependenciesOfSeparateChangeOriginsAreCheckedAsOneCoveredSet(): void
    {
        $this->change(self::EARLY, ['user']);
        $this->change(self::LATE, ['shared'], [[self::EARLY, 'user']]);
        $catalog = $this->catalog();
        $origins = [];
        foreach ($catalog->partsForUnits(['user', 'shared']) as $part) {
            $origins[] = FrameworkMigrationOrigin::forBodies('change', [$part->unit], [$part], $part->upSql, $part->downSql);
        }
        $this->assertCount(2, $catalog->coveredParts(array_reverse($origins)));
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('requires missing');
        $catalog->coveredParts([$origins[1]]);
    }

    public function testInstallCanDependOnAPartRecordedByAnotherUnitsOrigin(): void
    {
        $this->change(self::EARLY, ['user']);
        $this->change(self::LATE, ['shared'], [[self::EARLY, 'user']]);
        $catalog = $this->catalog();
        $user = $catalog->part(self::EARLY, 'user');
        $shared = $catalog->part(self::LATE, 'shared');
        $userOrigin = FrameworkMigrationOrigin::forBodies('install', ['user'], [$user], 'USER', 'DROP USER');
        $sharedOrigin = FrameworkMigrationOrigin::forBodies('install', ['shared'], [$shared], 'SHARED', 'DROP SHARED');
        $this->assertSame(
            $sharedOrigin->toArray(),
            $catalog->verifyPair(...$this->pair($sharedOrigin, 'SHARED', 'DROP SHARED'))->toArray(),
        );
        $this->assertSame([], $catalog->missingParts(['user', 'shared'], [$sharedOrigin, $userOrigin]));
    }

    public function testAnInstallCannotClaimALaterPartWithoutItsEarlierRequiredPart(): void
    {
        $this->change(self::EARLY, ['user']);
        $this->change(self::LATE, ['user'], [[self::EARLY, 'user']]);
        $part = $this->catalog()->part(self::LATE, 'user');
        $origin = FrameworkMigrationOrigin::forBodies('install', ['user'], [$part], 'up', 'down');
        $this->expectException(DatabaseException::class);
        $this->catalog()->coveredParts([$origin]);
    }

    #[DataProvider('invalidPartIds')]
    public function testMalformedPartIdentityIsRefused(string $id, string $unit): void
    {
        $this->expectException(DatabaseException::class);
        FrameworkMigrationPart::keyOf($id, $unit);
    }

    public static function invalidPartIds(): array
    {
        return [
            ['20260230120000000000_HIL-1518_bad_day', 'user'],
            ['20261320120000000000_HIL-1518_bad_month', 'user'],
            ['2026102012000000000_HIL-1518_short', 'user'],
            ['202610201200000000000_HIL-1518_long', 'user'],
            ['20261020120000000000_HIL-0_zero', 'user'],
            ['20261020120000000000_HIL-1518_UPPER', 'user'],
            [self::EARLY, '../user'],
        ];
    }

    #[DataProvider('invalidReferenceCases')]
    public function testInvalidPartReferenceIsRefused(string $case): void
    {
        $this->change(self::EARLY, ['user']);
        $part = $this->catalog()->part(self::EARLY, 'user');
        $origin = FrameworkMigrationOrigin::forBodies('baseline', ['user'], [$part], 'up', 'down');
        $data = $origin->toArray();
        switch ($case) {
            case 'duplicate': $data['parts'][] = $data['parts'][0]; break;
            case 'foreign-unit': $data['units'] = ['shared']; break;
            case 'missing-field': unset($data['parts'][0]['upSha256']); break;
            case 'source-up': $data['parts'][0]['upSha256'] = str_repeat('b', 64); break;
            case 'source-down': $data['parts'][0]['downSha256'] = str_repeat('c', 64); break;
            case 'unknown-part': $data['parts'][0]['changeId'] = self::LATE; break;
            case 'adopt-with-part': $data['kind'] = 'adopt'; break;
        }
        $header = FrameworkMigrationOrigin::PREFIX . json_encode($data, JSON_THROW_ON_ERROR);
        $this->expectException(DatabaseException::class);
        $this->catalog()->verifyPair($header . "\nup", $header . "\ndown");
    }

    public static function invalidReferenceCases(): array
    {
        return [['duplicate'], ['foreign-unit'], ['missing-field'], ['source-up'], ['source-down'],
            ['unknown-part'], ['adopt-with-part']];
    }

    public function testSourceUsesTheSameLineEndingCanonicalizationAsOrigin(): void
    {
        $this->change(self::EARLY, ['user']);
        file_put_contents($this->root . '/Change/' . self::EARLY . '/unrelated-folder/user.sql', "ALTER user;\r\n");
        $part = $this->catalog()->part(self::EARLY, 'user');
        $this->assertSame("ALTER user;\n", $part->upSql);
        $this->assertSame(hash('sha256', "ALTER user;\n"), $part->upSha256);
    }

    public function testSqlSourceCannotAlreadyCarryAnOrigin(): void
    {
        $this->change(self::EARLY, ['user']);
        file_put_contents(
            $this->root . '/Change/' . self::EARLY . '/unrelated-folder/user.sql',
            "-- hilos-framework: {}\nALTER user;\n",
        );
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    public function testMissingSourceFileBecomesADatabaseMigrationFailure(): void
    {
        $this->change(self::EARLY, ['user']);
        unlink($this->root . '/Change/' . self::EARLY . '/unrelated-folder/user_down.sql');
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    public function testManifestSymlinkCannotEscapeGroup(): void
    {
        $this->change(self::EARLY, ['user']);
        $manifest = $this->root . '/Change/' . self::EARLY . '/change.json';
        rename($manifest, $this->root . '/outside.json');
        symlink($this->root . '/outside.json', $manifest);
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    public function testGroupSymlinkCannotEscapeChangeDirectory(): void
    {
        $this->change(self::EARLY, ['user']);
        $group = $this->root . '/Change/' . self::EARLY;
        rename($group, $this->root . '/outside');
        symlink($this->root . '/outside', $group);
        $this->expectException(DatabaseException::class);
        $this->catalog();
    }

    public function testMissingGenesisBecomesADatabaseMigrationFailure(): void
    {
        unlink($this->root . '/catalog.json');
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot read framework migration catalog');
        $this->catalog();
    }

    private function catalog(): FrameworkMigrationCatalog
    {
        return new FrameworkMigrationCatalog($this->schema, $this->root);
    }

    /** @param list<string> $units @param list<array{string, string}> $requires */
    private function change(string $id, array $units, array $requires = []): void
    {
        $path = '/Change/' . $id;
        mkdir($this->root . $path . '/unrelated-folder', 0775, true);
        $parts = [];
        foreach ($units as $key) {
            $unit = $this->schema->unit($key);
            file_put_contents($this->root . $path . '/unrelated-folder/' . $key . '.sql', "ALTER {$key};\n");
            file_put_contents($this->root . $path . '/unrelated-folder/' . $key . '_down.sql', "UNDO {$key};\n");
            $parts[] = [
                'unit' => $key,
                'up' => 'unrelated-folder/' . $key . '.sql',
                'down' => 'unrelated-folder/' . $key . '_down.sql',
                'requires' => array_map(static fn (array $ref): array => ['changeId' => $ref[0], 'unit' => $ref[1]], $requires),
                'afterStubUpSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->upPath)),
                'afterStubDownSha256' => $unit->bootstrapOwned ? null : hash('sha256', file_get_contents($unit->downPath)),
            ];
        }
        $this->writeJson($path . '/change.json', ['format' => 1, 'id' => $id, 'parts' => $parts]);
    }

    /** @param list<array{string, string}> $requires */
    private function introduce(string $id, string $unit, array $requires = []): void
    {
        $this->change($id, [$unit], $requires);
        $this->editManifest($id, static function (array &$data): void { $data['parts'][0]['introducesUnit'] = true; });
    }

    private function leaveOutOfGenesis(string $unit): void
    {
        $data = json_decode(file_get_contents($this->root . '/catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $data['units'] = array_values(array_filter($data['units'], static fn (array $entry): bool => $entry['unit'] !== $unit));
        $this->writeJson('/catalog.json', $data);
    }

    private function editManifest(string $id, callable $edit): void
    {
        $path = '/Change/' . $id . '/change.json';
        $data = json_decode(file_get_contents($this->root . $path), true, flags: JSON_THROW_ON_ERROR);
        $edit($data);
        $this->writeJson($path, $data);
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($this->root . $path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    }

    private function pair(FrameworkMigrationOrigin $origin, string $up, string $down): array
    {
        return [$origin->header() . "\n" . $up, $origin->header() . "\n" . $down];
    }
}

final class MigrationSourceSchema extends FrameworkSchemaCatalog
{
    public function __construct(private readonly string $root)
    {
        parent::__construct(new class extends FeatureRegistry {
            protected function buildDefinitions(): array
            {
                return array_map(static fn (HilosFeature $feature): FeatureDefinition => new class ($feature) extends FeatureDefinition {
                    public function __construct(private readonly HilosFeature $value) {}
                    public function feature(): HilosFeature { return $this->value; }
                    public function requirements(): FeatureRequirements
                    {
                        return new FeatureRequirements(requiredDbTables: ['hilos_shared']);
                    }
                }, [HilosFeature::AUTH, HilosFeature::CODE_CHANNELS]);
            }
        });
    }

    protected function buildUnits(): array
    {
        $units = [new FrameworkSchemaUnit('migration', ['migration'], null, null, bootstrapOwned: true)];
        foreach (['user', 'session', 'shared', 'extra'] as $key) {
            $units[] = new FrameworkSchemaUnit(
                $key,
                ['hilos_' . $key],
                $this->root . '/Stub/' . $key . '.sql',
                $this->root . '/Stub/' . $key . '_down.sql',
            );
        }

        return $units;
    }
}

abstract class MigrationSourceCoreHilos extends Hilos
{
    protected static function createRuntime(): ?RtContext { return null; }
}

abstract class MigrationSourceFeaturesHilos extends MigrationSourceCoreHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::CODE_CHANNELS];
}
