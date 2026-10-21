<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\FrameworkMigrationPullCommand;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPair;
use Hilos\Database\Migration\FrameworkMigrationProjectRegistry;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/** Exercises the shared registry and writer on source trees only, without a daemon or database. */
final class FrameworkMigrationPullCommandTest extends TestCase
{
    private string $root;
    private array $autoloaders;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-pull-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->autoloaders = spl_autoload_functions();
    }

    protected function tearDown(): void
    {
        foreach (spl_autoload_functions() as $loader) {
            if (!in_array($loader, $this->autoloaders, true) && is_array($loader) && $loader[0] instanceof ClassLoader) {
                $loader[0]->unregister();
            }
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testSharedManagerLoadsProjectPsr4AndPullsEveryTargetWithoutBootingOrSwitchingFacades(): void
    {
        $first = $this->project('one');
        $second = $this->project('two');
        $path = $this->registry([$first, $second]);
        $app = Hilos::appClass();
        $db = Hilos::$db;
        $rt = Hilos::$rt;
        $env = Hilos::$env;
        $manager = new CliManager(['repo-script', CliCommands::DB_FRAMEWORK_PULL, '--projects=' . $path]);
        self::assertFalse($manager->requiresDatabase(CliCommands::DB_FRAMEWORK_PULL));
        self::assertNull($manager->answerWithoutRunning());
        [$code, $output] = $this->capture(fn (): int => $manager->run());
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertSame(2, substr_count($output, ': created 4 files'));
        foreach (['one', 'two'] as $name) {
            $files = glob($this->track($name) . '/002_framework_install_setting*.sql');
            self::assertCount(2, $files);
            $origin = FrameworkMigrationOrigin::parsePair(file_get_contents($files[0]), file_get_contents($files[1]));
            self::assertSame(['setting'], $origin->units);
            $changes = glob($this->track($name) . '/003_framework_change_38c7203a140d8efdf40017ddaa16d4a695810303ef7f3e89ae703b19ed141c0d*.sql');
            self::assertCount(2, $changes);
            $change = FrameworkMigrationOrigin::parsePair(file_get_contents($changes[0]), file_get_contents($changes[1]));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['user'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261021021039718057_HIL-1580_add_user_time_zone/user', $change->parts[0]->key());
        }
        [$repeat, $repeatOutput] = $this->capture(fn (): int => $manager->run());
        self::assertSame(ExitCode::SUCCESS, $repeat);
        self::assertSame(2, substr_count($repeatOutput, ': skipped (already current)'));
        self::assertSame($app, Hilos::appClass());
        self::assertSame($db, Hilos::$db);
        self::assertSame($rt, Hilos::$rt);
        self::assertSame($env, Hilos::$env);
    }

    public function testDefaultTargetUsesTheConfiguredTrackAndCurrentFacade(): void
    {
        $entry = $this->project('current');
        FrameworkMigrationProjectRegistry::load($this->registry([$entry]));
        $app = new ReflectionProperty(Hilos::class, 'appClass');
        $list = new ReflectionProperty(Migration::class, 'migrationListPath');
        $name = new ReflectionProperty(Migration::class, 'migrationName');
        $previousApp = $app->getValue();
        $previousList = $list->getValue();
        $previousName = $name->getValue();
        try {
            $app->setValue(null, $entry['facade']);
            Migration::setMigrationListPath(dirname($this->track('current')));
            Migration::setMigrationName('Schema');
            [$code, $output] = $this->capture(fn (): int => new CliManager(['cli', CliCommands::DB_FRAMEWORK_PULL])->run());
            self::assertSame(ExitCode::SUCCESS, $code, $output);
            self::assertFileExists($this->track('current') . '/002_framework_install_setting.sql');
        } finally {
            $app->setValue(null, $previousApp);
            $list->setValue(null, $previousList);
            $name->setValue(null, $previousName);
        }
    }

    public function testBadSecondTrackPreventsWritingTheValidFirstTarget(): void
    {
        $entries = [$this->project('one'), $this->project('two')];
        unlink($this->track('two') . '/001_adopt.sql');
        unlink($this->track('two') . '/001_adopt_down.sql');
        $command = new PullCommandProbe();
        [$code, $output] = $this->capture(fn (): int => $command->execute(['projects' => $this->registry($entries)], []));
        self::assertSame(ExitCode::ERROR, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('no framework catalog origin', $command->stderr);
        self::assertStringContainsString('not written because batch preflight failed', $command->stderr);
        self::assertCount(2, glob($this->track('one') . '/*.sql'));
    }

    /** @return array<string, array{string}> Malformed registry boundaries */
    public static function invalidRegistries(): array
    {
        return [
            'broken JSON' => ['{'],
            'array root' => ['[]'],
            'no projects' => ['{}'],
            'empty projects' => ['{"projects":[]}'],
            'object projects' => ['{"projects":{}}'],
            'bad entry' => ['{"projects":[{"root":1,"facade":"missing"}]}'],
        ];
    }

    /** @param string $json Invalid registry source */
    #[DataProvider('invalidRegistries')]
    public function testInvalidRegistryIsANonzeroStderrRefusal(string $json): void
    {
        file_put_contents($this->root . '/projects.json', $json);
        $command = new PullCommandProbe();
        [$code, $output] = $this->capture(fn (): int => $command->execute(['projects' => $this->root . '/projects.json'], []));
        self::assertSame(ExitCode::ERROR, $code);
        self::assertSame('', $output);
        self::assertNotSame('', $command->stderr);
    }

    public function testUnknownFacadeAndMissingTrackAreRefusedBeforePublication(): void
    {
        $entry = $this->project('one');
        $entry['facade'] = 'NoSuchPullFacade';
        $command = new PullCommandProbe();
        [$code, $output] = $this->capture(fn (): int => $command->execute(['projects' => $this->registry([$entry])], []));
        self::assertSame(ExitCode::ERROR, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('Hilos facade', $command->stderr);

        $entry = $this->project('two');
        unlink($this->track('two') . '/001_adopt.sql');
        unlink($this->track('two') . '/001_adopt_down.sql');
        rmdir($this->track('two'));
        $command->stderr = '';
        [$code, $output] = $this->capture(fn (): int => $command->execute(['projects' => $this->registry([$entry])], []));
        self::assertSame(ExitCode::ERROR, $code);
        self::assertSame('', $output);
        self::assertStringContainsString($this->track('two'), $command->stderr);
    }

    public function testArgumentsBareProjectsAndUnreadableRegistryAreRefused(): void
    {
        foreach ([[[], ['extra']], [['projects' => true], []], [['projects' => '/no/pull-registry.json'], []]] as [$options, $args]) {
            $command = new PullCommandProbe();
            [$code, $output] = $this->capture(fn (): int => $command->execute($options, $args));
            self::assertSame(ExitCode::ERROR, $code);
            self::assertSame('', $output);
            self::assertNotSame('', $command->stderr);
        }
    }

    public function testMalformedProjectComposerMetadataIsRefused(): void
    {
        $entry = $this->project('one');
        $path = $this->registry([$entry]);
        foreach (['{', '{}', '{"autoload":{"psr-4":{"BadPrefix":"backend/"}}}',
            '{"autoload":{"psr-4":{"Good\\\\":42}}}'] as $composer) {
            file_put_contents($this->root . '/one/composer.json', $composer);
            $command = new PullCommandProbe();
            [$code, $output] = $this->capture(fn (): int => $command->execute(['projects' => $path], []));
            self::assertSame(ExitCode::ERROR, $code);
            self::assertSame('', $output);
            self::assertStringContainsString('composer.json', $command->stderr);
        }
    }

    public function testRepoEntrypointUsesTheSameManagerCommandAndExplicitRegistry(): void
    {
        $root = dirname(__DIR__, 5);
        $source = file_get_contents($root . '/scripts/framework-migrations.php');
        self::assertStringContainsString("'/vendor/autoload.php'", $source);
        self::assertStringContainsString('new CliManager([', $source);
        self::assertStringContainsString('CliCommands::DB_FRAMEWORK_PULL', $source);
        self::assertStringContainsString("'/framework-projects.json'", $source);
        self::assertStringContainsString('$manager->answerWithoutRunning() ?? $manager->run()', $source);
        self::assertStringNotContainsString('FrameworkMigrationInstaller', $source);
        $composer = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('@php scripts/framework-migrations.php', $composer['scripts']['db:framework:pull']);
    }

    /**
     * @param string $name Project directory name
     * @return array{root: string, facade: string} JSON registry entry
     */
    private function project(string $name): array
    {
        $namespace = 'PullFixture\\P' . bin2hex(random_bytes(8));
        mkdir($this->track($name), 0775, true);
        file_put_contents($this->root . '/' . $name . '/composer.json', json_encode([
            'autoload' => ['psr-4' => [$namespace . '\\' => ['backend/']]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/' . $name . '/backend/Hilos.php', "<?php\nnamespace {$namespace};\n"
            . 'class Hilos extends \\Hilos\\Hilos { protected const array FEATURES = [\\Hilos\\Core\\Feature\\HilosFeature::SETTINGS];'
            . ' protected static function createDb(): \\Hilos\\Database\\Context\\HilosDbContext {'
            . ' throw new \\LogicException("must not initialize database"); }'
            . ' protected static function createRuntime(): ?\\Hilos\\Runtime\\View\\Context\\RtContext { return new Runtime(); } }');
        file_put_contents($this->root . '/' . $name . '/backend/Runtime.php', "<?php\nnamespace {$namespace};\n"
            . 'class Runtime extends \\Hilos\\Runtime\\View\\Context\\RtContext {'
            . ' public function configure(): void { throw new \\LogicException("must not configure runtime"); } }');
        file_put_contents($this->root . '/' . $name . '/.env', 'must not read project environment');
        $body = "-- Reviewed fixture adoption.\n";
        $pair = new FrameworkMigrationPair('adopt', FrameworkMigrationOrigin::forBodies(
            FrameworkMigrationOrigin::ADOPT,
            ['user', 'session', 'verifier_circle'],
            [],
            $body,
            $body,
        ), $body, $body);
        file_put_contents($this->track($name) . '/001_adopt.sql', $pair->upSql);
        file_put_contents($this->track($name) . '/001_adopt_down.sql', $pair->downSql);

        return ['root' => $name, 'facade' => $namespace . '\\Hilos'];
    }

    /** @param string $name Project directory name
     * @return string Temporary schema track
     */
    private function track(string $name): string
    {
        return $this->root . '/' . $name . '/backend/Database/Migration/Schema';
    }

    /**
     * @param list<array{root: string, facade: string}> $entries Registry JSON boundary
     * @return string Registry path
     */
    private function registry(array $entries): string
    {
        $path = $this->root . '/projects.json';
        file_put_contents($path, json_encode(['projects' => $entries], JSON_THROW_ON_ERROR));
        return $path;
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

/** Captures the source command's stderr boundary. */
final class PullCommandProbe extends FrameworkMigrationPullCommand
{
    public string $stderr = '';

    /** @param string $text Command refusal */
    protected function writeToStandardError(string $text): void
    {
        $this->stderr .= $text . "\n";
    }
}
