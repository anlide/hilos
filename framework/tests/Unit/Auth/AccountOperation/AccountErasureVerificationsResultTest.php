<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureVerificationsResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result the users library's erasure part records, written literally and read back (HIL-1944). */
final class AccountErasureVerificationsResultTest extends TestCase
{
    /** The count of deleted codes is written as exactly one literal object and read back. */
    public function testPayloadIsTheLiteralCountObjectAndReadsBack(): void
    {
        $payload = AccountErasureVerificationsResult::payloadOf(5);

        self::assertSame('{"verifications":5}', $payload->toJson());
        self::assertSame(5, AccountErasureVerificationsResult::deletedOf($payload));
    }

    /** A step with no code to delete still writes its count, as zero. */
    public function testNothingDeletedIsWrittenAsZero(): void
    {
        $payload = AccountErasureVerificationsResult::payloadOf(0);

        self::assertSame('{"verifications":0}', $payload->toJson());
        self::assertSame(0, AccountErasureVerificationsResult::deletedOf($payload));
    }

    /** @return array<string, array{string}> Saved payloads that are not a users library erasure result */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'extra key' => ['{"verifications":5,"addresses":[]}'],
            'other key' => ['{"orders":5}'],
            'fraction' => ['{"verifications":5.0}'],
            'numeric string' => ['{"verifications":"5"}'],
            'null' => ['{"verifications":null}'],
            'negative' => ['{"verifications":-1}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('A users library erasure result must be {"verifications":<int>}.');

        AccountErasureVerificationsResult::deletedOf(AccountOperationResultPayload::fromJson($json));
    }
}
