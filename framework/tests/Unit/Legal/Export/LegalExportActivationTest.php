<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal\Export;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** A project registering the legal agent names a directory for its exports of acceptance records (HIL-1234). */
final class LegalExportActivationTest extends TestCase
{
    private ?FsContext $previousFs = null;

    protected function setUp(): void
    {
        $this->previousFs = Hilos::$fs;
    }

    protected function tearDown(): void
    {
        Hilos::$fs = $this->previousFs;
    }

    public function testTheLegalAgentWithoutTheDirectoryIsRefused(): void
    {
        Hilos::$fs = null;

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('legal_export');
        LegalAgentHilos::checkDirectory();
    }

    public function testNamingTheDirectorySufficesWithoutFilesystemWork(): void
    {
        Hilos::$fs = new class extends FsContext {
            /** Registers an intentionally uncreated path. */
            public function configure(): void
            {
                $this->registerDirectory(self::LEGAL_EXPORT, '/uncreated/legal-export', DirectoryScope::CLUSTER);
            }
        };
        Hilos::$fs->configure();

        LegalAgentHilos::checkDirectory();
        self::assertTrue(Hilos::$fs->hasDirectory(FsContext::LEGAL_EXPORT));
    }

    public function testAProjectWithoutTheLegalAgentOwesNoDirectory(): void
    {
        Hilos::$fs = null;

        NoLegalAgentHilos::checkDirectory();
        self::assertNull(Hilos::$fs);
    }
}

abstract class LegalAgentHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_LEGAL => []];

    /**
     * @throws IncompleteFeatureActivationException When the fixture names no legal export directory
     */
    public static function checkDirectory(): void
    {
        static::refuseLegalExportWithoutDirectory();
    }
}

abstract class NoLegalAgentHilos extends Hilos
{
    /**
     * @throws IncompleteFeatureActivationException When the fixture names no legal export directory
     */
    public static function checkDirectory(): void
    {
        static::refuseLegalExportWithoutDirectory();
    }
}
