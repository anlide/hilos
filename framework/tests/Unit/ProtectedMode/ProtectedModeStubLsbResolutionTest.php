<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Database\Context\DbContext;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeStubConstants;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the LSB loss on the protected-mode stub registry accessor.
 *
 * The same defect HIL-275 fixed for the backup catalog and HIL-489 for the notification
 * registries: {@see Hilos::protectedModeStubRegistry()} resolves `static::PROTECTED_MODE_STUB`
 * from a bare `Hilos::` call-site, so it would bind to the abstract base and answer the
 * framework default however the project declared itself. Read through the live seam,
 * {@see ProtectedModeStubCopy::forOperation()}, so the case pins what a caller actually sees.
 */
final class ProtectedModeStubLsbResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore the captured facade class to the base default for later cases.
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testTheProjectsOwnWordsReachTheLiveSeam(): void
    {
        // Sanity: without a project facade captured, the base accessor names no such words.
        self::assertNotSame('Restoring a backup', ProtectedModeStubCopy::forOperation('restore')->title);

        ProtectedModeStubLsbTestHilos::initBrowser();

        $copy = ProtectedModeStubCopy::forOperation('restore');

        self::assertSame('Restoring a backup', $copy->title);
        self::assertSame('The data is being restored.', $copy->message);
    }

    public function testAnUnregisteredOperationFallsBackToTheProjectsDefault(): void
    {
        ProtectedModeStubLsbTestHilos::initBrowser();

        $copy = ProtectedModeStubCopy::forOperation('reindex');

        self::assertSame('Project maintenance', $copy->title);
        self::assertSame('This project is briefly unavailable.', $copy->message);
    }
}

/**
 * Project facade fixture naming a protected-mode stub registry of its own.
 */
final class ProtectedModeStubLsbTestHilos extends Hilos
{
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Project maintenance',
            ProtectedModeStubConstants::MESSAGE => 'This project is briefly unavailable.',
        ],
        'restore' => [
            ProtectedModeStubConstants::TITLE => 'Restoring a backup',
            ProtectedModeStubConstants::MESSAGE => 'The data is being restored.',
        ],
    ];

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new ProtectedModeStubLsbTestDbContext();
    }
}

/**
 * No-op DB context so the abstract facade fixture is instantiable.
 */
final class ProtectedModeStubLsbTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the stub registry resolution fixture.
     */
    public function configure(): void
    {
    }
}
