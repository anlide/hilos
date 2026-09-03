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
                ProtectedModeStateSignalData::passIssued => false,
                ProtectedModeStateSignalData::bannerMessage => null,
            ],
            $state->toArray(),
        );
    }

    public function testTheBannerSentenceSurvivesTheRoundTrip(): void
    {
        // The frame told to somebody the mode lets in: the surface copy stays null because they
        // render no surface, and the sentence they do render travels in its own field.
        $state = new ProtectedModeStateSignalData(
            active: false,
            operation: 'restore',
            acceptsPass: true,
            bannerMessage: 'The restore is being verified.',
        );

        $restored = ProtectedModeStateSignalData::fromArray($state->toArray());

        $this->assertFalse($restored->active);
        $this->assertTrue($restored->acceptsPass);
        $this->assertNull($restored->title);
        $this->assertNull($restored->message);
        $this->assertSame('The restore is being verified.', $restored->bannerMessage);
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

    public function testTheTwoVerificationBitsTravelIndependently(): void
    {
        // The pair is the whole point of the leaf: the window can be open with nothing minted,
        // and each half has to survive the round-trip without borrowing the other's value.
        $open = ProtectedModeStateSignalData::fromArray(
            (new ProtectedModeStateSignalData(active: true, acceptsPass: true))->toArray(),
        );
        $minted = ProtectedModeStateSignalData::fromArray(
            (new ProtectedModeStateSignalData(active: true, acceptsPass: true, passIssued: true))->toArray(),
        );

        $this->assertTrue($open->acceptsPass);
        $this->assertFalse($open->passIssued);
        $this->assertTrue($minted->acceptsPass);
        $this->assertTrue($minted->passIssued);
    }

    public function testAFrameWithoutTheSecondBitOffersNoField(): void
    {
        // Same fail-safe direction as the missing active flag: a payload that says nothing about
        // minting must not put a field on the stub that can take nothing.
        $restored = ProtectedModeStateSignalData::fromArray([
            ProtectedModeStateSignalData::active => true,
            ProtectedModeStateSignalData::acceptsPass => true,
        ]);

        $this->assertFalse($restored->passIssued);
    }
}
