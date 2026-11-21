<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\FilesTestResetCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the files test reset command against a temporary storage tree.
 */
final class FilesTestResetCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var class-string<Hilos> Facade to restore */
    private string $previousFacade;

    private ?FsContext $previousFs = null;

    private string $root;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousFacade = Hilos::appClass();
        $this->previousFs = Hilos::$fs;
        $this->root = sys_get_temp_dir() . '/hilos-files-reset-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files', 0777, true);
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        Hilos::$fs = null;
    }

    protected function tearDown(): void
    {
        Hilos::$env = new EnvAccessor();
        $this->previousFacade::initEnv();
        Hilos::$env = $this->previousEnv;
        Hilos::$fs = $this->previousFs;

        self::cleanupTree($this->root);
    }

    public function testCommandContract(): void
    {
        $command = new FilesTestResetCommand();
        self::assertInstanceOf(TestOnlyCommand::class, $command);
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
    }

    public function testWithoutFilesFeatureSucceedsAndLeavesDirectoryIntact(): void
    {
        FilesDisabledHilos::initEnv();
        file_put_contents($this->root . '/files/stored.bin', 'data');

        [$code, $output] = $this->executeCommand();

        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertSame("Nothing to empty: the project does not declare FILES.\n", $output);
        self::assertFileExists($this->root . '/files/stored.bin');
    }

    public function testWithFilesFeatureEmptiesDirectoryAndPreservesDirectoryAndGitignore(): void
    {
        FilesEnabledHilos::$filesPath = $this->root . '/files';
        FilesEnabledHilos::initEnv();
        file_put_contents($this->root . '/files/.gitignore', '*');
        file_put_contents($this->root . '/files/random-name.bin', 'data');
        mkdir($this->root . '/files/sub');
        file_put_contents($this->root . '/files/sub/nested.bin', 'data');

        [$code, $output] = $this->executeCommand();

        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertDirectoryExists($this->root . '/files');
        self::assertFileExists($this->root . '/files/.gitignore');
        self::assertFileDoesNotExist($this->root . '/files/random-name.bin');
        self::assertDirectoryDoesNotExist($this->root . '/files/sub');
        self::assertStringContainsString('emptied: 3 path(s) removed.', $output);
    }

    public function testWhenDirectoryDoesNotExistSucceeds(): void
    {
        $nonExistent = $this->root . '/does-not-exist';
        FilesEnabledHilos::$filesPath = $nonExistent;
        FilesEnabledHilos::initEnv();

        [$code, $output] = $this->executeCommand();

        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertDirectoryDoesNotExist($nonExistent);
        self::assertSame("Nothing to empty: files directory {$nonExistent} does not exist yet.\n", $output);
    }

    public function testUndeletablePathReturnsErrorAndReportsPath(): void
    {
        $parent = $this->root . '/files';
        $child = $parent . '/locked';
        mkdir($child, 0777, true);
        file_put_contents($child . '/file.bin', 'data');

        FilesEnabledHilos::$filesPath = $parent;
        FilesEnabledHilos::initEnv();

        $originalUid = posix_geteuid();
        $unprivilegedUid = $originalUid;
        if ($originalUid === 0) {
            $nobody = posix_getpwnam('nobody');
            self::assertIsArray($nobody);
            $unprivilegedUid = $nobody['uid'];
            self::assertNotSame(0, $unprivilegedUid);
        }

        try {
            FsPath::chmod($child, 0000);
            self::assertTrue(posix_seteuid($unprivilegedUid));
            self::assertSame($unprivilegedUid, posix_geteuid());
            [$code, $output] = $this->executeCommand();
        } finally {
            $restored = posix_seteuid($originalUid);
            if ($restored) {
                FsPath::chmod($child, 0755);
            }
            self::assertTrue($restored);
            self::assertSame($originalUid, posix_geteuid());
        }

        self::assertSame(ExitCode::ERROR, $code, $output);
        self::assertStringContainsString('was left with 1 path(s) behind:', $output);
        self::assertStringContainsString($child, $output);
    }

    public function testClusterDirectoryMarkerPreserved(): void
    {
        FilesEnabledHilos::$filesPath = $this->root . '/files';
        FilesEnabledHilos::initEnv();
        file_put_contents($this->root . '/files/' . ClusterDirectoryMarker::FILE_NAME, '{"marker":true}');
        file_put_contents($this->root . '/files/random.bin', 'data');

        [$code, $output] = $this->executeCommand();

        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertFileExists($this->root . '/files/' . ClusterDirectoryMarker::FILE_NAME);
        self::assertFileDoesNotExist($this->root . '/files/random.bin');
        self::assertStringContainsString('emptied: 1 path(s) removed.', $output);
    }

    /** @return array{int, string} Command exit code and stdout */
    private function executeCommand(): array
    {
        ob_start();
        try {
            return [new FilesTestResetCommand()->execute([], []), (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    private static function cleanupTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::cleanupTree($path . '/' . $entry);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}

/** Facade declaring FILES feature with dynamic storage directory */
abstract class FilesEnabledHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::FILES];

    public static ?string $filesPath = null;

    protected static function createFs(): ?FsContext
    {
        if (self::$filesPath === null) {
            return null;
        }

        return new FilesTestFsContext(self::$filesPath);
    }
}

/** Facade declaring no FILES feature */
abstract class FilesDisabledHilos extends Hilos
{
    protected const array FEATURES = [];
}

/** Disposable FS context for FilesTestResetCommand tests */
final class FilesTestFsContext extends FsContext
{
    public function __construct(string $filesPath)
    {
        $this->registerDirectory(FsContext::FILES, $filesPath, DirectoryScope::CLUSTER);
    }

    public function configure(): void
    {
    }
}
