<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Code;

use Hilos\Auth\Code\DTO\CodeSendProgressSignalData;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use PHPUnit\Framework\TestCase;

/**
 * Tests the two frames the send-progress line travels on (HIL-826).
 *
 * Both are round-tripped because both cross a process boundary as arrays, but the cases worth
 * naming are the ones where an EMPTY field means something: the frame with no state is what
 * takes the line off the screen, and the step frame that names no session is what keeps the
 * mail subsystem from learning who was asking.
 */
final class CodeSendSignalDataTest extends TestCase
{
    private const string SESSION_HASH = '4e1243bd22c66e76c2ba9eddc1f91394e57f9f83';

    private const string TICKET = 'a1b2c3d4e5f60718';

    private const string REFUSAL = 'mailbox unavailable (550)';

    public function testTheProgressFrameRoundTripsThroughArray(): void
    {
        $original = new CodeSendProgressSignalData(
            state: StateHilosCodeSendAttempt::STATE_FAILED,
            channel: StateHilosCodeSendAttempt::CHANNEL_EMAIL,
            detail: self::REFUSAL,
        );

        $restored = CodeSendProgressSignalData::fromArray($original->toArray());

        $this->assertEquals($original, $restored);
    }

    public function testTheEmptyProgressFrameSurvivesTheRoundTrip(): void
    {
        $restored = CodeSendProgressSignalData::fromArray((new CodeSendProgressSignalData())->toArray());

        // The commonest frame the signal carries: it is what a handshake is answered with when
        // the session is waiting for nothing, and refusing it would refuse silence its meaning.
        $this->assertNull($restored->state);
        $this->assertNull($restored->channel);
        $this->assertNull($restored->detail);
    }

    public function testTheProgressFrameOfNoLineIsTheEmptyOne(): void
    {
        $frame = CodeSendProgressSignalData::fromAttempt(null);

        $this->assertNull($frame->state);
        $this->assertNull($frame->channel);
    }

    public function testTheQueuedStepIsTheOnlyOneThatNamesTheSession(): void
    {
        $queued = CodeSendStepSignalData::queued(
            self::TICKET,
            self::SESSION_HASH,
            StateHilosCodeSendAttempt::CHANNEL_EMAIL,
        );

        $this->assertSame(self::SESSION_HASH, $queued->sessionTokenHash);
        $this->assertSame(StateHilosCodeSendAttempt::CHANNEL_EMAIL, $queued->channel);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $queued->state);
        $this->assertEquals($queued, CodeSendStepSignalData::fromArray($queued->toArray()));
    }

    public function testALaterStepCarriesTheTicketAndNothingAboutWhoAsked(): void
    {
        $step = CodeSendStepSignalData::step(
            self::TICKET,
            StateHilosCodeSendAttempt::STATE_FAILED,
            self::REFUSAL,
        );

        // This is the whole of "mail learns nothing about auth": the transport hands back the
        // opaque ticket it was given, and the owner does the matching.
        $this->assertNull($step->sessionTokenHash);
        $this->assertNull($step->channel);
        $this->assertSame(self::REFUSAL, $step->detail);
        $this->assertEquals($step, CodeSendStepSignalData::fromArray($step->toArray()));
    }

    public function testAStepWithNoTicketIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        // A step that names no send names no line either, and guessing which one it meant is
        // exactly the mistake the ticket exists to prevent.
        CodeSendStepSignalData::fromArray(['state' => StateHilosCodeSendAttempt::STATE_SENT]);
    }
}
