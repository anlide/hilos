<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureDeliveryCapture;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The portion of an account's deliveries the erasure coordinator hands to a channel owner, written literally and read back (HIL-1940). */
final class AccountErasureDeliveryCapturePayloadTest extends TestCase
{
    private const string FORM_REFUSAL
        = 'An account erasure delivery capture must be {"participant":"delivery:<channel>","notificationIds":[…]}.';

    /** The portion names the census participant of its channel and the notification numbers, and reads both back. */
    public function testThePortionIsWrittenLiterallyAndReadBack(): void
    {
        $payload = AccountErasureDeliveryCapture::payloadOf('email', [3, 17, 140]);

        self::assertSame('{"participant":"delivery:email","notificationIds":[3,17,140]}', $payload->toJson());
        self::assertSame('email', AccountErasureDeliveryCapture::channelOf($payload));
        self::assertSame([3, 17, 140], AccountErasureDeliveryCapture::notificationIdsOf($payload));
    }

    /** A channel that carries the separator itself is kept whole. */
    public function testAChannelWithTheSeparatorIsKeptWhole(): void
    {
        $payload = AccountErasureDeliveryCapture::payloadOf('push:ios', [5]);

        self::assertSame('{"participant":"delivery:push:ios","notificationIds":[5]}', $payload->toJson());
        self::assertSame('push:ios', AccountErasureDeliveryCapture::channelOf($payload));
    }

    /** @return array<string, array{string, list<int>}> Channels and numbers a portion cannot be written with */
    public static function wrongInputs(): array
    {
        return [
            'no numbers' => ['email', []],
            'repeated number' => ['email', [3, 3]],
            'descending numbers' => ['email', [17, 3]],
            'empty channel' => ['', [3]],
        ];
    }

    /**
     * @param string $channel Channel of the portion
     * @param list<int> $notificationIds Its notification numbers
     */
    #[DataProvider('wrongInputs')]
    public function testAPortionOfAWrongFormIsNotWritten(string $channel, array $notificationIds): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(self::FORM_REFUSAL);

        AccountErasureDeliveryCapture::payloadOf($channel, $notificationIds);
    }

    /** @return array<string, array{string}> Saved payloads that are not a delivery portion */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'census payload' => ['{"participant":"delivery:email"}'],
            'keys swapped' => ['{"notificationIds":[3],"participant":"delivery:email"}'],
            'extra key' => ['{"participant":"delivery:email","notificationIds":[3],"addresses":[]}'],
            'other participant' => ['{"participant":"personal","notificationIds":[3]}'],
            'no channel' => ['{"participant":"delivery:","notificationIds":[3]}'],
            'no numbers' => ['{"participant":"delivery:email","notificationIds":[]}'],
            'numbers an object' => ['{"participant":"delivery:email","notificationIds":{"0":3}}'],
            'number a string' => ['{"participant":"delivery:email","notificationIds":["3"]}'],
            'repeated number' => ['{"participant":"delivery:email","notificationIds":[3,3]}'],
            'descending numbers' => ['{"participant":"delivery:email","notificationIds":[17,3]}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testASavedPayloadOfAWrongFormIsRefused(string $json): void
    {
        $payload = AccountOperationAssignmentPayload::fromJson($json);
        foreach ([AccountErasureDeliveryCapture::channelOf(...), AccountErasureDeliveryCapture::notificationIdsOf(...)] as $reader) {
            try {
                $reader($payload);
                self::fail('A wrong form was read');
            } catch (InvalidFormatException $refusal) {
                self::assertSame(self::FORM_REFUSAL, $refusal->getMessage());
            }
        }
    }
}
