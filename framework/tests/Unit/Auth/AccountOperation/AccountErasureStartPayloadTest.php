<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureStartPayload;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The saved start mode is strict, immutable and independent of JSON key order. */
final class AccountErasureStartPayloadTest extends TestCase
{
    public function testBothModesRoundTripWithoutChangingThePayload(): void
    {
        foreach ([true, false] as $expireGrace) {
            $json = $expireGrace ? '{"participant":"personal","expireGrace":true}' : '{"participant":"personal","expireGrace":false}';
            $payload = AccountErasureStartPayload::payloadOf($expireGrace);

            self::assertSame($json, $payload->toJson());
            self::assertSame($expireGrace, AccountErasureStartPayload::expireGraceOf($payload));
            self::assertSame($json, $payload->toJson());
        }
    }

    /** @return array<string, array{string}> Objects that do not carry exactly a personal start mode */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'old participant only' => ['{"participant":"personal"}'],
            'missing participant' => ['{"expireGrace":true}'],
            'foreign participant' => ['{"participant":"users_library","expireGrace":true}'],
            'null participant' => ['{"participant":null,"expireGrace":false}'],
            'numeric participant' => ['{"participant":1,"expireGrace":false}'],
            'null mode' => ['{"participant":"personal","expireGrace":null}'],
            'numeric mode' => ['{"participant":"personal","expireGrace":1}'],
            'string mode' => ['{"participant":"personal","expireGrace":"false"}'],
            'array mode' => ['{"participant":"personal","expireGrace":[]}'],
            'object mode' => ['{"participant":"personal","expireGrace":{}}'],
            'extra key' => ['{"participant":"personal","expireGrace":true,"rows":{}}'],
        ];
    }

    /** @param string $json Invalid saved start intention */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountErasureStartPayload::expireGraceOf(AccountOperationAssignmentPayload::fromJson($json));
    }

    public function testEitherJsonKeyOrderIsAccepted(): void
    {
        self::assertTrue(AccountErasureStartPayload::expireGraceOf(
            AccountOperationAssignmentPayload::fromJson('{"expireGrace":true,"participant":"personal"}'),
        ));
        self::assertFalse(AccountErasureStartPayload::expireGraceOf(
            AccountOperationAssignmentPayload::fromJson('{"expireGrace":false,"participant":"personal"}'),
        ));
    }
}
