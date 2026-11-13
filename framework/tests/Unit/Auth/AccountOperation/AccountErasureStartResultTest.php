<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureStartResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result the start of an account erasure records, written literally and read back (HIL-1939). */
final class AccountErasureStartResultTest extends TestCase
{
    /** A won start and a lost one are written as exactly one literal object each, and read back. */
    public function testPayloadIsTheLiteralStartObjectAndReadsBack(): void
    {
        $started = AccountErasureStartResult::payloadOf(true);
        $lost = AccountErasureStartResult::payloadOf(false);

        self::assertSame('{"start":"started"}', $started->toJson());
        self::assertSame('{"start":"lost"}', $lost->toJson());
        self::assertTrue(AccountErasureStartResult::startedOf($started));
        self::assertFalse(AccountErasureStartResult::startedOf($lost));
    }

    /** @return array<string, array{string}> Saved payloads that are not an erasure start result */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'unknown word' => ['{"start":"begun"}'],
            'boolean' => ['{"start":true}'],
            'null' => ['{"start":null}'],
            'extra key' => ['{"start":"started","rows":{}}'],
            'other key' => ['{"started":"started"}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountErasureStartResult::startedOf(AccountOperationResultPayload::fromJson($json));
    }
}
