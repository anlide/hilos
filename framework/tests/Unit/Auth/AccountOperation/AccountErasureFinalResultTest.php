<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureFinalResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result the personal final of an account erasure records, written literally and read back (HIL-1942). */
final class AccountErasureFinalResultTest extends TestCase
{
    /** A completed request is written as exactly one literal object and read back. */
    public function testPayloadNamesTheCompletedRequestAndReadsBack(): void
    {
        $payload = AccountErasureFinalResult::payloadOf(42);

        self::assertSame('{"request":42}', $payload->toJson());
        self::assertSame(42, AccountErasureFinalResult::fromPayload($payload)->requestId);
    }

    /** An account with no request to complete is written with an explicit null and read back. */
    public function testPayloadWithoutRequestIsAnExplicitNullAndReadsBack(): void
    {
        $payload = AccountErasureFinalResult::payloadOf(null);

        self::assertSame('{"request":null}', $payload->toJson());
        self::assertNull(AccountErasureFinalResult::fromPayload($payload)->requestId);
    }

    /** @return array<string, array{string}> Saved payloads that are not a personal erasure final */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'string id' => ['{"request":"42"}'],
            'fractional id' => ['{"request":4.2}'],
            'boolean' => ['{"request":true}'],
            'extra key' => ['{"request":42,"rows":{}}'],
            'other key' => ['{"requestId":42}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('An account erasure final must be {"request":<id>|null}.');

        AccountErasureFinalResult::fromPayload(AccountOperationResultPayload::fromJson($json));
    }
}
