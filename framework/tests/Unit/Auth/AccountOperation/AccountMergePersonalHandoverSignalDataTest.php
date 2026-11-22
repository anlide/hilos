<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\DTO\AccountMergePersonalHandoverSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataEnvelope;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The personal frame carries the project's rows as they are, under an exact go receive address. */
final class AccountMergePersonalHandoverSignalDataTest extends TestCase
{
    public function testExactKeysRoundTripTheRowsAsTheProjectGaveThem(): void
    {
        $data = self::frame()->toArray();
        self::assertSame(['operationId', 'stage', 'assignmentId', 'ownerIndex', 'rows'], array_keys($data));
        self::assertSame(2, $data['operationId']);
        self::assertSame('go', $data['stage']);
        self::assertSame(13, $data['assignmentId']);
        self::assertSame('7', $data['ownerIndex']);
        self::assertSame(['userProductCarts' => [['productId' => 5, 'quantity' => 2]], 'note' => null], $data['rows']);
        self::assertSame($data, AccountMergePersonalHandoverSignalData::fromArray(array_reverse($data, true))->toArray());
        self::assertSame($data, AccountMergePersonalHandoverSignalData::fromJson(json_encode($data, JSON_THROW_ON_ERROR))->toArray());
    }

    public function testNothingToGiveTravelsAsEmptyRows(): void
    {
        $frame = new AccountMergePersonalHandoverSignalData(2, AccountMergeStage::Go->value, 13, '7', []);
        self::assertSame([], AccountMergePersonalHandoverSignalData::fromJson($frame->toJson())->rows);
        self::assertSame([], AccountMergePersonalHandoverSignalData::fromJson(
            '{"operationId":2,"stage":"go","assignmentId":13,"ownerIndex":"7","rows":{}}',
        )->rows);
    }

    /** @return iterable<string, array{array}> Incomplete, mistyped and extra fields */
    public static function invalidFields(): iterable
    {
        $frame = self::frame()->toArray();
        foreach ($frame as $key => $value) {
            $wrong = $frame;
            unset($wrong[$key]);
            yield "missing $key" => [$wrong];
            foreach ([null, false, 1.5, 'text', 1, []] as $index => $bad) {
                if (get_debug_type($bad) === get_debug_type($value)) {
                    continue;
                }
                yield "wrong $key $index" => [array_replace($frame, [$key => $bad])];
            }
        }
        yield 'extra' => [$frame + ['unknown' => 'value']];
        yield 'rows list' => [array_replace($frame, ['rows' => [['productId' => 5]]])];
        yield 'rows decimal key' => [array_replace($frame, ['rows' => ['5' => ['quantity' => 2]]])];
    }

    /** @param array $data Broken transport fields */
    #[DataProvider('invalidFields')]
    public function testStrictKeysAndTypesRejectBrokenFrames(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergePersonalHandoverSignalData::fromArray($data);
    }

    /** @return iterable<string, array{array}> Coordinates outside a go receive address */
    public static function wrongCoordinates(): iterable
    {
        foreach (['operationId' => [0, -1], 'assignmentId' => [0, -1],
            'stage' => ['preparing', 'cancelling', 'GO', ''], 'ownerIndex' => ['07', '+7', '7 ', '0', '-7', '7.0', '']] as $key => $values) {
            foreach ($values as $index => $value) {
                yield "$key $index" => [array_replace(self::frame()->toArray(), [$key => $value])];
            }
        }
    }

    /** @param array $data Invalid immutable coordinates */
    #[DataProvider('wrongCoordinates')]
    public function testConstructionAndHydrationRejectInconsistentCoordinates(array $data): void
    {
        foreach ([fn () => AccountMergePersonalHandoverSignalData::fromArray($data),
            fn () => new AccountMergePersonalHandoverSignalData($data['operationId'], $data['stage'], $data['assignmentId'],
                $data['ownerIndex'], $data['rows'])] as $parse) {
            try {
                $parse();
                self::fail('The inconsistent personal handover address was accepted.');
            } catch (InvalidFormatException $failure) {
                self::assertStringContainsString('personal merge handover', $failure->getMessage());
            }
        }
    }

    public function testEnvelopeRouterAndSerializedFrameKeepReceiptMetadataAndOriginalSender(): void
    {
        $dto = self::frame();
        $encoded = SignalDataEnvelope::encode($dto);
        self::assertSame($dto->toArray(), SignalDataEnvelope::decode($encoded[SignalPayloadConstants::FIELD_DATA],
            $encoded[SignalPayloadConstants::FIELD_DATA_TYPE])->toArray());
        $source = new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, '9');
        $signal = new SignalDTO($source, new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER), new AgentSignalData($dto, 23),
            ['trace' => 'kept']);
        $restored = SignalDTO::fromArray(json_decode(json_encode($signal->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($signal->toArray(), $restored->toArray());
        self::assertSame('agent/hilos_user#9', SignalSource::describe($restored->signalSource));
        $router = new PersonalHandoverTestRouter();
        self::assertEquals([new AgentDestination(HilosAgentType::HILOS_USER, '7')], $router->getDestinations($restored));
        $hydrated = $router->createAgentSignalPayloadDTO(HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER,
            new AgentSignalData(new SignalData($dto->toArray()), 23));
        self::assertInstanceOf(AccountMergePersonalHandoverSignalData::class, $hydrated->data);
        self::assertSame(23, $hydrated->receiptId);
        self::assertSame($dto->toArray(), $hydrated->data->toArray());
    }

    /** @return AccountMergePersonalHandoverSignalData Typed frame with nested project rows */
    private static function frame(): AccountMergePersonalHandoverSignalData
    {
        return new AccountMergePersonalHandoverSignalData(2, AccountMergeStage::Go->value, 13, '7', [
            'userProductCarts' => [['productId' => 5, 'quantity' => 2]],
            'note' => null,
        ]);
    }
}

final class PersonalHandoverTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture with the inherited personal declaration */
    protected function hilosClass(): string
    {
        return PersonalHandoverTestHilos::class;
    }
}

abstract class PersonalHandoverTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => PersonalHandoverTestUser::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class PersonalHandoverTestUser extends AbstractUserAgent
{
}
