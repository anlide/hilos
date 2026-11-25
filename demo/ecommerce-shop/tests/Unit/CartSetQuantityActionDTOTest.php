<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Pages\DTO\CartSetQuantityActionDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartSetQuantityActionDTOTest extends TestCase
{
    private const array INPUT = ['lineId' => 19, 'ownerKind' => 'guest', 'quantity' => '  003  ', 'expectedQuantity' => 2];

    public function testBothCartKindsAndTheOriginalQuantitySurviveARoundTrip(): void
    {
        foreach ([self::INPUT, ['lineId' => 23, 'ownerKind' => 'account', 'quantity' => '1', 'expectedQuantity' => 4_294_967_295]] as $input) {
            $dto = CartSetQuantityActionDTO::fromArray($input);

            self::assertSame('cart_set_quantity', $dto->getAction());
            self::assertSame([], CartSetQuantityActionDTO::SECRET_FIELDS);
            self::assertSame($input, $dto->toArray());
            self::assertSame($input, CartSetQuantityActionDTO::fromArray($this->receiveActionPayload($dto->toArray()))->toArray());
        }
    }

    /** @param array<string, mixed> $input Malformed quantity edit */
    #[DataProvider('invalidInputs')]
    public function testPayloadTypesAndBoundsAreStrictAfterWorkerIpc(array $input): void
    {
        $received = $this->receiveActionPayload($input);

        $this->expectException(InvalidFormatException::class);

        CartSetQuantityActionDTO::fromArray($received);
    }

    /** @return iterable<string, array{array<string, mixed>}> Refused quantity edits */
    public static function invalidInputs(): iterable
    {
        foreach (array_keys(self::INPUT) as $field) {
            $input = self::INPUT;
            unset($input[$field]);
            yield 'missing ' . $field => [$input];
        }
        yield 'zero ID' => [[...self::INPUT, 'lineId' => 0]];
        yield 'negative ID' => [[...self::INPUT, 'lineId' => -1]];
        yield 'string ID' => [[...self::INPUT, 'lineId' => '19']];
        yield 'float ID' => [[...self::INPUT, 'lineId' => 19.0]];
        yield 'boolean ID' => [[...self::INPUT, 'lineId' => true]];
        yield 'unknown owner' => [[...self::INPUT, 'ownerKind' => 'user']];
        yield 'numeric owner' => [[...self::INPUT, 'ownerKind' => 1]];
        yield 'null owner' => [[...self::INPUT, 'ownerKind' => null]];
        yield 'numeric quantity' => [[...self::INPUT, 'quantity' => 3]];
        yield 'float quantity' => [[...self::INPUT, 'quantity' => 3.0]];
        yield 'boolean quantity' => [[...self::INPUT, 'quantity' => true]];
        yield 'zero expected quantity' => [[...self::INPUT, 'expectedQuantity' => 0]];
        yield 'negative expected quantity' => [[...self::INPUT, 'expectedQuantity' => -1]];
        yield 'oversized expected quantity' => [[...self::INPUT, 'expectedQuantity' => 4_294_967_296]];
        yield 'string expected quantity' => [[...self::INPUT, 'expectedQuantity' => '2']];
        yield 'float expected quantity' => [[...self::INPUT, 'expectedQuantity' => 2.0]];
        yield 'boolean expected quantity' => [[...self::INPUT, 'expectedQuantity' => true]];
        yield 'extra owner ID' => [[...self::INPUT, 'userId' => 4]];
    }

    public function testQuantityTextReachesTheCartRulesUnparsed(): void
    {
        foreach (['', '2.5', '1e3', '-1', '9999999999999999999999999'] as $quantity) {
            $dto = CartSetQuantityActionDTO::fromArray([...self::INPUT, 'quantity' => $quantity]);

            self::assertSame($quantity, $dto->quantity);
        }
    }

    /**
     * @param array<string, mixed> $input Browser action payload
     * @return array<string, mixed> Action payload received by the worker
     */
    private function receiveActionPayload(array $input): array
    {
        $action = new WebSocketActionSignalDTO('browser-key', EcommerceShopSignalConstants::CART_SET_QUANTITY, $input);
        $frame = new DaemonAgentMessageDTO('cart_agent', new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName($action->action),
            $action,
        ));

        $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());

        self::assertInstanceOf(DaemonAgentMessageDTO::class, $restored);
        self::assertInstanceOf(WebSocketActionSignalDTO::class, $restored->signal->data);

        return $restored->signal->data->data;
    }
}
