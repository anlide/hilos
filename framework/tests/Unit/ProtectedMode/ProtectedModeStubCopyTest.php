<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeStubConstants;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for resolving the maintenance-surface copy out of the stub registry.
 *
 * The registry is what a project overrides to speak in its own voice, so the fallbacks are the
 * contract: an operation nobody registered still gets words, and a registry stripped of even the
 * default yields nulls rather than an invented sentence - the frontend's last-resort copy is the
 * one place a client is allowed to word the screen.
 */
final class ProtectedModeStubCopyTest extends TestCase
{
    private const array REGISTRY = [
        'restore' => [
            ProtectedModeStubConstants::TITLE => 'Restoring a backup',
            ProtectedModeStubConstants::MESSAGE => 'The data is being restored.',
        ],
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Maintenance in progress',
            ProtectedModeStubConstants::MESSAGE => 'Work is in progress.',
        ],
    ];

    public function testTheOperationsOwnEntryWins(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry(self::REGISTRY, 'restore');

        $this->assertSame('Restoring a backup', $copy->title);
        $this->assertSame('The data is being restored.', $copy->message);
    }

    public function testAnUnregisteredOperationFallsBackToTheDefaultEntry(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry(self::REGISTRY, 'reindex');

        $this->assertSame('Maintenance in progress', $copy->title);
        $this->assertSame('Work is in progress.', $copy->message);
    }

    public function testAFreezeWithoutARecordedOperationFallsBackToTheDefaultEntry(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry(self::REGISTRY, null);

        $this->assertSame('Maintenance in progress', $copy->title);
        $this->assertSame('Work is in progress.', $copy->message);
    }

    public function testARegistryWithoutADefaultEntryAnswersNothing(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry([], 'restore');

        $this->assertNull($copy->title);
        $this->assertNull($copy->message);
    }

    public function testTheFrameworkShipsADefaultEntryEveryOperationCanFallBackOn(): void
    {
        // Reads the live facade rather than the fixture above: the wiring from the constant to
        // the resolver is what makes a project's override arrive, and a demo that registers
        // nothing must still get words on the screen.
        $copy = ProtectedModeStubCopy::forOperation('an-operation-nobody-registered');

        $this->assertNotNull($copy->title);
        $this->assertNotNull($copy->message);
        $this->assertArrayHasKey(
            ProtectedModeStubConstants::DEFAULT_OPERATION,
            Hilos::protectedModeStubRegistry(),
        );
    }
}
