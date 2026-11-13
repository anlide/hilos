<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureSessionsResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result the session holder's erasure part records, written literally and read back (HIL-1943). */
final class AccountErasureSessionsResultTest extends TestCase
{
    /** Two counts are written as exactly one literal object, sessions first, and read back. */
    public function testPayloadIsTheLiteralCountsObjectAndReadsBack(): void
    {
        $payload = (new AccountErasureSessionsResult(3, 7))->payload();

        self::assertSame('{"sessions":3,"accessLog":7}', $payload->toJson());
        $result = AccountErasureSessionsResult::fromPayload($payload);
        self::assertSame(3, $result->sessions);
        self::assertSame(7, $result->accessLog);
    }

    /** A holder with nothing to release still writes both counts, as zeros. */
    public function testNothingReleasedIsWrittenAsTwoZeros(): void
    {
        $payload = (new AccountErasureSessionsResult(0, 0))->payload();

        self::assertSame('{"sessions":0,"accessLog":0}', $payload->toJson());
        $result = AccountErasureSessionsResult::fromPayload($payload);
        self::assertSame(0, $result->sessions);
        self::assertSame(0, $result->accessLog);
    }

    /** @return array<string, array{string}> Saved payloads that are not a session holder erasure result */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'extra key' => ['{"sessions":3,"accessLog":7,"files":[]}'],
            'missing key' => ['{"sessions":3}'],
            'other key' => ['{"sessions":3,"accessLogs":7}'],
            'fraction' => ['{"sessions":3.0,"accessLog":7}'],
            'numeric string' => ['{"sessions":3,"accessLog":"7"}'],
            'null' => ['{"sessions":null,"accessLog":7}'],
            'negative' => ['{"sessions":3,"accessLog":-1}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountErasureSessionsResult::fromPayload(AccountOperationResultPayload::fromJson($json));
    }
}
