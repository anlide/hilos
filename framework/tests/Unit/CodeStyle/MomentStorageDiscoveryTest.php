<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Database\Entity\Item\AuthBlock;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Tests\CodeStyle\MomentStorage\MomentStorageSourceRule;
use Hilos\Tests\CodeStyle\MomentStorage\MomentStorageSources;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\ScannedRoots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Repository discovery must distinguish optional absence from a production tree it cannot inspect. */
final class MomentStorageDiscoveryTest extends TestCase
{
    private string $repository;

    /** Each test owns one disposable repository; no application or database is initialized. */
    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir() . '/moment-discovery-' . bin2hex(random_bytes(8));
        mkdir($this->repository . '/framework/backend', 0755, recursive: true);
    }

    /** Restricted permissions and the effective UID have already been restored by the boundary helper. */
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->repository, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->repository);
    }

    /** @return iterable<string, array{string, int}> Actual discovery and selected-root access failures */
    public static function inaccessibleDirectories(): iterable
    {
        yield 'demo cannot be listed' => ['demo', 0111];
        yield 'demo cannot be traversed' => ['demo', 0444];
        yield 'demo denies all access' => ['demo', 0000];
        yield 'demo child cannot be traversed to its existing backend' => ['demo/example', 0444];
        yield 'demo child denies all access' => ['demo/example', 0000];
        yield 'selected backend cannot be read' => ['demo/example/backend', 0111];
        yield 'selected backend cannot be traversed' => ['demo/example/backend', 0444];
        yield 'framework backend cannot be read' => ['framework/backend', 0111];
    }

    /**
     * This pure tooling boundary is exercised in-process with real unprivileged access, without a child or a stand service.
     *
     * @param string $directory Existing directory whose permissions prevent complete production discovery
     * @param int $mode Permissions under which discovery must refuse
     */
    #[DataProvider('inaccessibleDirectories')]
    public function testInaccessibleProductionTreeRefusesRepositoryDiscovery(string $directory, int $mode): void
    {
        $this->createDemo();
        $path = $this->repository . '/' . $directory;
        $autoloaders = spl_autoload_functions();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot inspect moment source directory: ' . $path);

        try {
            $this->withUnprivilegedAccess($path, $mode, function (): void {
                MomentStorageSources::forRepository($this->repository);
            });
        } finally {
            self::assertSame($autoloaders, spl_autoload_functions());
        }
    }

    /** The shared root enumerator must not equate an actual glob error with zero matches. */
    public function testSharedRootEnumerationRefusesAnUnreadableDemoDirectory(): void
    {
        $this->createDemo();
        $path = $this->repository . '/demo';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot enumerate code-style demo roots: ' . $path);

        $this->withUnprivilegedAccess($path, 0111, function (): void {
            ScannedRoots::all($this->repository);
        });
    }

    /** A package without demos still receives framework metadata and a complete source scan. */
    public function testPackageWithoutDemoDirectoryScansFrameworkOnly(): void
    {
        $this->assertFrameworkOnly();
        self::assertSame([
            'framework/backend' => RootKind::Production,
            'framework/tests' => RootKind::Suite,
            'scripts' => RootKind::Standalone,
        ], ScannedRoots::all($this->repository), 'Missing suite and standalone roots remain optional.');
    }

    /** Successful enumeration with zero matches remains a valid optional domain. */
    public function testReadableEmptyDemoDirectoryIsOptional(): void
    {
        mkdir($this->repository . '/demo', 0755);

        $this->assertFrameworkOnly();
    }

    /** A readable demo directory need not ship a backend or a composer manifest. */
    public function testReadableDemoWithoutBackendIsOptional(): void
    {
        mkdir($this->repository . '/demo/example', 0755, recursive: true);

        $this->assertFrameworkOnly();
    }

    /** Production PSR-4 discovery loads actual moment metadata and the selected backend is scanned. */
    public function testReadableBackendContributesMetadataAndSourceFindings(): void
    {
        $this->createDemo();
        $entityDirectory = str_replace('\\', '/', substr(Entity::class, strlen('Hilos\\'), -strlen('Entity')));
        $sourcePath = 'demo/example/backend/' . $entityDirectory . 'Event.php';
        mkdir(dirname($this->repository . '/' . $sourcePath), 0755, recursive: true);
        file_put_contents($this->repository . '/' . $sourcePath, <<<'PHP'
<?php
namespace MomentDiscoveryFixtures\Database\Entity\Item;
use Hilos\Database\Entity\Item\Entity;
final class Event extends Entity
{
    public const string _table = 'discovery_event';
    public const array _columns = ['at'];
    public const array _moments = ['at'];
    public int $at;
    public function write(): void
    {
        $this->at = time();
    }
}
PHP);
        $autoloaders = spl_autoload_functions();
        $inputs = MomentStorageSources::forRepository($this->repository);

        self::assertSame($autoloaders, spl_autoload_functions());
        self::assertSame(['framework/backend', 'demo/example/backend'], $inputs['roots']);
        self::assertContains([
            'entity' => 'MomentDiscoveryFixtures\Database\Entity\Item\Event',
            'declaration' => 'MomentDiscoveryFixtures\Database\Entity\Item\Event',
            'table' => 'discovery_event', 'column' => 'at', 'mapped' => true, 'frameworkOwned' => false, 'projectStorage' => null,
        ], $inputs['catalog']);
        $report = MomentStorageSourceRule::forRoots($this->repository, $inputs['roots'], $inputs['catalog'], $inputs['excludedPaths']);
        self::assertCount(1, $report['reported']);
        self::assertCount(1, $report['subjects']);
        self::assertSame(['source', $sourcePath,
            'MomentDiscoveryFixtures\Database\Entity\Item\Event::write',
            json_encode(['schema', 'MomentDiscoveryFixtures\Database\Entity\Item\Event', 'discovery_event', 'at'], JSON_THROW_ON_ERROR),
        ], json_decode($report['subjects'][0], true, flags: JSON_THROW_ON_ERROR));
    }

    /** A missing mandatory framework backend remains a discovery failure. */
    public function testFrameworkBackendIsRequired(): void
    {
        rmdir($this->repository . '/framework/backend');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('framework/backend');

        MomentStorageSources::forRepository($this->repository);
    }

    /** Minimal readable project inputs; the negative scenarios need no metadata classes of their own. */
    private function createDemo(): void
    {
        mkdir($this->repository . '/demo/example/backend', 0755, recursive: true);
        file_put_contents($this->repository . '/demo/example/composer.json', json_encode([
            'autoload' => ['psr-4' => ['MomentDiscoveryFixtures\\' => 'backend/']],
        ], JSON_THROW_ON_ERROR));
    }

    /** Optional paths do not weaken either metadata discovery or the selected-root source scan. */
    private function assertFrameworkOnly(): void
    {
        $autoloaders = spl_autoload_functions();
        $inputs = MomentStorageSources::forRepository($this->repository);

        self::assertSame($autoloaders, spl_autoload_functions());
        self::assertSame(['framework/backend'], $inputs['roots']);
        self::assertContains(AuthBlock::class, array_column($inputs['catalog'], 'entity'));
        self::assertSame(['reported' => [], 'subjects' => []], MomentStorageSourceRule::forRoots(
            $this->repository, $inputs['roots'], $inputs['catalog'], $inputs['excludedPaths'],
        ));
    }

    /**
     * Root keeps its real UID so finally restores the effective UID before restoring fixture permissions.
     * No process, environment change, permission-based skip or simulated access flags are involved.
     *
     * @param string $path Existing fixture directory to restrict
     * @param int $mode Permissions that provoke the real access failure
     * @param callable(): void $callback Actual discovery boundary
     */
    private function withUnprivilegedAccess(string $path, int $mode, callable $callback): void
    {
        $originalUid = posix_geteuid();
        $accessUid = $originalUid;
        if ($originalUid === 0) {
            $nobody = posix_getpwnam('nobody');
            self::assertIsArray($nobody);
            $accessUid = $nobody['uid'];
        }
        $originalMode = fileperms($path) & 0777;
        try {
            self::assertTrue(chmod($path, $mode));
            self::assertTrue(posix_seteuid($accessUid));
            self::assertNotSame(0, posix_geteuid(), 'The actual discovery must run without root access.');
            clearstatcache();
            $callback();
        } finally {
            $uidRestored = posix_seteuid($originalUid);
            $modeRestored = chmod($path, $originalMode);
            clearstatcache();
            self::assertTrue($uidRestored);
            self::assertSame($originalUid, posix_geteuid());
            self::assertTrue($modeRestored);
        }
    }
}
