<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureDeliveryResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The delivery participant's exact result payload and its strict reading boundary. */
final class AccountErasureDeliveryResultTest extends TestCase
{
    /** The deleted count is written literally and read back. */
    public function testPayloadIsTheLiteralCountAndReadsBack(): void
    {
        $payload = AccountErasureDeliveryResult::payloadOf(3);

        self::assertSame('{"deliveries":3}', $payload->toJson());
        self::assertSame(3, AccountErasureDeliveryResult::deliveriesOf($payload));
    }

    /** A participant without deliveries still records zero. */
    public function testNothingDeletedIsWrittenAsZero(): void
    {
        $payload = AccountErasureDeliveryResult::payloadOf(0);

        self::assertSame('{"deliveries":0}', $payload->toJson());
        self::assertSame(0, AccountErasureDeliveryResult::deliveriesOf($payload));
    }

    /** @return array<string, array{string}> Payloads outside the exact result shape */
    public static function wrongForms(): array
    {
        return [
            'missing key' => ['{}'],
            'extra key' => ['{"deliveries":3,"orders":0}'],
            'other key' => ['{"delivery":3}'],
            'fraction' => ['{"deliveries":3.0}'],
            'numeric string' => ['{"deliveries":"3"}'],
            'null' => ['{"deliveries":null}'],
            'boolean' => ['{"deliveries":true}'],
            'negative' => ['{"deliveries":-1}'],
        ];
    }

    /** @param string $json Saved payload outside the result shape */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('An account erasure delivery result must be {"deliveries":<int>}.');

        AccountErasureDeliveryResult::deliveriesOf(AccountOperationResultPayload::fromJson($json));
    }
}
