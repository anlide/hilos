<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeSessionsResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountMergeSessionsResultTest extends TestCase
{
    public function testExactCountsRoundTripIncludingZero(): void
    {
        foreach ([[3, 7], [0, 0]] as [$sessions, $log]) {
            $payload = (new AccountMergeSessionsResult($sessions, $log))->toPayload();
            self::assertSame('{"sessions":' . $sessions . ',"accessLog":' . $log . '}', $payload->toJson());
            $saved = AccountMergeSessionsResult::of($payload);
            self::assertSame($sessions, $saved->sessions);
            self::assertSame($log, $saved->accessLog);
        }
    }

    public function testFieldOrderDoesNotChangeTheCounts(): void
    {
        $saved = AccountMergeSessionsResult::of(AccountOperationResultPayload::fromJson('{"accessLog":7,"sessions":3}'));
        self::assertSame('{"sessions":3,"accessLog":7}', $saved->toPayload()->toJson());
    }

    /** @return iterable<string, array{string}> Invalid saved payloads */
    public static function invalidPayloads(): iterable
    {
        foreach (['{}', '{"sessions":1}', '{"sessions":1,"accessLog":0,"secret":"no"}',
            '{"sessions":-1,"accessLog":0}', '{"sessions":0,"accessLog":-1}',
            '{"sessions":true,"accessLog":0}', '{"sessions":0,"accessLog":false}',
            '{"sessions":"1","accessLog":0}', '{"sessions":0,"accessLog":"1"}',
            '{"sessions":1.0,"accessLog":0}', '{"sessions":null,"accessLog":0}'] as $json) {
            yield $json => [$json];
        }
    }

    /** @param string $json Invalid saved result */
    #[DataProvider('invalidPayloads')]
    public function testInvalidSavedCountsRefuse(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeSessionsResult::of(AccountOperationResultPayload::fromJson($json));
    }

    public function testNegativeConstructedCountsCannotBePublished(): void
    {
        $this->expectException(InvalidFormatException::class);
        new AccountMergeSessionsResult(0, -1);
    }
}
