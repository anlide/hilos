<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal\Export;

use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Legal\Export\LegalAcceptancesExportErasureResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result the legal agent's part of an account erasure records, written literally and read back (HIL-1947). */
final class LegalAcceptancesExportErasureResultTest extends TestCase
{
    /** The counts are written as exactly one literal object, own orders first, and read back. */
    public function testPayloadIsTheLiteralCountsObjectAndReadsBack(): void
    {
        $counted = LegalAcceptancesExportErasureResult::payloadOf(1, 2);
        $none = LegalAcceptancesExportErasureResult::payloadOf(0, 0);

        self::assertSame('{"ownOrders":1,"sharedExports":2}', $counted->toJson());
        self::assertSame('{"ownOrders":0,"sharedExports":0}', $none->toJson());
        self::assertSame(['ownOrders' => 1, 'sharedExports' => 2], LegalAcceptancesExportErasureResult::countsOf($counted));
        self::assertSame(['ownOrders' => 0, 'sharedExports' => 0], LegalAcceptancesExportErasureResult::countsOf($none));
    }

    /** @return array<string, array{string}> Saved payloads that are not a legal export erasure result */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'extra key' => ['{"ownOrders":1,"sharedExports":2,"files":3}'],
            'missing key' => ['{"ownOrders":1}'],
            'other key' => ['{"ownOrders":1,"exports":2}'],
            'not an integer' => ['{"ownOrders":1,"sharedExports":2.5}'],
            'a string' => ['{"ownOrders":"1","sharedExports":2}'],
            'null' => ['{"ownOrders":null,"sharedExports":2}'],
            'negative own orders' => ['{"ownOrders":-1,"sharedExports":2}'],
            'negative shared exports' => ['{"ownOrders":1,"sharedExports":-2}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);

        LegalAcceptancesExportErasureResult::countsOf(AccountOperationResultPayload::fromJson($json));
    }
}
