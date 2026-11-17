<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/** Early FS setup stays independent, while full startup still checks prepared contexts. */
final class HilosFsInitializationTest extends TestCase
{
    /** @var array<string, object|null> Facade globals isolated by these cases */
    private array $previous = [];

    private ?BrowserContext $previousBrowser;

    /** @var class-string<Hilos> Facade bound before this test */
    private string $previousApp;

    /** Starts with no storage or settings layers. */
    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        $this->previousBrowser = Hilos::$browser;
        Hilos::resetBrowser();
        foreach (['fs', 'env', 'db', 'rt', 'table', 'setting', 'notify', 'files', 'mail', 'sms'] as $property) {
            $this->previous[$property] = Hilos::${$property};
            Hilos::${$property} = null;
        }
    }

    /** Restores the facade without reflection or a live daemon. */
    protected function tearDown(): void
    {
        foreach ($this->previous as $property => $value) {
            Hilos::${$property} = $value;
        }
        $this->previousApp::initBrowser($this->previousBrowser);
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        }
        parent::tearDown();
    }

    /** Neither an unused FS nor a configured one initializes another product layer. */
    public function testFsInitializationOnlyCreatesAndConfiguresTheFilesystem(): void
    {
        FsUnusedInitializationHilos::initFs();
        self::assertNull(Hilos::$fs);

        FsOnlyInitializationHilos::initFs();

        self::assertInstanceOf(FsInitializationContext::class, Hilos::$fs);
        self::assertSame(1, Hilos::$fs->configurations);
        foreach (['env', 'db', 'rt', 'table', 'setting'] as $property) {
            self::assertNull(Hilos::${$property}, $property);
        }
    }

    /** Repeating the early entry retains the configured instance. */
    public function testRepeatedInitializationDoesNotConfigureAgain(): void
    {
        FsOnlyInitializationHilos::initFs();
        $fs = Hilos::$fs;

        FsOnlyInitializationHilos::initFs();

        self::assertSame($fs, Hilos::$fs);
        self::assertSame(1, $fs->configurations);
    }

    /** A context supplied by the caller is already under that caller's control. */
    public function testAnInjectedContextIsRetainedWithoutConfiguration(): void
    {
        $fs = new FsInitializationContext();
        Hilos::$fs = $fs;

        FsOnlyInitializationHilos::initFs();

        self::assertSame($fs, Hilos::$fs);
        self::assertSame(0, $fs->configurations);
    }

    /** Both a feature's privacy check and the directory ownership check run after early setup. */
    public function testFullInitializationStillRefusesAnInvalidPreparedContext(): void
    {
        FsOnlyInitializationHilos::initFs();
        Hilos::$db = new class extends HilosDbContext {
        };
        Hilos::$rt = new class extends RtContext {
            /** The fixture needs no runtime rows. */
            public function configure(): void
            {
            }
        };

        try {
            FsAnalyticsFullInitializationHilos::init();
            self::fail('Full startup must still require the analytics Privacy declaration.');
        } catch (IncompleteFeatureActivationException $failure) {
            self::assertStringContainsString('standard.deletion', $failure->getMessage());
        }

        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('DirectoryScope::NODE');
        FsFullInitializationHilos::init();
    }
}

/** An intentionally wrong owner proves the early entry performs no product checks. */
final class FsInitializationContext extends FsContext
{
    public int $configurations = 0;

    /** Registers a journal path without creating it. */
    public function configure(): void
    {
        $this->configurations++;
        $this->registerDirectory(self::ANALYTICS_JOURNAL, '/uncreated/analytics-journal', DirectoryScope::CLUSTER);
    }
}

/** Any accidental bootstrap past FS fails at the factory boundary. */
abstract class FsOnlyInitializationHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::ANALYTICS];

    /** @return ?FsContext Context used only by FS bootstrap */
    protected static function createFs(): ?FsContext
    {
        return new FsInitializationContext();
    }

    /** @return EnvAccessor Never created by FS bootstrap */
    protected static function createEnv(): EnvAccessor
    {
        TestCase::fail('FS bootstrap must not create the environment accessor.');
    }

    /** @return SettingsAccessor Never created by FS bootstrap */
    protected static function createSetting(): SettingsAccessor
    {
        TestCase::fail('FS bootstrap must not create settings.');
    }

    /** @return HilosDbContext Never created by FS bootstrap */
    protected static function createDb(): HilosDbContext
    {
        TestCase::fail('FS bootstrap must not create the database context.');
    }

    /** @return ?RtContext Never created by FS bootstrap */
    protected static function createRuntime(): ?RtContext
    {
        TestCase::fail('FS bootstrap must not create the runtime context.');
    }

    /** @return ?TableContext Never created by FS bootstrap */
    protected static function createTable(): ?TableContext
    {
        TestCase::fail('FS bootstrap must not create the table context.');
    }
}

/** A project may need no filesystem at all. */
abstract class FsUnusedInitializationHilos extends FsOnlyInitializationHilos
{
    /** @return ?FsContext No FS context in this project */
    protected static function createFs(): ?FsContext
    {
        return null;
    }
}

/** Isolates real full-init FS checks from unrelated topology registration. */
abstract class FsFullInitializationHilos extends Hilos
{
    /** The fixture tests post-configuration checks only. */
    public static function validateTopology(): void
    {
    }

    /** The fixture tests post-configuration checks only. */
    public static function validateFeatureActivation(): void
    {
    }

    /** @return HilosDbContext The already supplied context must be kept */
    protected static function createDb(): HilosDbContext
    {
        TestCase::fail('The fixture supplies an inert database context.');
    }
}

/** Full startup must require the Analytics privacy declaration after early FS setup. */
abstract class FsAnalyticsFullInitializationHilos extends FsFullInitializationHilos
{
    protected const array FEATURES = [HilosFeature::ANALYTICS];
}
