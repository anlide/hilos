<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Clock\ClusterClock;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Tests the master answering test:cluster:clock:shift itself.
 *
 * The clock shift is master-local state: valid requests change and read it back, while refused
 * payloads leave it alone. No database or runtime row carries the value.
 */
final class CommandClientClockShiftTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    private string|false $previousAppEnv = false;

    private string|false $previousThreshold = false;

    /** @var list<Socket> Sockets kept alive for the client under test */
    private array $sockets = [];

    /** Prepares an admitted test environment with a registered master-local clock. */
    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousThreshold = getenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS');
        putenv('APP_ENV=test');
        putenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS=300');
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        Hilos::$env = new EnvAccessor();

        $context = new ClusterContext();
        $context->registerClock(new ClusterClock(static fn (): array => []));
        Hilos::$cluster = $context;
    }

    /** Restores process state and closes the test sockets. */
    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
        $this->previousThreshold === false
            ? putenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS')
            : putenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS=' . $this->previousThreshold);
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    /** @return array<string, array{int}> Shifts accepted at both ends of the one-day window */
    public static function boundaryShifts(): array
    {
        return [
            'positive one day' => [86_400_000],
            'negative one day' => [-86_400_000],
        ];
    }

    /**
     * @param int $shiftMs Signed shift applied to the clock
     */
    #[DataProvider('boundaryShifts')]
    public function testBoundaryShiftsAreAcceptedAndReadBack(int $shiftMs): void
    {
        $reply = $this->ask([CommandConstants::FIELD_SHIFT_MS => $shiftMs]);

        self::assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        self::assertSame([CommandConstants::FIELD_SHIFT_MS => $shiftMs], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        self::assertSame($shiftMs, Hilos::$cluster?->clock()?->testShiftMs());
    }

    /** Confirms both shift directions are stored on and returned by the master clock. */
    public function testPositiveAndNegativeShiftsAreHeldByTheMasterClock(): void
    {
        foreach ([1200, -1200] as $shiftMs) {
            $reply = $this->ask([CommandConstants::FIELD_SHIFT_MS => $shiftMs]);

            self::assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
            self::assertSame([CommandConstants::FIELD_SHIFT_MS => $shiftMs], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
            self::assertSame($shiftMs, Hilos::$cluster?->clock()?->testShiftMs());
        }
    }

    /** Confirms an omitted payload field restores the clock's default answer shift. */
    public function testOmittingTheShiftClearsIt(): void
    {
        Hilos::$cluster?->clock()?->setTestShift(500);

        $reply = $this->ask([]);

        self::assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        self::assertSame([CommandConstants::FIELD_SHIFT_MS => 0], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        self::assertSame(0, Hilos::$cluster?->clock()?->testShiftMs());
    }

    /** @return array<string, array{mixed}> Values rejected by the master's wire boundary */
    public static function invalidShifts(): array
    {
        return [
            'fraction' => [1.5],
            'numeric string' => ['100'],
            'boolean' => [true],
            'null' => [null],
            'array' => [[]],
            'above one day' => [86_400_001],
            'below negative one day' => [-86_400_001],
        ];
    }

    /** @param mixed $shiftMs Payload value that must be refused */
    #[DataProvider('invalidShifts')]
    public function testAnInvalidShiftIsRefusedWithoutChangingTheClock(mixed $shiftMs): void
    {
        Hilos::$cluster?->clock()?->setTestShift(700);

        $reply = $this->ask([CommandConstants::FIELD_SHIFT_MS => $shiftMs]);

        self::assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        self::assertSame(
            'shiftMs must be a whole number of milliseconds within one day',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
        self::assertSame(700, Hilos::$cluster?->clock()?->testShiftMs());
    }

    /** Confirms a node without a registered cluster clock refuses the command. */
    public function testOutsideAClusterTheCommandIsRefused(): void
    {
        Hilos::$cluster = null;

        $reply = $this->ask([CommandConstants::FIELD_SHIFT_MS => 100]);

        self::assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        self::assertSame(
            'This node is not in a cluster: there is no clock check to shift',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * Sends one test:cluster:clock:shift request to a fresh command client and reads its reply.
     *
     * @param array<string, mixed> $payload Request payload
     * @return array<string, mixed> Decoded reply
     */
    private function ask(array $payload): array
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        $client = new ClockShiftTestCommandClient($pair[0], new CommandServer('127.0.0.1', 0));
        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-clock-shift',
            CommandConstants::FIELD_COMMAND => CliCommands::CLUSTER_TEST_CLOCK_SHIFT,
            CommandConstants::FIELD_PAYLOAD => $payload,
        ]);

        return $client->lastReply();
    }
}

/** Command client that takes a request straight from the test instead of the socket. */
final class ClockShiftTestCommandClient extends CommandClient
{
    /**
     * Hands one request to the read-buffer parser as the CLI would send it.
     *
     * @param array<string, mixed> $request Request map
     */
    public function feed(array $request): void
    {
        $this->readBuffer .= json_encode($request) . "\n";
        $this->processReadBuffer();
    }

    /**
     * Reads back the reply the parser queued for the CLI.
     *
     * @return array<string, mixed> Decoded reply, or an empty array when none was queued
     */
    public function lastReply(): array
    {
        $lines = array_filter(explode("\n", $this->writeBuffer), static fn(string $line): bool => $line !== '');
        $reply = JsonHelper::tryDecode((string) end($lines));

        return is_array($reply) ? $reply : [];
    }
}
