<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartStateSignalDataTest extends TestCase
{
    /** @param ?int $voucherId Saved voucher ID or an explicitly empty slot */
    #[DataProvider('voucherIds')]
    public function testRoundtripPreservesTheWholeCart(?int $voucherId): void
    {
        $lines = [['productId' => 12, 'quantity' => 2], ['productId' => 7, 'quantity' => 1]];
        $payload = (new CartStateSignalData(5, $lines, $voucherId))->toArray();

        self::assertSame(['userId' => 5, 'lines' => $lines, 'voucherId' => $voucherId], $payload);
        $restored = CartStateSignalData::fromArray($payload);
        self::assertSame($voucherId, $restored->voucherId);
        self::assertSame($payload, $restored->toArray());
    }

    /** @return iterable<string, array{?int}> Valid saved voucher fields */
    public static function voucherIds(): iterable
    {
        yield 'selected voucher' => [23];
        yield 'no voucher' => [null];
    }

    /** @param array{voucherId?: mixed} $voucherField Missing or invalid voucher field */
    #[DataProvider('invalidVoucherFields')]
    public function testRejectsAMissingOrInvalidVoucherId(array $voucherField): void
    {
        $this->expectException(InvalidFormatException::class);

        CartStateSignalData::fromArray(['userId' => null, 'lines' => []] + $voucherField);
    }

    /** @return iterable<string, array{array{voucherId?: mixed}}> Invalid voucher fields */
    public static function invalidVoucherFields(): iterable
    {
        yield 'missing' => [[]];
        yield 'zero' => [['voucherId' => 0]];
        yield 'negative' => [['voucherId' => -1]];
        yield 'fraction' => [['voucherId' => 1.5]];
        yield 'string' => [['voucherId' => '23']];
        yield 'boolean' => [['voucherId' => true]];
        yield 'imprecise on the wire' => [['voucherId' => 9_007_199_254_740_992]];
    }
}
