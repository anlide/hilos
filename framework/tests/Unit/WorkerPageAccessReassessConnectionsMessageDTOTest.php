<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * The wire form of the by-connection re-decision announcement (HIL-652).
 *
 * One class travels both ways - worker to master, then master to every worker - so the frame
 * is put through {@see WorkerDTO::factoryWorkerDTO()} rather than `fromArray()`: an
 * unregistered type is refused before anyone looks inside, and half of what is asserted here
 * is that the registry knows this one and tells it apart from its by-user twin.
 */
final class WorkerPageAccessReassessConnectionsMessageDTOTest extends TestCase
{
    /** @var list<string> Accept keys of a session that had two tabs open when it ended */
    private const array ACCEPT_KEYS = ['ak-first', 'ak-second'];

    public function testTheTypeAndTheKeysSurviveTheRoundTripInBothDirections(): void
    {
        $outbound = WorkerDTO::factoryWorkerDTO(
            new WorkerPageAccessReassessConnectionsMessageDTO(self::ACCEPT_KEYS)->toJson(),
        );

        $this->assertInstanceOf(WorkerPageAccessReassessConnectionsMessageDTO::class, $outbound);
        $this->assertSame(WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_CONNECTIONS, $outbound->getType());
        $this->assertSame(self::ACCEPT_KEYS, $outbound->acceptKeys);

        // The master re-sends what it received; the frame it writes back out is the same class,
        // so the second leg is the first one over again rather than a second format.
        $inbound = WorkerDTO::factoryWorkerDTO($outbound->toJson());

        $this->assertInstanceOf(WorkerPageAccessReassessConnectionsMessageDTO::class, $inbound);
        $this->assertSame(self::ACCEPT_KEYS, $inbound->acceptKeys);
    }

    /**
     * The one field is the whole frame: an announcement naming no connection would ask every
     * worker of the node to re-judge a list it invented.
     */
    public function testAFrameNamingNoConnectionIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WorkerPageAccessReassessConnectionsMessageDTO::FIELD_ACCEPT_KEYS);

        WorkerDTO::factoryWorkerDTO((string)json_encode([
            WorkerDTO::TYPE => WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_CONNECTIONS,
        ]));
    }
}
