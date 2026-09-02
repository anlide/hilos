<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the whole-database re-hydrate frames (HIL-479, HIL-436, HIL-694).
 *
 * The announcement is the transport half of a signal that had an applicator and no emitter; the
 * answer and the verdict turn it from a shout into a barrier. What has to hold is that each one
 * survives the round trip and that the factory still tells them apart - a frame that decoded into
 * something else would leave a restored node reading a database it has already forgotten, or
 * report a barrier as closed when it never was.
 *
 * The round number rides on both halves and has to survive with them: it is what tells this
 * announcement's answers from the previous announcement's, and both frames read a missing one as
 * 0 on purpose - a number that matches no round rather than one that matches whichever is open.
 */
final class DbReHydrateFrameTest extends TestCase
{
    /** Announcing agent used across the cases, in the shape agent ids actually take. */
    private const string ANNOUNCER = 'backup:0';

    /** Round the cases announce and answer under; any number above 0 does. */
    private const int ROUND = 4;

    public function testTheFrameTypeIsTheSignalTypeItself(): void
    {
        $this->assertSame(SignalTypeConstants::DB_REHYDRATE, WorkerConstants::MESSAGE_DB_REHYDRATE);
        $this->assertSame(
            WorkerConstants::MESSAGE_DB_REHYDRATE,
            new WorkerDbReHydrateMessageDTO(self::ANNOUNCER, self::ROUND)->getType(),
        );
    }

    public function testTheFrameCarriesNothingBesidesItsTypeItsAnnouncerAndItsRound(): void
    {
        $this->assertSame(
            [
                WorkerDTO::TYPE => WorkerConstants::MESSAGE_DB_REHYDRATE,
                AgentConstants::FIELD_AGENT_ID => self::ANNOUNCER,
                WorkerDbReHydrateMessageDTO::FIELD_ROUND => self::ROUND,
            ],
            new WorkerDbReHydrateMessageDTO(self::ANNOUNCER, self::ROUND)->toArray(),
        );
    }

    public function testTheFactoryRebuildsTheFrameFromItsJson(): void
    {
        $parsed = WorkerDTO::factoryWorkerDTO(new WorkerDbReHydrateMessageDTO(self::ANNOUNCER, self::ROUND)->toJson());

        $this->assertInstanceOf(WorkerDbReHydrateMessageDTO::class, $parsed);
        $this->assertSame(WorkerConstants::MESSAGE_DB_REHYDRATE, $parsed->getType());
        $this->assertSame(self::ANNOUNCER, $parsed->agentId);
        $this->assertSame(self::ROUND, $parsed->round);
    }

    public function testAnAnnouncementOnItsWayToTheDaemonCarriesNoRoundYet(): void
    {
        $this->assertSame(
            0,
            new WorkerDbReHydrateMessageDTO(self::ANNOUNCER)->round,
            'The agent announces before any round exists; the daemon mints the number when it opens one',
        );
        $this->assertSame(0, WorkerDbReHydrateMessageDTO::fromArray([])->round);
    }

    public function testAWorkerAnswerSurvivesTheRoundTripOnBothOutcomes(): void
    {
        $good = WorkerDTO::factoryWorkerDTO(new WorkerDbReHydratedDTO(round: self::ROUND, ok: true)->toJson());
        $this->assertInstanceOf(WorkerDbReHydratedDTO::class, $good);
        $this->assertSame(self::ROUND, $good->round);
        $this->assertTrue($good->ok);
        $this->assertNull($good->error);

        $bad = WorkerDTO::factoryWorkerDTO(
            new WorkerDbReHydratedDTO(round: self::ROUND, ok: false, error: 'connection gone')->toJson(),
        );
        $this->assertInstanceOf(WorkerDbReHydratedDTO::class, $bad);
        $this->assertSame(self::ROUND, $bad->round);
        $this->assertFalse($bad->ok);
        $this->assertSame('connection gone', $bad->error);
    }

    public function testAWorkerAnswerWithNoVerdictOnTheWireReadsAsAFailure(): void
    {
        $parsed = WorkerDbReHydratedDTO::fromArray([]);

        $this->assertFalse($parsed->ok, 'An unreadable answer is not a confirmation');
        $this->assertSame(0, $parsed->round, 'And an answer with no number on it belongs to no round');
    }

    public function testTheVerdictCarriesItsProblemsToTheAnnouncer(): void
    {
        $problems = ['worker #2: read failed: connection gone', 'node-b: timeout'];
        $parsed = WorkerDTO::factoryWorkerDTO(
            new DbReHydrateCompleteDTO(self::ANNOUNCER, complete: false, problems: $problems)->toJson(),
        );

        $this->assertInstanceOf(DbReHydrateCompleteDTO::class, $parsed);
        $this->assertSame(self::ANNOUNCER, $parsed->agentId);
        $this->assertFalse($parsed->complete);
        $this->assertSame($problems, $parsed->problems);
    }

    public function testAVerdictWithNothingOnTheWireReadsAsNotComplete(): void
    {
        $parsed = DbReHydrateCompleteDTO::fromArray([]);

        $this->assertFalse($parsed->complete, 'An unreadable verdict is not a confirmation');
        $this->assertNull($parsed->agentId, 'A verdict that names nobody is addressed to nobody');
        $this->assertSame([], $parsed->problems);
    }
}
