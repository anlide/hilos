<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Generator;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountOperationAskSignalDataTest extends TestCase
{
    /** @return array<string, array{?string}> Singleton and indexed participant addresses */
    public static function ownerIndexes(): array
    {
        return ['singleton' => [null], 'indexed' => ['42']];
    }

    /** @param ?string $ownerIndex Participant instance address */
    #[DataProvider('ownerIndexes')]
    public function testRoundtripPreservesTheExactAssignmentAddress(?string $ownerIndex): void
    {
        $dto = new AccountOperationAskSignalData(9007199254740993, 'parts', 9007199254740995, $ownerIndex);
        $wire = [
            'operationId' => 9007199254740993,
            'stage' => 'parts',
            'assignmentId' => 9007199254740995,
            'ownerIndex' => $ownerIndex,
        ];

        self::assertSame($wire, $dto->toArray());
        self::assertEquals($dto, AccountOperationAskSignalData::fromArray($wire));
        self::assertSame($wire, AccountOperationAskSignalData::fromArray($dto->toArray())->toArray());
    }

    /** @return Generator<string, array{array<string, mixed>}> Missing and mistyped assignment fields */
    public static function malformedPayloads(): Generator
    {
        $valid = ['operationId' => 7, 'stage' => 'parts', 'assignmentId' => 11, 'ownerIndex' => null];
        foreach (['operationId', 'stage', 'assignmentId'] as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield 'missing ' . $field => [$missing];
            yield 'null ' . $field => [array_replace($valid, [$field => null])];
        }
        foreach (['7', 7.5, true, []] as $value) {
            yield 'operation type ' . get_debug_type($value) => [array_replace($valid, ['operationId' => $value])];
            yield 'assignment type ' . get_debug_type($value) => [array_replace($valid, ['assignmentId' => $value])];
        }
        yield 'integer stage' => [array_replace($valid, ['stage' => 3])];
        yield 'array stage' => [array_replace($valid, ['stage' => []])];
        yield 'integer index' => [array_replace($valid, ['ownerIndex' => 42])];
        yield 'array index' => [array_replace($valid, ['ownerIndex' => []])];
    }

    /** @param array<string, mixed> $payload Malformed assignment address */
    #[DataProvider('malformedPayloads')]
    public function testMalformedAssignmentAddressIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountOperationAskSignalData::fromArray($payload);
    }
}
