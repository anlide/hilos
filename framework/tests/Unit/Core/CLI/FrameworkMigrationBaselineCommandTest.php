<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use FilesystemIterator;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\FrameworkMigrationBaselineCommand;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationProject;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use LogicException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/** Exercises current-project baseline delivery without booting the project's database or runtime. */
final class FrameworkMigrationBaselineCommandTest extends TestCase
{
    private string $root;
    private string $trackPath;
    private ReflectionProperty $appClassProperty;
    private ReflectionProperty $migrationListPathProperty;
    private ReflectionProperty $migrationNameProperty;
    private mixed $previousAppClass;
    private mixed $previousMigrationListPath;
    private mixed $previousMigrationName;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-baseline-' . bin2hex(random_bytes(8));
        $this->trackPath = $this->root . '/backend/Database/Migration/Schema';
        mkdir($this->trackPath, 0775, true);

        $this->appClassProperty = new ReflectionProperty(Hilos::class, 'appClass');
        $this->migrationListPathProperty = new ReflectionProperty(Migration::class, 'migrationListPath');
        $this->migrationNameProperty = new ReflectionProperty(Migration::class, 'migrationName');
        $this->previousAppClass = $this->appClassProperty->getValue();
        $this->previousMigrationListPath = $this->migrationListPathProperty->getValue();
        $this->previousMigrationName = $this->migrationNameProperty->getValue();

        $this->appClassProperty->setValue(null, BaselineCommandFacade::class);
        Migration::setMigrationListPath(dirname($this->trackPath));
        Migration::setMigrationName('Schema');
    }

    protected function tearDown(): void
    {
        $this->appClassProperty->setValue(null, $this->previousAppClass);
        $this->migrationListPathProperty->setValue(null, $this->previousMigrationListPath);
        $this->migrationNameProperty->setValue(null, $this->previousMigrationName);

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testCurrentTargetWritesBaselineWithoutBootingOrSwitchingTheFacade(): void
    {
        $database = Hilos::$db;
        $runtime = Hilos::$rt;
        $environment = Hilos::$env;
        $command = new BaselineCommandProbe();

        [$code, $output] = $this->capture(fn (): int => $command->execute([], []));

        self::assertSame(ExitCode::SUCCESS, $code, $command->stderr);
        self::assertSame('', $command->stderr);
        self::assertStringContainsString($this->trackPath . ': created 2 files', $output);
        self::assertStringContainsString($this->trackPath . '/001_framework_baseline.sql', $output);
        self::assertStringContainsString($this->trackPath . '/001_framework_baseline_down.sql', $output);
        self::assertSame(BaselineCommandFacade::class, Hilos::appClass());
        self::assertSame($database, Hilos::$db);
        self::assertSame($runtime, Hilos::$rt);
        self::assertSame($environment, Hilos::$env);
    }

    public function testExistingBaselineIsSkippedAndNamesThePullCommandForLaterUpdates(): void
    {
        $command = new BaselineCommandProbe();
        [$firstCode, $firstOutput] = $this->capture(fn (): int => $command->execute([], []));
        self::assertSame(ExitCode::SUCCESS, $firstCode, $command->stderr);
        self::assertStringContainsString(': created 2 files', $firstOutput);

        [$code, $output] = $this->capture(fn (): int => $command->execute([], []));

        self::assertSame(ExitCode::SUCCESS, $code, $command->stderr);
        self::assertStringContainsString($this->trackPath . ': skipped (baseline already exists)', $output);
        self::assertStringContainsString('run db:framework:pull for later framework updates', $output);
        self::assertSame('', $command->stderr);
    }

    public function testHalfPairRecoveryReportsTheFileCreatedByThisCall(): void
    {
        $plan = new FrameworkMigrationInstaller()->planBaseline(
            new FrameworkMigrationProject($this->trackPath, BaselineCommandFacade::class),
        )[0];
        $publication = $plan->publications[0];
        file_put_contents($publication->downPath, $publication->pair->downSql);
        $command = new BaselineCommandProbe();

        [$code, $output] = $this->capture(fn (): int => $command->execute([], []));

        self::assertSame(ExitCode::SUCCESS, $code, $command->stderr);
        self::assertStringContainsString($this->trackPath . ': created 1 file', $output);
        self::assertStringContainsString("  {$publication->upPath}\n", $output);
        self::assertFileExists($publication->downPath);
        self::assertFileExists($publication->upPath);
        self::assertSame('', $command->stderr);
    }

    public function testInvalidTrackReportsItsTargetAndReturnsAnError(): void
    {
        file_put_contents($this->trackPath . '/001_existing.sql', '-- Existing project migration.');
        $command = new BaselineCommandProbe();

        [$code, $output] = $this->capture(fn (): int => $command->execute([], []));

        self::assertSame(ExitCode::ERROR, $code);
        self::assertSame('', $output);
        self::assertStringContainsString($this->trackPath, $command->stderr);
        self::assertStringContainsString('empty numeric migration track', $command->stderr);
    }

    public function testPositionalArgumentsAreRefusedWithAnError(): void
    {
        $command = new BaselineCommandProbe();

        [$code, $output] = $this->capture(fn (): int => $command->execute([], ['extra']));

        self::assertSame(ExitCode::ERROR, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('db:framework:baseline takes no positional arguments', $command->stderr);
    }

    /**
     * @param callable(): int $body Command invocation
     * @return array{int, string} Exit code and stdout
     */
    private function capture(callable $body): array
    {
        ob_start();
        try {
            return [$body(), (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}

/** Facade fixture whose process contexts fail if a source command tries to initialize them. */
final class BaselineCommandFacade extends Hilos
{
    protected const array FEATURES = [HilosFeature::SETTINGS];

    protected static function createDb(): HilosDbContext
    {
        throw new LogicException('baseline command must not initialize the database');
    }

    protected static function createRuntime(): ?RtContext
    {
        return new BaselineCommandRuntime();
    }
}

/** Runtime capability probe whose configuration must never be invoked by the source command. */
final class BaselineCommandRuntime extends RtContext
{
    public function configure(): void
    {
        throw new LogicException('baseline command must not configure the runtime');
    }
}

/** Captures the baseline command's stderr boundary. */
final class BaselineCommandProbe extends FrameworkMigrationBaselineCommand
{
    public string $stderr = '';

    /** @param string $text Source-delivery refusal or failure */
    protected function writeToStandardError(string $text): void
    {
        $this->stderr .= $text . "\n";
    }
}
