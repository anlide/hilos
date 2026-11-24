<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Hilos\Constants\SignalPayloadConstants;
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

/** A cart step carries the exact saved row identity and a one-unit direction. */
final class CartStepQuantityActionDTOTest extends TestCase
{
    private const array INPUT = ['lineId' => 19, 'ownerKind' => 'guest', 'step' => 1];

    public function testBothCartKindsAndDirectionsSurviveARoundTrip(): void
    {
        foreach ([self::INPUT, ['lineId' => 23, 'ownerKind' => 'account', 'step' => -1]] as $input) {
            $dto = CartStepQuantityActionDTO::fromArray($input);
            self::assertSame('cart_step_quantity', $dto->getAction());
            self::assertSame([], CartStepQuantityActionDTO::SECRET_FIELDS);
            self::assertSame($input, $dto->toArray());
            self::assertEquals($dto, CartStepQuantityActionDTO::fromArray($dto->toArray()));
            self::assertSame($input, CartStepQuantityActionDTO::fromArray($this->receiveActionPayload($input))->toArray());
        }
    }

    /** @param array<string, mixed> $input Malformed cart step */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidPayloadIsRefused(array $input): void
    {
        $this->expectException(InvalidFormatException::class);

        CartStepQuantityActionDTO::fromArray($input);
    }

    /** @param array<string, mixed> $input Malformed cart step */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidPayloadIsRefusedAfterWorkerIpc(array $input): void
    {
        $received = $this->receiveActionPayload($input);

        $this->expectException(InvalidFormatException::class);

        CartStepQuantityActionDTO::fromArray($received);
    }

    /** @return iterable<string, array{array<string, mixed>}> Refused cart steps */
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
        yield 'no movement' => [[...self::INPUT, 'step' => 0]];
        yield 'two units up' => [[...self::INPUT, 'step' => 2]];
        yield 'two units down' => [[...self::INPUT, 'step' => -2]];
        yield 'string step' => [[...self::INPUT, 'step' => '-1']];
        yield 'float step' => [[...self::INPUT, 'step' => 1.0]];
        yield 'boolean step' => [[...self::INPUT, 'step' => true]];
        yield 'extra quantity' => [[...self::INPUT, 'quantity' => 3]];
        yield 'nested payload' => [[SignalPayloadConstants::FIELD_DATA => self::INPUT]];
        yield 'nested payload with an extra field' => [[SignalPayloadConstants::FIELD_DATA => self::INPUT, 'quantity' => 3]];
        yield 'twice nested payload' => [[SignalPayloadConstants::FIELD_DATA => [SignalPayloadConstants::FIELD_DATA => self::INPUT]]];
        yield 'invalid wrapper' => [[SignalPayloadConstants::FIELD_DATA => null, ...self::INPUT]];
    }

    /**
     * @param array<string, mixed> $input Browser action payload
     * @return array<string, mixed> Action payload received by the worker
     */
    private function receiveActionPayload(array $input): array
    {
        $action = new WebSocketActionSignalDTO('browser-key', EcommerceShopSignalConstants::CART_STEP_QUANTITY, $input);
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
