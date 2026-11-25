<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Pages\DTO\CartVoucherApplyActionDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The action carries the original code and displayed owner kind. */
final class CartVoucherApplyActionDTOTest extends TestCase
{
    public function testRawAndWrappedPayloadsPreserveTheEnteredCode(): void
    {
        foreach (['guest', 'account'] as $ownerKind) {
            $input = ['code' => '  SpRiNg25  ', 'ownerKind' => $ownerKind];
            $dto = CartVoucherApplyActionDTO::fromArray($input);
            self::assertSame('cart_apply_voucher', $dto->getAction());
            self::assertSame([], CartVoucherApplyActionDTO::SECRET_FIELDS);
            self::assertSame($input, $dto->toArray());
            self::assertEquals($dto, CartVoucherApplyActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => $dto->toArray()]));
            self::assertSame($input, CartVoucherApplyActionDTO::fromArray($input + ['sessionId' => 17, 'userId' => 18, 'voucherId' => 19])
                ->toArray());
        }
    }

    /** @param array<string, mixed> $input Malformed voucher application payload */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidPayloadIsRefused(array $input): void
    {
        $this->expectException(InvalidFormatException::class);

        CartVoucherApplyActionDTO::fromArray($input);
    }

    /** @return iterable<string, array{array<string, mixed>}> Refused voucher application payloads */
    public static function invalidInputs(): iterable
    {
        yield 'missing code' => [['ownerKind' => 'guest']];
        yield 'missing owner kind' => [['code' => 'SPRING25']];
        yield 'numeric code' => [['code' => 25, 'ownerKind' => 'guest']];
        yield 'null code' => [['code' => null, 'ownerKind' => 'guest']];
        yield 'numeric owner kind' => [['code' => 'SPRING25', 'ownerKind' => 1]];
        yield 'unknown owner kind' => [['code' => 'SPRING25', 'ownerKind' => 'admin']];
        yield 'invalid wrapper' => [[SignalPayloadConstants::FIELD_DATA => 'SPRING25']];
    }
}
