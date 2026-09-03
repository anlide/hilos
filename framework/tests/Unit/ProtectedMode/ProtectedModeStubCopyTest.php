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
 * one place a client is allowed to word the screen. The banner sentence resolves by the same
 * rules and is asserted beside the surface copy, because the two audiences share one entry and a
 * reader that only ever read two fields would look just as green.
 */
final class ProtectedModeStubCopyTest extends TestCase
{
    private const array REGISTRY = [
        'restore' => [
            ProtectedModeStubConstants::TITLE => 'Restoring a backup',
            ProtectedModeStubConstants::MESSAGE => 'The data is being restored.',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'The restore is being verified.',
        ],
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Maintenance in progress',
            ProtectedModeStubConstants::MESSAGE => 'Work is in progress.',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'The work is being verified.',
        ],
    ];

    public function testTheOperationsOwnEntryWins(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry(self::REGISTRY, 'restore');

        $this->assertSame('Restoring a backup', $copy->title);
        $this->assertSame('The data is being restored.', $copy->message);
        $this->assertSame('The restore is being verified.', $copy->bannerMessage);
    }

    public function testAnUnregisteredOperationFallsBackToTheDefaultEntry(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry(self::REGISTRY, 'reindex');

        $this->assertSame('Maintenance in progress', $copy->title);
        $this->assertSame('Work is in progress.', $copy->message);
        $this->assertSame('The work is being verified.', $copy->bannerMessage);
    }

    public function testAFreezeWithoutARecordedOperationFallsBackToTheDefaultEntry(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry(self::REGISTRY, null);

        $this->assertSame('Maintenance in progress', $copy->title);
        $this->assertSame('Work is in progress.', $copy->message);
        $this->assertSame('The work is being verified.', $copy->bannerMessage);
    }

    public function testARegistryWithoutADefaultEntryAnswersNothing(): void
    {
        $copy = ProtectedModeStubCopy::fromRegistry([], 'restore');

        $this->assertNull($copy->title);
        $this->assertNull($copy->message);
        $this->assertNull($copy->bannerMessage);
    }

    public function testAnEntryThatWordsOnlyTheSurfaceLeavesTheBannerSentenceNull(): void
    {
        // The validator refuses such an entry at startup, so this is what a registry read
        // some other way resolves to - a caller holding a catalog of its own, a fixture. The
        // banner falls back to the frontend's last-resort copy from here, exactly as the
        // surface does when the registry words neither audience.
        $copy = ProtectedModeStubCopy::fromRegistry([
            ProtectedModeStubConstants::DEFAULT_OPERATION => [
                ProtectedModeStubConstants::TITLE => 'Maintenance in progress',
                ProtectedModeStubConstants::MESSAGE => 'Work is in progress.',
            ],
        ], 'restore');

        $this->assertSame('Maintenance in progress', $copy->title);
        $this->assertNull($copy->bannerMessage);
    }

    public function testTheFrameworkShipsADefaultEntryEveryOperationCanFallBackOn(): void
    {
        // Reads the live facade rather than the fixture above: the framework always ships a
        // default entry, so a demo that registers nothing still gets words on the screen. Whether
        // a project's own override actually reaches this seam is pinned separately, by
        // {@see ProtectedModeStubLsbResolutionTest}.
        $copy = ProtectedModeStubCopy::forOperation('an-operation-nobody-registered');

        $this->assertNotNull($copy->title);
        $this->assertNotNull($copy->message);
        $this->assertNotNull($copy->bannerMessage);
        $this->assertArrayHasKey(
            ProtectedModeStubConstants::DEFAULT_OPERATION,
            Hilos::protectedModeStubRegistry(),
        );
    }
}
