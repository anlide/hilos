<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\BootstrapMigrationProof;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\MigrationUpFile;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Source-only provenance proof; no database or runtime initialization. */
final class BootstrapMigrationProofTest extends TestCase
{
    private const string CHANGE = '20261109000848893922_HIL-1918_bootstrap_moments';

    private string $root;

    /** Each case owns both generated directions in a temporary track. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-bootstrap-proof-' . bin2hex(random_bytes(6));
        FsPath::ensureDirectory($this->root, 0700);
    }

    /** Removes the complete source fixture, including tampered counterparts. */
    protected function tearDown(): void
    {
        foreach (FsPath::entries($this->root) as $entry) {
            FsPath::delete($this->root . '/' . $entry);
        }
        FsPath::removeDirectory($this->root);
    }

    /** @param string $unit Exact bootstrap unit to prove */
    #[DataProvider('bootstrapUnits')]
    public function testOnlyProvenCanonicalPartsExposeBootstrapAlteration(string $unit): void
    {
        $file = $this->copyPair($unit);
        $this->assertSame(MigrationOperationKind::UNKNOWN, MigrationOperationReader::read($file->statements[0], 'target')[0]->kind);
        $operation = BootstrapMigrationProof::read($file, 'target');
        $this->assertNotNull($operation);
        $this->assertSame(MigrationOperationKind::ALTER_TABLE, $operation->kind);
        $this->assertSame('target', $operation->table->database);
        $this->assertSame('hilos_' . $unit, $operation->table->table);
        $this->assertSame($file->statements[0], $operation->sql);
        $this->assertFalse($operation->isProvenanceSource());
    }

    /** @return list<array{string}> Every admitted immutable part */
    public static function bootstrapUnits(): array
    {
        return [['migration_claim'], ['admin_view_mode_latch'], ['database_marker']];
    }

    public function testIdenticalUnsignedBodyHasNoProof(): void
    {
        $file = $this->copyPair('migration_claim');
        FsPath::write($file->path, new FrameworkMigrationCatalog()->part(self::CHANGE, 'migration_claim')->upSql);
        $this->assertNull(BootstrapMigrationProof::read(MigrationUpFile::read($file->index, $file->path), 'target'));
    }

    public function testMissingCounterpartCannotProveTheUp(): void
    {
        $file = $this->copyPair('migration_claim');
        FsPath::delete(substr($file->path, 0, -4) . '_down.sql');
        $this->expectException(RestoreFailedException::class);
        BootstrapMigrationProof::read($file, 'target');
    }

    public function testTamperedCounterpartCannotProveTheUp(): void
    {
        $file = $this->copyPair('migration_claim');
        $path = substr($file->path, 0, -4) . '_down.sql';
        FsPath::write($path, FsPath::read($path) . "-- tampered\n");
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('body hash disagrees');
        BootstrapMigrationProof::read($file, 'target');
    }

    public function testSelfConsistentBodyHashesDoNotProveImmutableSourceIdentity(): void
    {
        $file = $this->copyPair('migration_claim');
        $part = new FrameworkMigrationCatalog()->part(self::CHANGE, 'migration_claim');
        $up = str_replace('`hilos_migration_claim`', '`application_rows`', $part->upSql);
        $origin = FrameworkMigrationOrigin::forBodies(FrameworkMigrationOrigin::CHANGE, [$part->unit], [$part], $up, $part->downSql);
        FsPath::write($file->path, $origin->header() . "\n" . $up);
        FsPath::write(substr($file->path, 0, -4) . '_down.sql', $origin->header() . "\n" . $part->downSql);
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('immutable source');
        BootstrapMigrationProof::read(MigrationUpFile::read($file->index, $file->path), 'target');
    }

    public function testBaselineCoverageCannotStandInForAnExactChangeBody(): void
    {
        $file = $this->copyPair('migration_claim');
        $part = new FrameworkMigrationCatalog()->part(self::CHANGE, 'migration_claim');
        $origin = FrameworkMigrationOrigin::forBodies(FrameworkMigrationOrigin::BASELINE, [$part->unit], [$part], $part->upSql, $part->downSql);
        FsPath::write($file->path, $origin->header() . "\n" . $part->upSql);
        FsPath::write(substr($file->path, 0, -4) . '_down.sql', $origin->header() . "\n" . $part->downSql);
        $this->assertNull(BootstrapMigrationProof::read(MigrationUpFile::read($file->index, $file->path), 'target'));
    }

    /**
     * @param string $unit Exact production part to locate in the delivered Chat track
     * @return MigrationUpFile Copied generated source with its real body and provenance
     */
    private function copyPair(string $unit): MigrationUpFile
    {
        foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $path) {
            $content = FsPath::read($path);
            if (!str_ends_with($path, '_down.sql') && str_contains($content, self::CHANGE)
                && str_contains($content, '"units":["' . $unit . '"]')) {
                $copy = $this->root . '/' . basename($path);
                FsPath::copy($path, $copy);
                FsPath::copy(substr($path, 0, -4) . '_down.sql', substr($copy, 0, -4) . '_down.sql');
                return MigrationUpFile::read((int)basename($path), $copy);
            }
        }
        $this->fail('The real delivered bootstrap pair must exist');
    }
}
