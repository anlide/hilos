<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\DTO\AccountMergeCancelActionDTO;
use Hilos\Users\DTO\AccountMergeCancelSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The operation address and waiting submit survive the cancellation handover unchanged. */
final class AccountMergeCancelDTOTest extends TestCase
{
    public function testActionRoundTripsRawAndWrappedPayloads(): void
    {
        $dto = new AccountMergeCancelActionDTO(57);
        self::assertSame(HilosSignalConstants::HILOS_USER_MERGE_CANCEL, $dto->getAction());
        self::assertSame(['operationId' => 57], $dto->toArray());
        self::assertSame([], $dto::SECRET_FIELDS);
        self::assertEquals($dto, AccountMergeCancelActionDTO::fromArray($dto->toArray()));
        self::assertEquals($dto, AccountMergeCancelActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $dto->toArray(),
        ]));
    }

    public function testHandoverRoundTripsTrackedAndUntrackedRequests(): void
    {
        foreach (['request-1', null] as $requestId) {
            $dto = new AccountMergeCancelSignalData(57, HilosSignalConstants::HILOS_ACCOUNT_MERGE_CANCEL_DONE,
                'accept-1', $requestId, HilosSignalConstants::HILOS_USER_MERGE_CANCEL, null);
            self::assertSame([
                'operationId' => 57,
                'replySignal' => HilosSignalConstants::HILOS_ACCOUNT_MERGE_CANCEL_DONE,
                'acceptKey' => 'accept-1',
                'requestId' => $requestId,
                'action' => HilosSignalConstants::HILOS_USER_MERGE_CANCEL,
                'successMessage' => null,
            ], $dto->toArray());
            self::assertEquals($dto, AccountMergeCancelSignalData::fromJson($dto->toJson()));
        }
    }

    /** @return iterable<string, array{class-string, array<string, mixed>}> Invalid operation addresses and submit fields */
    public static function invalidPayloads(): iterable
    {
        foreach ([AccountMergeCancelActionDTO::class, AccountMergeCancelSignalData::class] as $class) {
            foreach ([0, -1, '57', null, 1.5, true] as $index => $id) {
                yield $class . $index => [$class, ['operationId' => $id,
                    'replySignal' => HilosSignalConstants::HILOS_ACCOUNT_MERGE_CANCEL_DONE,
                    'acceptKey' => 'accept-1', 'action' => HilosSignalConstants::HILOS_USER_MERGE_CANCEL]];
            }
            yield $class . ' missing' => [$class, []];
        }
        $valid = new AccountMergeCancelSignalData(57, HilosSignalConstants::HILOS_ACCOUNT_MERGE_CANCEL_DONE,
            'accept-1', 'request-1', HilosSignalConstants::HILOS_USER_MERGE_CANCEL, null)->toArray();
        foreach (['replySignal', 'acceptKey', 'action'] as $field) {
            $payload = $valid;
            unset($payload[$field]);
            yield 'missing ' . $field => [AccountMergeCancelSignalData::class, $payload];
        }
        foreach (['replySignal', 'acceptKey', 'action', 'requestId', 'successMessage'] as $field) {
            yield 'wrong type ' . $field => [AccountMergeCancelSignalData::class, array_replace($valid, [$field => 57])];
        }
    }

    /**
     * @param class-string $class Payload reader
     * @param array<string, mixed> $payload Invalid transport fields
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRefused(string $class, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromArray($payload);
    }
}
