<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the maintenance section's action DTOs (HIL-1120).
 *
 * The one action today names a verifier: its DTO parses from the raw WebSocket envelope -
 * tolerating the optional FIELD_DATA wrapper - and carries the address as typed, trimmed and
 * nothing else.
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
}
