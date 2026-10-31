<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\StandGateway;

use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\StandGateway\StandGatewayHttpClient;
use Hilos\StandGateway\StandGatewayTlsServer;
use Hilos\StandGateway\Store;
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

        // The delay's deadline is private to the connection; moving it into the past stands in for a minute of ticks.
        new ReflectionProperty(StandGatewayHttpClient::class, 'releaseAt')->setValue($held, microtime(true) - 1);
        $held->write();

        $this->assertSame('late answer', $this->answerOf($this->receive($peer))['response']);
    }

    public function testHeldAnswerWithADelayStaysBackAfterTheDelayUntilItsRelease(): void
    {
        $this->request('POST', '/model/test/answer', ['key' => 'release-a', 'response' => 'late answer']);
        $this->declareBehavior(['key' => 'release-a', 'awaitRelease' => true, 'delayMs' => 60000]);
        [$held, $peer] = $this->send('POST', self::GENERATE, ['prompt' => 'release-a']);

        // The delay's deadline is private to the connection; moving it into the past stands in for a minute of ticks.
        new ReflectionProperty(StandGatewayHttpClient::class, 'releaseAt')->setValue($held, microtime(true) - 1);
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
        $server = new StandGatewayTlsServer('127.0.0.1', 0, '');
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
}
