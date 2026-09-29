<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\DataExport;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

final class DataExportActivationTest extends TestCase
{
    /** AUTH owes both the builder and its durable request table. */
    public function testAuthRequiresExportAgentAndTable(): void
    {
        $requirements = (new AuthFeature())->requirements();
        self::assertContains(HilosAgentType::HILOS_DATA_EXPORT, $requirements->requiredAgents);
        self::assertContains(DataExport::_table, $requirements->requiredDbTables);
    }

    /** A project cannot activate accounts without naming export storage. */
    public function testMissingStorageIsRefused(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = null;
        try {
            $this->expectException(IncompleteFeatureActivationException::class);
            $this->expectExceptionMessage('data_export');
            ExportActivationHilos::checkDirectory();
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    /** Naming storage suffices; the builder creates the physical directory later. */
    public function testNamedStoragePassesWithoutFilesystemWork(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = new class extends FsContext {
            /** Registers an intentionally uncreated path. */
            public function configure(): void
            {
                $this->registerDirectory(self::DATA_EXPORT, '/uncreated/data-export', DirectoryScope::CLUSTER);
            }
        };
        Hilos::$fs->configure();
        try {
            ExportActivationHilos::checkDirectory();
            self::assertTrue(Hilos::$fs->hasDirectory(FsContext::DATA_EXPORT));
        } finally {
            Hilos::$fs = $previousFs;
        }
    }
}

abstract class ExportActivationHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];

    /**
     * @throws IncompleteFeatureActivationException When the fixture names no export directory
     */
    public static function checkDirectory(): void
    {
        static::refuseDataExportWithoutDirectory();
    }
}
