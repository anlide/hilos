<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\ReHydrateRound;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the re-hydrate barrier (HIL-436, HIL-694).
 *
 * The barrier is what stands between a restored database and the moment anybody is let back in to
 * read it, and it is pure state on purpose - so the four ways a round can end are provable without
 * a stand. Two of them are the interesting ones: a participant that answers "I could not re-read"
 * and one that never answers at all both leave the node closed, because a process holding caches
 * of a database that no longer exists would answer a verifier with a fiction.
 *
 * The fifth way is the round's number, and it is the reason two consecutive restores do not read
 * as one: every round is opened over the same labels, so without the number a straggler from the
 * previous round is the answer this one has been waiting for.
 */
final class ReHydrateRoundTest extends TestCase
{
    /** Deadline used by the cases that are not about expiry; far enough away to never fire. */
    private const float LATE_DEADLINE = 1_000.0;

    /** Round number used wherever the case is not about telling two rounds apart. */
    private const int ROUND = 1;

    public function testTheBarrierClosesWhenEveryParticipantConfirms(): void
    {
        $round = new ReHydrateRound();
        $round->start(
            self::ROUND,
            [ReHydrateRound::daemonParticipant(), ReHydrateRound::workerParticipant(1)],
            self::LATE_DEADLINE,
        );

        $this->assertFalse($round->isSettled(), 'A round with answers outstanding is not settled');

        $round->ack(self::ROUND, ReHydrateRound::daemonParticipant(), true, null);
        $this->assertFalse($round->isSettled(), 'One answer of two still leaves somebody to wait for');

        $round->ack(self::ROUND, ReHydrateRound::workerParticipant(1), true, null);
        $this->assertTrue($round->isSettled());
        $this->assertTrue($round->isComplete());
        $this->assertSame([], $round->problems());
    }

    public function testARoundOverNobodyIsCompleteImmediately(): void
    {
        $round = new ReHydrateRound();
        $round->start(self::ROUND, [], self::LATE_DEADLINE);

        $this->assertTrue($round->isSettled(), 'A daemon with no workers has nobody to wait for');
        $this->assertTrue($round->isComplete());
    }

    public function testANegativeAnswerSettlesTheRoundWithoutCompletingIt(): void
    {
        $round = new ReHydrateRound();
        $round->start(self::ROUND, [ReHydrateRound::workerParticipant(2)], self::LATE_DEADLINE);

        $round->ack(self::ROUND, ReHydrateRound::workerParticipant(2), false, 'connection gone');

        $this->assertTrue($round->isSettled(), 'A failure is a final answer, not a reason to keep waiting');
        $this->assertFalse($round->isComplete(), 'A process that could not re-read must not open the node');
        $this->assertSame(['worker #2: read failed: connection gone'], $round->problems());
    }

    public function testAFailureWithNothingToQuoteStillNamesItsParticipant(): void
    {
        $round = new ReHydrateRound();
        $round->start(self::ROUND, [ReHydrateRound::workerParticipant(3)], self::LATE_DEADLINE);

        $round->ack(self::ROUND, ReHydrateRound::workerParticipant(3), false, null);

        $this->assertSame(['worker #3: read failed'], $round->problems());
    }

    public function testTheDeadlineWritesOffWhoeverIsStillSilent(): void
    {
        $round = new ReHydrateRound();
        $round->start(
            self::ROUND,
            [ReHydrateRound::daemonParticipant(), ReHydrateRound::nodeParticipant('node-b')],
            deadline: 100.0,
        );
        $round->ack(self::ROUND, ReHydrateRound::daemonParticipant(), true, null);

        $round->expire(99.0);
        $this->assertFalse($round->isSettled(), 'Nothing happens before the deadline');

        $round->expire(100.0);
        $this->assertTrue($round->isSettled());
        $this->assertFalse($round->isComplete(), 'Not hearing back is not the same as hearing "ready"');
        $this->assertSame(['node-b: timeout'], $round->problems());
    }

    public function testADroppedParticipantIsNotWaitedForAndIsNotAProblem(): void
    {
        $round = new ReHydrateRound();
        $round->start(
            self::ROUND,
            [ReHydrateRound::daemonParticipant(), ReHydrateRound::workerParticipant(1)],
            self::LATE_DEADLINE,
        );
        $round->ack(self::ROUND, ReHydrateRound::daemonParticipant(), true, null);

        $round->drop(ReHydrateRound::workerParticipant(1));

        $this->assertTrue($round->isSettled(), 'A worker that died cannot answer, so it is taken off the count');
        $this->assertTrue($round->isComplete(), 'Whatever starts in its place reads the database already in place');
        $this->assertSame([], $round->problems());
    }

    public function testAnAnswerFromThePreviousRoundDoesNotCloseThisOne(): void
    {
        $first = new ReHydrateRound();
        $first->start(1, [ReHydrateRound::workerParticipant(1)], deadline: 100.0);
        $first->expire(100.0);

        // Same participants, because the labels name processes and the processes did not change:
        // this is the whole reason the round needs a number of its own.
        $second = new ReHydrateRound();
        $second->start(2, [ReHydrateRound::workerParticipant(1)], self::LATE_DEADLINE);

        $this->assertFalse(
            $second->ack(1, ReHydrateRound::workerParticipant(1), true, null),
            'An answer to the round that timed out belongs to that round and is refused here',
        );
        $this->assertFalse($second->isSettled(), 'The worker this round waits for has still not answered');

        $this->assertTrue($second->ack(2, ReHydrateRound::workerParticipant(1), true, null));
        $this->assertTrue($second->isComplete());
    }

    public function testAnAnswerWithNoNumberOnItBelongsToNoRound(): void
    {
        $round = new ReHydrateRound();
        $round->start(self::ROUND, [ReHydrateRound::workerParticipant(1)], self::LATE_DEADLINE);

        $this->assertFalse(
            $round->ack(0, ReHydrateRound::workerParticipant(1), true, null),
            'Rounds are numbered from 1, so a frame that lost its number matches none of them',
        );
        $this->assertFalse($round->isSettled());
    }

    public function testTheRoundAnswersUnderTheNumberItWasStartedWith(): void
    {
        $round = new ReHydrateRound();
        $round->start(7, [], self::LATE_DEADLINE);

        $this->assertSame(7, $round->round());
    }

    public function testALateOrUnknownAnswerChangesNothing(): void
    {
        $round = new ReHydrateRound();
        $round->start(self::ROUND, [ReHydrateRound::workerParticipant(1)], deadline: 100.0);
        $round->expire(100.0);

        $this->assertTrue(
            $round->ack(self::ROUND, ReHydrateRound::workerParticipant(1), true, null),
            'A written-off participant answered the right round; it is the round that has moved on',
        );
        $round->ack(self::ROUND, ReHydrateRound::workerParticipant(9), false, 'never invited');

        $this->assertSame(
            ['worker #1: timeout'],
            $round->problems(),
            'A written-off participant does not come back, and a stranger never joins',
        );
        $this->assertFalse($round->isComplete());
    }
}
