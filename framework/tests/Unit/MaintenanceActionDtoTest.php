<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\ProtectedMode\DTO\ManualMaintenanceEnableSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the maintenance section's action and handover DTOs.
 *
 * Circle edits name a verifier or take one out. Both DTOs parse from the raw WebSocket
 * envelope, tolerating the optional FIELD_DATA wrapper. Naming carries the address as typed,
 * trimmed and nothing else; taking out carries the row key of the membership, not its address.
 * Manual entry carries no browser fields; its internal ask requires both server-resolved identity halves.
 */
final class MaintenanceActionDtoTest extends TestCase
{
    public function testEnableActionCarriesNothingFromTheBrowser(): void
    {
        $dto = MaintenanceEnableActionDTO::fromArray([
            'acceptKey' => 'forged', 'sessionHash' => 'forged', 'operation' => 'restore',
            'entryMode' => 'freeze', 'message' => 'custom words',
        ]);
        $this->assertSame(HilosSignalConstants::MAINTENANCE_ENABLE, $dto->getAction());
        $this->assertSame([], $dto->toArray());
        $this->assertSame([], MaintenanceEnableActionDTO::SECRET_FIELDS);
    }

    public function testEnableAskRoundTripsRequiredAndNullableFields(): void
    {
        foreach ([null, 'request-1'] as $requestId) {
            $ask = new ManualMaintenanceEnableSignalData(
                'accept-1', 'session-hash', $requestId, HilosSignalConstants::MAINTENANCE_ENABLE,
                HilosSignalConstants::MAINTENANCE_ENABLE_DONE, $requestId === null ? null : 'Maintenance is on',
            );
            $this->assertEquals($ask, ManualMaintenanceEnableSignalData::fromArray($ask->toArray()));
        }

        $wire = $ask->toArray();
        unset($wire['requestId'], $wire['successMessage']);
        $parsed = ManualMaintenanceEnableSignalData::fromArray($wire);
        $this->assertNull($parsed->requestId);
        $this->assertNull($parsed->successMessage);
    }

    #[DataProvider('invalidEnableAsks')]
    public function testEnableAskRefusesMissingMistypedOrEmptyIdentity(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        ManualMaintenanceEnableSignalData::fromArray($payload);
    }

    public static function invalidEnableAsks(): iterable
    {
        $valid = [
            'acceptKey' => 'accept-1', 'sessionHash' => 'session-hash',
            'action' => HilosSignalConstants::MAINTENANCE_ENABLE,
            'replySignal' => HilosSignalConstants::MAINTENANCE_ENABLE_DONE,
        ];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            yield 'mistyped ' . $key => [array_replace($valid, [$key => 7])];
        }
        yield 'empty accept key' => [array_replace($valid, ['acceptKey' => ''])];
        yield 'empty session hash' => [array_replace($valid, ['sessionHash' => ''])];
        yield 'CLI identity is not a browser ask' => [array_replace($valid, ['acceptKey' => '', 'sessionHash' => null])];
    }

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
