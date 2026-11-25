<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartVoucherRemoveActionDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartVoucherRemoveActionDTOTest extends TestCase
{
    public function testRawAndWrappedPayloadsPreserveTheDisplayedVoucherIdAndOwnerKind(): void
    {
        foreach ([
            ['expectedVoucherId' => 1, 'ownerKind' => 'guest'],
            ['expectedVoucherId' => 9_007_199_254_740_991, 'ownerKind' => 'account'],
        ] as $payload) {
            $dto = CartVoucherRemoveActionDTO::fromArray($payload + ['sessionId' => 17, 'userId' => 18]);
            self::assertSame('cart_remove_voucher', $dto->getAction());
            self::assertSame([], CartVoucherRemoveActionDTO::SECRET_FIELDS);
            self::assertSame($payload, $dto->toArray());
            self::assertSame($payload, CartVoucherRemoveActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => $dto->toArray()])->toArray());
        }
    }

    /** @param array<string, mixed> $payload Malformed voucher removal payload */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidPayloadIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        CartVoucherRemoveActionDTO::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Refused voucher removal payloads */
    public static function invalidInputs(): iterable
    {
        $guest = ['ownerKind' => CartStepQuantityActionDTO::GUEST];
        yield 'missing ID' => [$guest];
        yield 'missing owner kind' => [['expectedVoucherId' => 1]];
        yield 'zero ID' => [['expectedVoucherId' => 0] + $guest];
        yield 'negative ID' => [['expectedVoucherId' => -1] + $guest];
        yield 'unsafe integer ID' => [['expectedVoucherId' => 9_007_199_254_740_992] + $guest];
        yield 'numeric string ID' => [['expectedVoucherId' => '1'] + $guest];
        yield 'floating point ID' => [['expectedVoucherId' => 1.0] + $guest];
        yield 'boolean ID' => [['expectedVoucherId' => true] + $guest];
        yield 'null ID' => [['expectedVoucherId' => null] + $guest];
        yield 'numeric owner kind' => [['expectedVoucherId' => 1, 'ownerKind' => 1]];
        yield 'unknown owner kind' => [['expectedVoucherId' => 1, 'ownerKind' => 'admin']];
        yield 'invalid wrapper' => [[SignalPayloadConstants::FIELD_DATA => 1]];
    }
}
