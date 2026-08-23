<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * The wire form of the access re-decision announcement (HIL-644).
 *
 * One class travels both ways - worker to master, then master to every worker - so the frame
 * is put through {@see WorkerDTO::factoryWorkerDTO()} rather than `fromArray()`: an
 * unregistered type is refused before anyone looks inside, and half of what is asserted here
 * is that the registry knows this one.
 */
final class WorkerPageAccessReassessMessageDTOTest extends TestCase
{
    private const int USER_ID = 41;

    public function testTheTypeAndTheUserSurviveTheRoundTripInBothDirections(): void
    {
        $outbound = WorkerDTO::factoryWorkerDTO(new WorkerPageAccessReassessMessageDTO(self::USER_ID)->toJson());

        $this->assertInstanceOf(WorkerPageAccessReassessMessageDTO::class, $outbound);
        $this->assertSame(WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_USER, $outbound->getType());
        $this->assertSame(self::USER_ID, $outbound->userId);

        // The master re-sends what it received; the frame it writes back out is the same class,
        // so the second leg is the first one over again rather than a second format.
        $inbound = WorkerDTO::factoryWorkerDTO($outbound->toJson());

        $this->assertInstanceOf(WorkerPageAccessReassessMessageDTO::class, $inbound);
        $this->assertSame(self::USER_ID, $inbound->userId);
    }

    /**
     * The one field is the whole frame: an announcement naming nobody would set every worker
     * sweeping for a user id it invented.
     */
    public function testAFrameNamingNoUserIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WorkerPageAccessReassessMessageDTO::FIELD_USER_ID);

        WorkerDTO::factoryWorkerDTO((string)json_encode([
            WorkerDTO::TYPE => WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_USER,
        ]));
    }
}
