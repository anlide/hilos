<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * What the server tick does with a client that fails (HIL-569).
 *
 * The master reads one client through two paths - the epoll callback and this tick -
 * and until now only the first of them contained a failure. A line that did not
 * decode, a frame with an opcode nobody knows or an HTTP request at a WebSocket port
 * left the tick uncaught and took the whole master process with it; which of the two
 * paths saw the bytes first was a race.
 *
 * The guard closes that: whatever a client throws ends as a closed connection and a
 * line in the journal, and the level tells the routine from the alarming by the
 * marker the failure carries. The one exception is a failure that is the node's own -
 * the refusal of the secure random source - which passes through untouched, because
 * the decision to stop belongs to the manager and not to a server (HIL-568).
 */
final class AbstractServerTickGuardTest extends TestCase
{
    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-tick-guard-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(Logger::class);
        $reflection->getProperty('logFile')->setValue(null, null);

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testInputThatCouldNotBeParsedClosesTheClientAndLeavesTheServerTicking(): void
    {
        $server = new AbstractServerTickGuardTestServer();
        $client = $server->seedClient(new InvalidJsonException('Payload does not decode as JSON: Syntax error'));

        $server->onTick();

        $this->assertTrue($client->closed);
        $this->assertSame([], $server->getClients());
    }

    public function testInputThatCouldNotBeParsedIsWrittenAsTheRoutineEventItIs(): void
    {
        $server = new AbstractServerTickGuardTestServer();
        $server->seedClient(new InvalidJsonException('Payload does not decode as JSON: Syntax error'));

        $server->onTick();

        $logged = $this->logged();
        $this->assertStringContainsString('WARNING:', $logged);
        $this->assertStringNotContainsString('ERROR:', $logged);
        $this->assertStringContainsString('Syntax error', $logged);
    }

    public function testAFailureCarryingNoMarkerIsWrittenAsAnErrorAndStillDropsTheClient(): void
    {
        $server = new AbstractServerTickGuardTestServer();
        $client = $server->seedClient(new RuntimeException('worker table is out of shape'));

        $server->onTick();

        $logged = $this->logged();
        $this->assertStringContainsString('ERROR:', $logged);
        $this->assertStringContainsString('worker table is out of shape', $logged);
        $this->assertTrue($client->closed);
        $this->assertSame([], $server->getClients());
    }

    public function testABrokenTransportIsWrittenAsTheRoutineEventItIs(): void
    {
        $server = new AbstractServerTickGuardTestServer();
        $client = $server->seedClient(new SocketException('Connection reset by peer'));

        $server->onTick();

        $logged = $this->logged();
        $this->assertStringContainsString('WARNING:', $logged);
        $this->assertStringNotContainsString('ERROR:', $logged);
        $this->assertStringContainsString('Connection reset by peer', $logged);
        $this->assertTrue($client->closed);
        $this->assertSame([], $server->getClients());
    }

    /**
     * The regression HIL-568 closed: a catch-all that also swallowed this refusal
     * would leave the node minting handshake secrets it cannot make unguessable.
     */
    public function testTheRefusalOfTheSecureRandomSourcePassesTheGuardUntouched(): void
    {
        $server = new AbstractServerTickGuardTestServer();
        $client = $server->seedClient(new RandomException('source of randomness unavailable'));

        try {
            $server->onTick();
            $this->fail('The tick must not contain a refusal that belongs to the node.');
        } catch (RandomException $exception) {
            $this->assertSame('source of randomness unavailable', $exception->getMessage());
        }

        $this->assertFalse($client->closed);
        $this->assertSame('', $this->logged());
    }

    public function testTheLineNamesTheServerTheClassAndWhereItCameFrom(): void
    {
        $server = new AbstractServerTickGuardTestServer();
        $server->seedClient(new InvalidJsonException('Payload does not decode as JSON: Syntax error'));

        $server->onTick();

        $logged = $this->logged();
        $this->assertStringContainsString('Error in client tick for tick-guard-test', $logged);
        $this->assertStringContainsString(InvalidJsonException::class, $logged);
        $this->assertStringContainsString('AbstractServerTickGuardTest.php:', $logged);
    }

    public function testAClientThatReadsWithoutFailingIsKept(): void
    {
        $server = new AbstractServerTickGuardTestServer();
        $client = $server->seedClient(null);

        $server->onTick();

        $this->assertSame(1, $client->reads);
        $this->assertFalse($client->closed);
        $this->assertSame([$client], $server->getClients());
        $this->assertSame('', $this->logged());
    }

    /**
     * @return string Everything the tick wrote to the journal
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * A server that ticks the clients the test hands it and nothing else: it opens no
 * socket, so the accept path and its client factory are never reached.
 */
final class AbstractServerTickGuardTestServer extends AbstractServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * Puts a client in front of the tick as an accepted connection would.
     *
     * @param ?Throwable $failure Failure the client's read hands back, or null to read quietly
     * @return AbstractServerTickGuardTestClient Client the tick will see
     */
    public function seedClient(?Throwable $failure): AbstractServerTickGuardTestClient
    {
        $client = new AbstractServerTickGuardTestClient($failure);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @return string Server name the journal line names
     */
    public function getServerName(): string
    {
        return 'tick-guard-test';
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Client socket
     * @return ClientInterface Never returned; the test accepts no connection
     * @throws SocketException Always
     */
    protected function onCreateClient($socket): ClientInterface
    {
        throw new SocketException('the tick guard test accepts no connection');
    }
}

/**
 * A client whose read fails the way the test asked it to, and which remembers being
 * closed; nothing else about it is exercised.
 */
final class AbstractServerTickGuardTestClient implements ClientInterface
{
    /** @var bool True once the tick closed this client */
    public bool $closed = false;

    /** @var int Number of reads that ran to the end */
    public int $reads = 0;

    /**
     * @param ?Throwable $failure Failure the read hands back, or null to read quietly
     */
    public function __construct(private readonly ?Throwable $failure)
    {
    }

    /**
     * @throws Throwable The failure this client was built with, when it was built with one
     */
    public function read(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->reads++;
    }

    /**
     * @return null No socket behind this client
     */
    public function getSocket()
    {
        return null;
    }

    public function write(): void
    {
    }

    /**
     * @return bool Always false; the tick closes this client from its guard
     */
    public function shouldClose(): bool
    {
        return false;
    }

    public function markShouldClose(): void
    {
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function onTick(): void
    {
    }
}
