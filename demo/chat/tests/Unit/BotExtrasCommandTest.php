<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\CLI\DTO\BotExtrasCreateCommandData;
use Demo\Chat\CLI\DTO\BotExtrasDeleteCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Strict command payloads and refusals that must happen before any database access. */
final class BotExtrasCommandTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> Valid test-only requests */
    public static function commands(): iterable
    {
        yield 'create' => [ChatCommandConstants::BOT_TEST_EXTRAS_CREATE, ['marker' => 'extras01', 'count' => 3]];
        yield 'delete' => [ChatCommandConstants::BOT_TEST_EXTRAS_DELETE, ['marker' => 'extras01']];
    }

    /**
     * @param string $command Command refused by the environment gate
     * @param array<string, mixed> $payload Valid command fields
     */
    #[DataProvider('commands')]
    public function testClosedGateRefusesWithoutReadingBots(string $command, array $payload): void
    {
        $env = Hilos::$env;
        $this->withRouter(function () use ($command, $payload, $env): void {
            Hilos::$env = null;
            try {
                $replies = $this->dispatch(new CommandRequestDTO('extras', $command, $payload));
            } finally {
                Hilos::$env = $env;
            }
            $this->assertSame([['extras', false, 'Bot extras are test-only']], $replies);
        });
    }

    /** The library answers an unowned command without reaching the database. */
    public function testUnknownCommandIsRefusedByName(): void
    {
        $this->withRouter(function (): void {
            $this->assertSame(
                [['unknown', false, 'Unknown command: test:chat:unknown']],
                $this->dispatch(new CommandRequestDTO('unknown', 'test:chat:unknown')),
            );
        });
    }

    /**
     * @return iterable<string, array{string, class-string<BotExtrasCreateCommandData|BotExtrasDeleteCommandData>, array<string, mixed>}>
     *     Invalid marker or count fields
     */
    public static function malformed(): iterable
    {
        foreach ([
            ChatCommandConstants::BOT_TEST_EXTRAS_CREATE => BotExtrasCreateCommandData::class,
            ChatCommandConstants::BOT_TEST_EXTRAS_DELETE => BotExtrasDeleteCommandData::class,
        ] as $command => $dtoClass) {
            yield $command . ' empty payload' => [$command, $dtoClass, []];
            yield $command . ' missing marker' => [$command, $dtoClass, ['count' => 1]];
            foreach ([
                '', 'short01', str_repeat('a', 33), 'Uppercase1', 'extras-01', 'extras 01', 'extras/01', "extras01\n",
                'тестовый1', 12345678, null, true, 12345678.0, ['extras01'],
            ] as $index => $marker) {
                yield $command . ' marker ' . $index => [$command, $dtoClass, ['marker' => $marker, 'count' => 1]];
            }
        }
        yield 'create missing count' => [
            ChatCommandConstants::BOT_TEST_EXTRAS_CREATE, BotExtrasCreateCommandData::class, ['marker' => 'extras01'],
        ];
        foreach ([0, -1, 21, '1', null, true, 1.0, [1]] as $index => $count) {
            yield 'create count ' . $index => [
                ChatCommandConstants::BOT_TEST_EXTRAS_CREATE, BotExtrasCreateCommandData::class, ['marker' => 'extras01', 'count' => $count],
            ];
        }
    }

    /**
     * @param string $command Command whose DTO rejects the fields
     * @param class-string<BotExtrasCreateCommandData|BotExtrasDeleteCommandData> $dtoClass Payload reader
     * @param array<string, mixed> $payload Invalid command fields
     */
    #[DataProvider('malformed')]
    public function testDtoRejectsMalformedPayload(string $command, string $dtoClass, array $payload): void
    {
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly($command));
        $this->expectException(InvalidFormatException::class);
        $dtoClass::fromArray($payload);
    }

    /**
     * @param string $command Command whose generic hydration rejects the fields
     * @param class-string<BotExtrasCreateCommandData|BotExtrasDeleteCommandData> $dtoClass Expected payload reader
     * @param array<string, mixed> $payload Invalid command fields
     */
    #[DataProvider('malformed')]
    public function testGenericHydrationRejectsMalformedPayload(string $command, string $dtoClass, array $payload): void
    {
        $this->assertSame($dtoClass, Hilos::getCommandDtoRoutes()[$command]);
        $this->expectException(InvalidCommandPayloadException::class);
        new ChatSignalRouter()->createCommandPayloadDTO($command, new CommandRequestDTO('bad', $command, $payload));
    }

    /** @return iterable<string, array{string, int}> Inclusive marker and count boundaries */
    public static function boundaries(): iterable
    {
        yield 'minimum' => ['abcd0123', 1];
        yield 'maximum' => [str_repeat('z9', 16), 20];
    }

    /**
     * @param string $marker Valid marker at a length boundary
     * @param int $count Valid batch size at a boundary
     */
    #[DataProvider('boundaries')]
    public function testPayloadRoundtripsAndHydratesAtTheBoundaries(string $marker, int $count): void
    {
        $create = new BotExtrasCreateCommandData($marker, $count);
        $delete = new BotExtrasDeleteCommandData($marker);
        $this->assertSame(['marker' => $marker, 'count' => $count], BotExtrasCreateCommandData::fromJson($create->toJson())->toArray());
        $this->assertSame(['marker' => $marker], BotExtrasDeleteCommandData::fromJson($delete->toJson())->toArray());
        foreach ([
            ChatCommandConstants::BOT_TEST_EXTRAS_CREATE => $create,
            ChatCommandConstants::BOT_TEST_EXTRAS_DELETE => $delete,
        ] as $command => $dto) {
            $this->assertTrue(TestOnlyCommandRegistry::isTestOnly($command));
            $hydrated = new ChatSignalRouter()->createCommandPayloadDTO($command, new CommandRequestDTO('valid', $command, $dto->toArray()));
            $this->assertInstanceOf($dto::class, $hydrated->parsedPayload);
            $this->assertSame($dto->toArray(), $hydrated->parsedPayload->toArray());
        }
    }

    /** @param callable(): void $assertions Assertions over an isolated signal queue */
    private function withRouter(callable $assertions): void
    {
        $sr = Hilos::$sr;
        try {
            Hilos::$sr = new ChatSignalRouter();
            $assertions();
        } finally {
            Hilos::$sr = $sr;
        }
    }

    /**
     * @param CommandRequestDTO $request Command as the channel delivers it
     * @return list<array{string, bool, string}> Correlation id, outcome and text of each reply
     */
    private function dispatch(CommandRequestDTO $request): array
    {
        new LibraryAgent()->onSignalCommand(Hilos::$sr->createCommandPayloadDTO($request->command, $request), 'command', $request->command);
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(CommandReplyDTO::class, $signal->data, 'A refusal queues its reply and nothing else');
            $replies[] = [$signal->data->correlationId, $signal->data->isOk(), $signal->data->payload[CommandConstants::FIELD_MESSAGE]];
        }

        return $replies;
    }
}
