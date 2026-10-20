<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Testing;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CliCommands;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\OrmForgetRefusedException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Testing\OrmResetTarget;
use Hilos\Testing\OrmTestResetter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Tests orchestration; the real command framing and worker ACK already have transport integration coverage. */
final class OrmTestResetterTest extends TestCase
{
    private ?HilosDbContext $previousDb = null;

    /** Preserve the active facade while using controlled contexts. */
    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
    }

    /** Restore the process even after a refused reset. */
    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
    }

    /** Current process calls the mounted context directly; remote targets never fall back to it. */
    public function testTargetsRunInDeclaredOrderAndClientsAreClosed(): void
    {
        $events = new ResetEvents();
        Hilos::$db = new ResetContext($events);
        $resetter = new ControlledResetter($events);
        $resetter->reset([
            OrmResetTarget::forCurrentProcess(),
            OrmResetTarget::forWorker('node-b', 4302, -7),
            OrmResetTarget::forDaemon('node-a', 4301),
            OrmResetTarget::forWorker('node-a', 4301, 9),
        ]);

        self::assertSame([
            'local', 'open:node-b:4302', 'send:worker:-7', 'close',
            'open:node-a:4301', 'send:daemon', 'close',
            'open:node-a:4301', 'send:worker:9', 'close',
        ], $events->seen);
    }

    /** @return iterable<string, array{array<mixed>}> Invalid lists rejected before any reset */
    public static function invalidLists(): iterable
    {
        yield 'empty' => [[]];
        yield 'invalid element after local' => [[OrmResetTarget::forCurrentProcess(), 'daemon']];
        yield 'not a list' => [[1 => OrmResetTarget::forCurrentProcess()]];
    }

    /** @param array<mixed> $targets Deliberately invalid hook result */
    #[DataProvider('invalidLists')]
    public function testInvalidListRefusesBeforeAnyWork(array $targets): void
    {
        $events = new ResetEvents();
        Hilos::$db = new ResetContext($events);
        try {
            new ControlledResetter($events)->reset($targets);
            self::fail('Invalid target list was accepted');
        } catch (OrmForgetRefusedException) {
            self::assertSame([], $events->seen);
        }
    }

    /** A missing local context is a failed reset, never a no-op. */
    public function testMissingContextFails(): void
    {
        Hilos::$db = null;
        $this->expectException(OrmForgetRefusedException::class);
        $this->expectExceptionMessage('no database context');
        new OrmTestResetter()->reset([OrmResetTarget::forCurrentProcess()]);
    }

    /** @return iterable<string, array{string}> Transport/refusal variants */
    public static function remoteFailures(): iterable
    {
        foreach (['refusal', 'timeout', 'correlation', 'process', 'worker', 'missing-worker', 'start', 'tick', 'consume'] as $mode) {
            yield $mode => [$mode];
        }
    }

    /** @param string $mode Controlled failure in the remote exchange */
    #[DataProvider('remoteFailures')]
    public function testRemoteFailureClosesClientWithoutRetryOrLocalFallback(string $mode): void
    {
        $events = new ResetEvents();
        Hilos::$db = new ResetContext($events);
        try {
            new ControlledResetter($events, $mode)->reset([OrmResetTarget::forWorker('node', 4301, -8)]);
            self::fail('Failed remote exchange was accepted');
        } catch (OrmForgetRefusedException $failure) {
            self::assertNotSame('', $failure->getMessage());
            self::assertSame(['open:node:4301', 'send:worker:-8', 'close'], $events->seen);
        }
    }

    /** Every listed process is attempted, including after a local refusal. */
    public function testFailureDoesNotSuppressLaterExplicitTargets(): void
    {
        $events = new ResetEvents();
        Hilos::$db = new ResetContext($events, true);
        try {
            new ControlledResetter($events)->reset([
                OrmResetTarget::forCurrentProcess(), OrmResetTarget::forDaemon('node', 4301),
            ]);
            self::fail('Local refusal was lost');
        } catch (OrmForgetRefusedException $failure) {
            self::assertStringContainsString('local refusal', $failure->getMessage());
            self::assertSame(['local', 'open:node:4301', 'send:daemon', 'close'], $events->seen);
        }
    }

    /** @return iterable<string, array{string, int, ?int}> Invalid target addresses */
    public static function invalidAddresses(): iterable
    {
        yield 'empty host' => ['', 4301, null];
        yield 'whitespace host' => [' node', 4301, null];
        yield 'zero port' => ['node', 0, null];
        yield 'too high port' => ['node', 65536, null];
        yield 'negative port' => ['node', -1, 4];
        yield 'zero worker' => ['node', 4301, 0];
    }

    /**
     * @param string $host Command host
     * @param int $port Command port
     * @param ?int $workerId Worker target, null for daemon
     */
    #[DataProvider('invalidAddresses')]
    public function testTargetFactoriesRejectInvalidAddresses(string $host, int $port, ?int $workerId): void
    {
        $this->expectException(InvalidArgumentException::class);
        if ($workerId === null) {
            OrmResetTarget::forDaemon($host, $port);
        } else {
            OrmResetTarget::forWorker($host, $port, $workerId);
        }
    }
}

/** Trace private to one unit case, shared by its controlled collaborators. */
final class ResetEvents
{
    /** @var list<string> Recorded calls */
    public array $seen = [];
}

/** Observes the existing local reset door without touching SQL. */
final class ResetContext extends HilosDbContext
{
    /**
     * @param ResetEvents $events Case trace
     * @param bool $refuse Whether to simulate the existing admission refusal
     */
    public function __construct(private readonly ResetEvents $events, private readonly bool $refuse = false)
    {
    }

    /** No stores are needed for the selection test. */
    public function configure(): void
    {
    }

    /** Records the selected local door. */
    public function forgetLoadedDataForTest(): void
    {
        $this->events->seen[] = 'local';
        if ($this->refuse) {
            throw new OrmForgetRefusedException('local refusal');
        }
    }
}

/** Controls the clock and client, leaving all reset orchestration in the real implementation. */
final class ControlledResetter extends OrmTestResetter
{
    private float $clock = 0.0;

    /**
     * @param ResetEvents $events Case trace
     * @param string $mode Exchange outcome
     */
    public function __construct(private readonly ResetEvents $events, private readonly string $mode = 'ok')
    {
    }

    /**
     * @param string $host Selected host
     * @param int $port Selected port
     * @return AsyncCommandClient Controlled exchange
     */
    protected function createClient(string $host, int $port): AsyncCommandClient
    {
        $this->events->seen[] = "open:{$host}:{$port}";

        return new ResetCommandClient($this->events, $this->mode);
    }

    /** @return float Advancing monotonic seconds */
    protected function nowSeconds(): float
    {
        return $this->clock++;
    }

    /** The controlled clock needs no real sleep. */
    protected function waitForPoll(): void
    {
    }
}

/** Controlled existing client API, not a replacement command protocol. */
final class ResetCommandClient extends AsyncCommandClient
{
    private CommandRequestDTO $request;
    private bool $ready = false;

    /**
     * @param ResetEvents $events Case trace
     * @param string $mode Exchange outcome
     */
    public function __construct(private readonly ResetEvents $events, private readonly string $mode)
    {
    }

    /** @param CommandRequestDTO $request Actual request built by the resetter */
    public function startRequest(CommandRequestDTO $request): void
    {
        TestCase::assertSame(CliCommands::ORM_TEST_FORGET, $request->command);
        TestCase::assertNull($request->originNodeId);
        TestCase::assertSame(
            $request->payload['process'] === 'daemon' ? ['process'] : ['process', 'workerId'],
            array_keys($request->payload),
        );
        $this->request = $request;
        $description = 'send:' . $request->payload['process'];
        if (isset($request->payload['workerId'])) {
            $description .= ':' . $request->payload['workerId'];
        }
        $this->events->seen[] = $description;
        if ($this->mode === 'start') {
            throw new RuntimeException('connect failure');
        }
    }

    /** Advances one controlled exchange. */
    public function tick(): void
    {
        if ($this->mode === 'tick') {
            throw new RuntimeException('read failure');
        }
        $this->ready = $this->mode !== 'timeout';
    }

    /** @return bool Whether the controlled reply is ready */
    public function hasResult(): bool
    {
        return $this->ready;
    }

    /** @return CommandReplyDTO Success, refusal or deliberately mismatched reply */
    public function consumeResult(): CommandReplyDTO
    {
        if ($this->mode === 'consume') {
            throw new RuntimeException('consume failure');
        }
        if ($this->mode === 'refusal') {
            return CommandReplyDTO::error($this->request->correlationId, 'remote refusal');
        }
        $payload = [
            'process' => $this->mode === 'process' ? 'wrong' : $this->request->payload['process'],
            'workerId' => $this->mode === 'worker' ? 9 : ($this->request->payload['workerId'] ?? null),
        ];
        if ($this->mode === 'missing-worker') {
            unset($payload['workerId']);
        }

        return CommandReplyDTO::ok($this->mode === 'correlation' ? 'wrong' : $this->request->correlationId, $payload);
    }

    /** Records closing even when start, polling or parsing fails. */
    public function reset(): void
    {
        $this->events->seen[] = 'close';
    }
}
