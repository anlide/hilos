<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\StandGateway;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\StandGateway\Behavior;
use Hilos\StandGateway\StandGatewayHttpClient;
use Hilos\StandGateway\StandGatewayTlsServer;
use Hilos\StandGateway\Store;
use Hilos\Tests\Unit\Socket\MutualTlsFixture;
use Hilos\Tests\Unit\Socket\TlsTestExchange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/**
 * The awaitRelease lever and POST /test/release over socket pairs, without another process.
 *
 * Every connection is the one the gateway builds for a call it accepts - every resident's routes
 * and the house's own - with the bare socket in place of TLS, so a call, a declaration and a release
 * all go through the routes the stand serves. The held answers are the local model's, the provider
 * route whose answer a spec dictates and which forwards nothing to a mailbox.
 */
final class ReleaseBehaviorTest extends TestCase
{
    /** Provider route of the local model, the route the held answers are called on. */
    private const string GENERATE = '/model/api/generate';

    /** @var list<Socket> Sockets closed after each case */
    private array $sockets = [];

    private ?EventLoop $loop = null;

    /** @var list<StandGatewayHttpClient> Clients detached before fixture sockets close */
    private array $boundClients = [];

    /** Loads the gateway's classes by name from the source directory its server loads them from. */
    public static function setUpBeforeClass(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Hilos\\StandGateway\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $file = dirname(__DIR__, 3) . '/docker/stand-gateway/src/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /** Isolates the gateway's arrangements. */
    protected function setUp(): void
    {
        Store::reset();
    }

    /** Drops connections and arrangements even after a failure. */
    protected function tearDown(): void
    {
        foreach ($this->boundClients as $client) {
            $client->detachEventLoop();
        }
        $this->loop?->close();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Store::reset();
    }

    public function testHeldAnswerStaysBackWithoutAReleaseWhileANeighborAnswers(): void
    {
        $this->dictateHeld('release-a', 'held answer');
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $this->assertSame('', $this->receive($peer), 'The held answer waits for its release');

        $this->request('POST', '/model/test/answer', ['key' => 'neighbor-b', 'response' => 'neighbor answer']);
        $this->assertSame('neighbor answer', $this->request('POST', self::GENERATE, ['prompt' => 'neighbor-b'])['response'],
            'A neighboring connection answers meanwhile');

        $held->write();
        $this->assertSame('', $this->receive($peer), 'A tick without a release lets nothing out');
    }

    public function testReleaseAfterArrivalLetsTheHeldAnswerOut(): void
    {
        $this->dictateHeld('release-a', 'held answer');
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $this->assertSame('', $this->receive($peer));

        $this->assertSame(['ok' => true], $this->release('release-a'));
        $held->write();

        $this->assertSame('held answer', $this->answerOf($this->receive($peer))['response']);
    }

    public function testReleaseBeforeArrivalLetsTheCallOutAtOnce(): void
    {
        $this->dictateHeld('release-a', 'held answer');
        $this->release('release-a');

        [, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);

        $this->assertSame('held answer', $this->answerOf($this->receive($peer))['response']);
    }

    public function testReleaseWakesOnlyItsPairWithoutNewProviderInputAndSpendsOneToken(): void
    {
        $this->loop = new EventLoop();
        $this->dictateHeld('release-a', 'first');
        [$first, $firstPeer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $this->dictateHeld('release-a', 'second');
        [$second, $secondPeer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $this->dictateHeld('release-b', 'neighbor');
        [$neighbor, $neighborPeer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-b']);
        $pairWakes = $neighborWakes = 0;
        $this->bind($first, $pairWakes);
        $this->bind($second, $pairWakes);
        $this->bind($neighbor, $neighborWakes);
        $this->pump();
        $this->assertFalse($this->loop->hasReadyWork(), 'Held buffers do not keep themselves runnable');
        $before = $pairWakes;

        Store::pushRelease(self::GENERATE, 'unrelated');
        $this->pump();
        $this->assertSame($before, $pairWakes, 'An unrelated mutation wakes no waiting client');
        $this->assertSame(1, $neighborWakes);

        $this->release('release-a');
        $this->assertSame('', $this->receive($firstPeer), 'Mutation enqueues; it never writes reentrantly');
        $this->pump();
        $firstWire = $this->receive($firstPeer);
        $secondWire = $this->receive($secondPeer);
        $this->assertSame(1, (int)($firstWire !== '') + (int)($secondWire !== ''));
        $answers = [$this->answerOf($firstWire !== '' ? $firstWire : $secondWire)['response']];
        $this->assertFalse(Store::takeRelease(self::GENERATE, 'release-a'));
        $this->assertSame('', $this->receive($neighborPeer));
        $this->assertSame(1, $neighborWakes, 'No common client tick/write scan');

        $this->release('release-a');
        $this->pump();
        $answers[] = $this->answerOf($this->receive($firstWire === '' ? $firstPeer : $secondPeer))['response'];
        sort($answers);
        $this->assertSame(['first', 'second'], $answers);
        $this->assertFalse(Store::takeRelease(self::GENERATE, 'release-a'));
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testReleaseDrainsTheExactAnswerBeforeAPipelinedKeepAliveRequest(): void
    {
        $this->loop = new EventLoop();
        $this->dictateHeld('release-a', 'held answer');
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $expected = new ReflectionProperty(AbstractClient::class, 'writeBuffer')->getValue($held);
        $wakes = 0;
        $this->bind($held, $wakes);
        $next = "GET /test/health HTTP/1.1\r\nHost: stand-gateway\r\n\r\n";
        $this->assertSame(strlen($next), socket_write($peer, $next));
        $this->pump();
        $this->assertSame('', $this->receive($peer));

        $this->release('release-a');
        $this->pump();
        $wire = $this->receive($peer);
        $this->assertStringStartsWith($expected, $wire, 'The accepted response drains whole and first');
        $this->assertSame(['ok' => true], $this->answerOf(substr($wire, strlen($expected))));
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{bool}> Both orders of the two releasing facts */
    public static function releaseOrders(): iterable
    {
        yield 'release before delay' => [true];
        yield 'delay before release' => [false];
    }

    /** @param bool $releaseFirst Whether the release precedes the due delay */
    #[DataProvider('releaseOrders')]
    public function testActualDelayTimerAndReleaseNeedBothFacts(bool $releaseFirst): void
    {
        $this->loop = new EventLoop();
        $this->request('POST', '/model/test/answer', ['key' => 'release-a', 'response' => 'late answer']);
        $this->declareBehavior(['key' => 'release-a', 'awaitRelease' => true, 'delayMs' => 60000]);
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $wakes = 0;
        $this->bind($held, $wakes);
        $this->pump();
        if ($releaseFirst) {
            $this->release('release-a');
            $this->pump();
        }
        $this->assertSame('', $this->receive($peer));
        $before = $wakes;
        $this->request('GET', '/test/health');
        $this->pump();
        $this->assertSame($before, $wakes, 'Unrelated I/O does not probe a future delay');

        new ReflectionProperty(StandGatewayHttpClient::class, 'releaseAt')->setValue($held, 0.0);
        new ReflectionProperty(StandGatewayHttpClient::class, 'waitTimer')->getValue($held)->armAt(0.0);
        $this->pump();
        if (!$releaseFirst) {
            $this->assertSame('', $this->receive($peer), 'A due delay cannot substitute for a release');
            $this->assertFalse($this->loop->hasReadyWork());
            $this->release('release-a');
            $this->pump();
        }
        $this->assertSame('late answer', $this->answerOf($this->receive($peer))['response']);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testDetachCancelsQueuedWaitsAndRebindKeepsAnAcceptedAnswerAcrossResetAndForget(): void
    {
        $this->loop = new EventLoop();
        $this->dictateHeld('release-a', 'accepted');
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $wakes = 0;
        $this->bind($held, $wakes);
        $this->pump();
        Store::pushRelease(self::GENERATE, 'release-a');
        $held->detachEventLoop();
        Store::reset();
        Store::forgetModel(self::GENERATE, 'release-a');
        $this->pump();
        $this->assertSame(1, $wakes, 'Detach cancels the old queued client portion');
        $this->assertSame([], new ReflectionProperty(Store::class, 'releaseSubscribers')->getValue(null));

        $this->bind($held, $wakes);
        $this->pump();
        $this->assertSame('', $this->receive($peer));
        Store::reset();
        Store::forgetModel(self::GENERATE, 'release-a');
        $this->release('release-a');
        $this->pump();
        $this->assertSame('accepted', $this->answerOf($this->receive($peer))['response']);
        $this->assertSame([], new ReflectionProperty(Store::class, 'releaseSubscribers')->getValue(null));
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testPeerEofCancelsTheDelayTimerAndReleaseSubscription(): void
    {
        $this->loop = new EventLoop();
        $this->request('POST', '/model/test/answer', ['key' => 'release-a', 'response' => 'abandoned']);
        $this->declareBehavior(['key' => 'release-a', 'awaitRelease' => true, 'delayMs' => 60000]);
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $wakes = 0;
        $this->bind($held, $wakes);
        $this->pump();
        $timer = new ReflectionProperty(StandGatewayHttpClient::class, 'waitTimer')->getValue($held);
        $this->assertNotNull($timer->getDeadline());
        $this->assertTrue(socket_shutdown($peer, 1));
        $this->pump();
        $this->assertTrue($held->shouldClose(), 'EOF wins over both held bytes and a future deadline');
        $this->assertNull($timer->getDeadline());
        $this->assertSame([], new ReflectionProperty(Store::class, 'releaseSubscribers')->getValue(null));
        $before = $wakes;
        Store::pushRelease(self::GENERATE, 'release-a');
        $this->pump();
        $this->assertSame($before, $wakes);
        $this->assertTrue(Store::takeRelease(self::GENERATE, 'release-a'), 'A departed answer cannot spend a later release');
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testNativeCutIsAppliedOnceAndHoldStartsOnlyAfterActualDrain(): void
    {
        $fixture = new MutualTlsFixture();
        $exchange = null;
        try {
            $authority = $fixture->issueAuthority('Gateway wait CA');
            $exchange = new TlsTestExchange($fixture,
                $fixture->issueNode('gateway', $authority), $fixture->issueNode('caller', $authority),
                null, $fixture->trustFile($authority));
            $client = new StandGatewayHttpClient($exchange->sockets['accepting'], $exchange->transports['accepting']);
            $exchange->bindClient('accepting', $client);
            $exchange->handshake();
            $this->assertSame([], $exchange->failures);
            socket_set_option($exchange->sockets['accepting'], SOL_SOCKET, SO_SNDBUF, 4096);
            $body = str_repeat('0123456789abcdef', 32768) . 'x';
            $router = new HttpRouter();
            $router->addRoute(HttpConstants::METHOD_GET, '/test/native', static fn(): string => $body);
            $client->setRouter($router);
            $client->dictate(Behavior::fromFields(['cut' => true, 'holdMs' => 60000]), self::GENERATE, 'native');
            $request = "GET /test/native HTTP/1.1\r\nHost: gateway\r\n\r\n";
            $this->assertSame(0, $exchange->transports['dialing']->write($request));
            $exchange->runUntil(fn(): bool => $client->hasPendingWrite() || $exchange->failures !== []);
            $this->assertSame([], $exchange->failures);
            $closeAt = new ReflectionProperty(StandGatewayHttpClient::class, 'closeAt');
            $buffer = new ReflectionProperty(AbstractClient::class, 'writeBuffer');
            $this->assertNull($closeAt->getValue($client), 'A native enqueue has not started the hold');
            $expected = $buffer->getValue($client);
            $this->assertStringContainsString('Content-Length: 524289', $expected);
            $this->assertSame(substr($body, 0, 262144), explode("\r\n\r\n", $expected, 2)[1]);
            $client->write();
            $client->write();
            $this->assertSame($expected, $buffer->getValue($client), 'Retry cannot cut an in-flight prefix again');
            $this->assertNull($closeAt->getValue($client));

            $exchange->transports['dialing']->detachEventLoop();
            for ($pass = 0; $pass < 100; ++$pass) {
                $exchange->loop->tick();
            }
            $this->assertTrue($client->hasPendingWrite(), 'Real native backpressure retains the remaining cut answer');
            $this->assertNull($closeAt->getValue($client), 'A partially drained answer still owes no hold deadline');
            $reader = $exchange->loop->createReadyWork(static function () use ($exchange): void {
                $exchange->received['dialing'] .= (string)$exchange->transports['dialing']->read(65536);
            });
            $exchange->transports['dialing']->attachEventLoop($exchange->loop, $reader->markReady(...));
            $exchange->runUntil(fn(): bool => ($closeAt->getValue($client) !== null
                && strlen($exchange->received['dialing']) === strlen($expected)) || $exchange->failures !== []);
            $this->assertSame([], $exchange->failures);
            $this->assertSame($expected, $exchange->received['dialing']);
            $this->assertFalse($client->hasPendingWrite());
            $this->assertFalse($client->shouldClose());
            $timer = new ReflectionProperty(StandGatewayHttpClient::class, 'waitTimer')->getValue($client);
            $this->assertSame($closeAt->getValue($client), $timer->getDeadline());
            $closeAt->setValue($client, 0.0);
            $timer->armAt(0.0);
            $exchange->runUntil(fn(): bool => $client->shouldClose() || $exchange->failures !== []);
            $this->assertSame([], $exchange->failures);
            $this->assertTrue($client->shouldClose(), 'The real due hold timer wakes its guarded client');
        } finally {
            $exchange?->close();
            $fixture->close();
        }
    }

    public function testOneReleaseLetsOutOneHeldAnswerOfThePair(): void
    {
        $this->dictateHeld('release-a', 'first');
        $this->dictateHeld('release-a', 'second');
        $calls = [
            $this->send('POST', self::GENERATE, ['prompt' => 'release-a']),
            $this->send('POST', self::GENERATE, ['prompt' => 'release-a']),
        ];

        $this->release('release-a');
        $this->assertSame(1, $this->countAnswered($calls), 'One release lets out one of the two, whichever it is');

        $this->release('release-a');
        $this->assertSame(1, $this->countAnswered($calls), 'The second release lets out the other one');
    }

    public function testHeldAnswerWithADelayLeavesWhenTheReleaseHasComeAndTheDelayIsOver(): void
    {
        $this->request('POST', '/model/test/answer', ['key' => 'release-a', 'response' => 'late answer']);
        $this->declareBehavior(['key' => 'release-a', 'awaitRelease' => true, 'delayMs' => 60000]);
        $this->release('release-a');
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        $this->assertSame('', $this->receive($peer), 'Released, but the delay is not over');

        // The delay's monotonic deadline is private; moving it into the past avoids waiting a minute.
        new ReflectionProperty(StandGatewayHttpClient::class, 'releaseAt')->setValue($held, 0.0);
        $held->write();

        $this->assertSame('late answer', $this->answerOf($this->receive($peer))['response']);
    }

    public function testHeldAnswerWithADelayStaysBackAfterTheDelayUntilItsRelease(): void
    {
        $this->request('POST', '/model/test/answer', ['key' => 'release-a', 'response' => 'late answer']);
        $this->declareBehavior(['key' => 'release-a', 'awaitRelease' => true, 'delayMs' => 60000]);
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);

        // The delay's monotonic deadline is private; moving it into the past avoids waiting a minute.
        new ReflectionProperty(StandGatewayHttpClient::class, 'releaseAt')->setValue($held, 0.0);
        $held->write();
        $this->assertSame('', $this->receive($peer), 'The delay is over, but no release came');

        $this->release('release-a');
        $held->write();

        $this->assertSame('late answer', $this->answerOf($this->receive($peer))['response']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> Refused releases and the code each is refused with */
    public static function refusedReleases(): iterable
    {
        yield 'a lever' => [
            ['path' => self::GENERATE, 'key' => 'release-a', 'awaitRelease' => true],
            'FIELD_UNKNOWN',
        ];
        yield 'an unknown key before a wrong path' => [['path' => '/nowhere', 'delay_ms' => 1], 'FIELD_UNKNOWN'];
        yield 'no path' => [['key' => 'release-a'], 'PATH_NOT_PROVIDER'];
        yield 'a path nobody registered' => [['path' => '/nowhere', 'key' => 'release-a'], 'PATH_NOT_PROVIDER'];
        yield 'a test half' => [['path' => '/model/test/answer', 'key' => 'release-a'], 'PATH_NOT_PROVIDER'];
        yield 'a house route' => [['path' => '/test/release', 'key' => 'release-a'], 'PATH_NOT_PROVIDER'];
        yield 'a wrong path before no key' => [['path' => '/nowhere'], 'PATH_NOT_PROVIDER'];
        yield 'no key' => [['path' => self::GENERATE], 'KEY_REQUIRED'];
        yield 'an empty key' => [['path' => self::GENERATE, 'key' => ''], 'KEY_REQUIRED'];
        yield 'a key that is not a string' => [['path' => self::GENERATE, 'key' => 7], 'KEY_REQUIRED'];
    }

    /**
     * @param array<string, mixed> $body Refused release
     * @param string $error Code the release is refused with
     */
    #[DataProvider('refusedReleases')]
    public function testReleaseIsRefusedWithTheCodesAndTheOrderOfADeclaration(array $body, string $error): void
    {
        $this->assertSame(['ok' => false, 'error' => $error], $this->request('POST', '/test/release', $body, 400));
        $this->assertFalse(Store::takeRelease(self::GENERATE, 'release-a'), 'A refused release is not kept');
    }

    /** @return iterable<string, array{mixed}> Values of the release lever that are not a boolean */
    public static function nonBooleanAwaitRelease(): iterable
    {
        yield 'a string' => ['true'];
        yield 'an integer' => [1];
        yield 'null' => [null];
    }

    /** @param mixed $awaitRelease Value that is not a boolean */
    #[DataProvider('nonBooleanAwaitRelease')]
    public function testDeclarationRefusesAnAwaitReleaseThatIsNotABoolean(mixed $awaitRelease): void
    {
        $this->assertSame(
            ['ok' => false, 'error' => 'RELEASE_INVALID'],
            $this->declareBehavior(['key' => 'release-a', 'awaitRelease' => $awaitRelease], 400),
        );
        $this->assertNull(Store::takeBehavior(self::GENERATE, 'release-a'), 'A refused declaration is not queued');
    }

    public function testResetWipesKeptReleases(): void
    {
        $this->release('release-a');
        $this->assertSame(['ok' => true], $this->request('POST', '/test/reset'));

        $this->dictateHeld('release-a', 'held answer');
        [, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);

        $this->assertSame('', $this->receive($peer), 'The release sent before the reset is gone');
    }

    public function testForgettingAModelKeyWipesOnlyItsKeptReleases(): void
    {
        $this->release('release-a');
        $this->release('release-b');
        $this->assertSame(['ok' => true], $this->request('POST', '/model/test/forget', ['key' => 'release-a']));

        $this->dictateHeld('release-a', 'forgotten answer');
        $this->dictateHeld('release-b', 'kept answer');
        [, $forgotten] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);
        [, $kept] = $this->send('POST', self::GENERATE, ['prompt' => 'release-b']);

        $this->assertSame('', $this->receive($forgotten), 'The forgotten key\'s release is gone');
        $this->assertSame('kept answer', $this->answerOf($this->receive($kept))['response']);
    }

    /**
     * Dictates the model's answer for a key, held until a release lets it out.
     *
     * @param string $key Key the prompt of the call carries
     * @param string $response Text the model answers with
     */
    private function dictateHeld(string $key, string $response): void
    {
        $this->request('POST', '/model/test/answer', ['key' => $key, 'response' => $response]);
        $this->declareBehavior(['key' => $key, 'awaitRelease' => true]);
    }

    /**
     * Declares a behavior for the model's provider route at POST /test/behavior.
     *
     * @param array<string, mixed> $declaration Declaration without its path
     * @param int $status Expected HTTP status
     * @return array Acknowledgement or refusal
     */
    private function declareBehavior(array $declaration, int $status = 200): array
    {
        return $this->request('POST', '/test/behavior', ['path' => self::GENERATE, ...$declaration], $status);
    }

    /**
     * Releases one held answer of the model's provider route at POST /test/release.
     *
     * @param string $key Key the held answer is keyed by
     * @return array Acknowledgement
     */
    private function release(string $key): array
    {
        return $this->request('POST', '/test/release', ['path' => self::GENERATE, 'key' => $key]);
    }

    /**
     * Writes every held call once, as one tick of the gateway would, and counts those that answered.
     *
     * @param list<array{StandGatewayHttpClient, Socket}> $calls Held calls and their callers
     * @return int How many of the calls answered on this tick
     */
    private function countAnswered(array $calls): int
    {
        $answered = 0;
        foreach ($calls as [$client, $peer]) {
            $client->write();
            if ($this->receive($peer) !== '') {
                $answered++;
            }
        }

        return $answered;
    }

    /**
     * @param string $method HTTP method
     * @param string $path Request path and optional query
     * @param array<string, mixed> $body JSON body
     * @param int $status Expected HTTP status
     * @return array Decoded JSON response
     */
    private function request(string $method, string $path, array $body = [], int $status = 200): array
    {
        [, $peer] = $this->send($method, $path, $body);

        return $this->answerOf($this->receive($peer), $status);
    }

    /**
     * @param string $response Whole HTTP answer as it arrived
     * @param int $status Expected HTTP status
     * @return array Decoded JSON body
     */
    private function answerOf(string $response, int $status = 200): array
    {
        $this->assertStringStartsWith('HTTP/1.1 ' . $status . ' ', $response);

        return json_decode(explode("\r\n\r\n", $response, 2)[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $method HTTP method
     * @param string $path Request path and optional query
     * @param array<string, mixed> $body JSON body
     * @return array{StandGatewayHttpClient, Socket} Gateway connection and its caller
     */
    private function send(string $method, string $path, array $body): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->sockets = [...$this->sockets, ...$pair];
        $this->assertTrue(socket_set_nonblock($pair[0]));

        // The connection the gateway builds for an accepted call - its residents' routes and its own.
        $server = new StandGatewayTlsServer('127.0.0.1', 0, dirname(__DIR__, 3) . '/docker/stand-gateway/tls/server.pem');
        $server->prepareTlsContexts();
        $client = new ReflectionMethod(StandGatewayTlsServer::class, 'onCreateClient')->invoke($server, $pair[0]);
        // A socket pair carries no TLS: the bare transport the framework's client uses when given none.
        new ReflectionProperty(AbstractClient::class, 'transport')->setValue($client, new PlainSocketTransport($pair[0]));

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $request = $method . ' ' . $path . " HTTP/1.1\r\nHost: stand-gateway\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json;
        $this->assertSame(strlen($request), socket_write($pair[1], $request));
        $client->read();
        $client->write();

        return [$client, $pair[1]];
    }

    /**
     * @param Socket $peer Caller's socket
     * @return string All bytes available without waiting
     */
    private function receive(Socket $peer): string
    {
        $response = '';
        while (true) {
            $read = [$peer];
            $write = $except = null;
            if (socket_select($read, $write, $except, 0) !== 1) {
                return $response;
            }
            $chunk = socket_read($peer, 65536);
            if ($chunk === '' || $chunk === false) {
                return $response;
            }
            $response .= $chunk;
        }
    }

    /**
     * @param StandGatewayHttpClient $client Connection whose real EventLoop owns its waits
     * @param int $wakes Number of this client's guarded portions
     */
    private function bind(StandGatewayHttpClient $client, int &$wakes): void
    {
        $this->boundClients[] = $client;
        $client->attachEventLoop($this->loop, static function () use ($client, &$wakes): void {
            ++$wakes;
            $client->read();
            $client->onTick();
            $client->write();
            if ($client->shouldClose()) {
                $client->detachEventLoop();
            }
        });
    }

    /** Services a bounded number of nonblocking native/owner portions without a clock wait or client scan. */
    private function pump(): void
    {
        for ($pass = 0; $pass < 16; ++$pass) {
            $this->loop->tick();
        }
    }
}
