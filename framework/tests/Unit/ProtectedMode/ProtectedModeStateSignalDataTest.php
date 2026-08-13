<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the payload of the pushed protected-mode frame.
 *
 * The frame is the only way a page that was already open learns the mode turned on or off, so
 * what it carries is pinned in both directions: the entry frame keeps its operation and its
 * words through a serialization round-trip, and the lift frame carries none of them - nothing
 * renders words on the way out, and a stray title would be the pre-restore one.
 */
final class ProtectedModeStateSignalDataTest extends TestCase
{
    public function testEntryFrameSurvivesTheRoundTrip(): void
    {
        $state = new ProtectedModeStateSignalData(
            active: true,
            operation: 'restore',
            title: 'Maintenance in progress',
            message: 'The application is briefly unavailable.',
        );

        $restored = ProtectedModeStateSignalData::fromArray($state->toArray());

        $this->assertTrue($restored->active);
        $this->assertSame('restore', $restored->operation);
        $this->assertSame('Maintenance in progress', $restored->title);
        $this->assertSame('The application is briefly unavailable.', $restored->message);
    }

    public function testLiftFrameCarriesNoCopy(): void
    {
        $state = new ProtectedModeStateSignalData(active: false);

        $this->assertSame(
            [
                ProtectedModeStateSignalData::active => false,
                ProtectedModeStateSignalData::operation => null,
                ProtectedModeStateSignalData::title => null,
                ProtectedModeStateSignalData::message => null,
                ProtectedModeStateSignalData::acceptsPass => false,
            ],
            $state->toArray(),
        );
    }

    public function testAFrameWithoutFieldsReadsAsInactive(): void
    {
        // Fail-safe on a degenerate frame: an unreadable payload must not lock a client behind a
        // maintenance surface nobody can lift, so the missing flag reads as "no freeze".
        $restored = ProtectedModeStateSignalData::fromArray([]);

        $this->assertFalse($restored->active);
        $this->assertNull($restored->operation);
        $this->assertNull($restored->title);
        $this->assertNull($restored->message);
    }
}
