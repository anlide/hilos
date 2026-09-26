<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the maintenance section's action DTOs (HIL-1120, HIL-1121).
 *
 * Two actions: naming a verifier and taking one out. Both DTOs parse from the raw WebSocket
 * envelope, tolerating the optional FIELD_DATA wrapper. Naming carries the address as typed,
 * trimmed and nothing else; taking out carries the row key of the membership, not its address.
 */
final class MaintenanceActionDtoTest extends TestCase
{
    public function testTheCircleAddDtoReadsTheAddressThroughTheDataWrapper(): void
    {
        $dto = MaintenanceCircleAddActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [
                MaintenanceCircleAddActionDTO::identifier => '  +7 900 000-00-00  ',
            ],
        ]);

        $this->assertSame(HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, $dto->getAction());
        $this->assertSame(
            '+7 900 000-00-00',
            $dto->identifier,
            'What the operator typed is trimmed and otherwise left alone: the form it is stored in is'
            . ' the server\'s to decide',
        );
        $this->assertSame(
            [MaintenanceCircleAddActionDTO::identifier => '+7 900 000-00-00'],
            $dto->toArray(),
            'One field and no identity type beside it: which type the address is proven under is'
            . ' answered by the identity that carries it, never by the client',
        );
    }

    public function testTheCircleAddDtoRefusesAPayloadWithNoAddress(): void
    {
        $this->expectException(InvalidFormatException::class);

        MaintenanceCircleAddActionDTO::fromArray([]);
    }

    public function testTheCircleRemoveDtoNamesTheMembershipAndNotTheAddress(): void
    {
        $dto = MaintenanceCircleRemoveActionDTO::fromArray([
            MaintenanceCircleRemoveActionDTO::memberId => 12,
        ]);

        $this->assertSame(HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE, $dto->getAction());
        $this->assertSame(12, $dto->memberId);
        $this->assertSame([MaintenanceCircleRemoveActionDTO::memberId => 12], $dto->toArray());
    }

    public function testTheCircleRemoveDtoRefusesAPayloadWithNoMembership(): void
    {
        $this->expectException(InvalidFormatException::class);

        MaintenanceCircleRemoveActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => []]);
    }
}
