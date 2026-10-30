<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\StandGateway;

use Hilos\API\Router\HttpRouter;
use Hilos\StandGateway\Behavior;
use Hilos\StandGateway\GatewayRoutes;
use Hilos\StandGateway\ModelRoutes;
use Hilos\StandGateway\StandGatewayHttpClient;
use Hilos\StandGateway\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;

/**
 * The resident's real HTTP parser and behavior delay over socket pairs, without another process.
 * Live TLS/daemon consumption belongs to the causal e2e scenarios; this fixture exercises the
 * PHP resident itself, including two connections interleaving while one answer is held back.
 */
final class ModelObservationTest extends TestCase
{
    /** @var list<Socket> Sockets closed after each case */
    private array $sockets = [];

    /** Loads the resident through the same source files its server uses. */
    public static function setUpBeforeClass(): void
    {
        foreach (['InvalidBehaviorException', 'Behavior', 'Store', 'GatewayResident', 'StandGatewayHttpClient', 'StandGatewayTlsServer',
            'GatewayRoutes', 'InvalidModelAnswerException', 'ModelRoutes'] as $class) {
            require_once dirname(__DIR__, 3) . '/docker/stand-gateway/src/' . $class . '.php';
        }
    }

    /** Isolates the resident's arrangements. */
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

    public function testCapturesRealArrivalBeforeDelayedResponseAndHashesDecodedImages(): void
    {
        $this->request('POST', '/model/test/answer', ['key' => 'owned', 'response' => 'first']);
        Store::pushBehavior('/model/api/generate', 'owned', Behavior::fromFields(['delayMs' => 60000]));
        [$delayed, $peer] = $this->send('POST', '/model/api/generate', [
            'model' => 'vision', 'prompt' => 'owned in a real prompt',
            'images' => [base64_encode("\x00image-a\xff"), base64_encode('image-b')],
        ]);
        $this->assertSame('', $this->receive($peer), 'The provider answer is still delayed');
        $first = ['ordinal' => 1, 'key' => 'owned', 'imageHashes' => [hash('sha256', "\x00image-a\xff"), hash('sha256', 'image-b')]];
        $this->assertSame([$first], $this->request('GET', '/model/test/requests?key=owned'));

        $this->request('POST', '/model/test/answer', ['key' => 'owned', 'response' => 'second']);
        $this->request('POST', '/model/api/generate', ['prompt' => 'owned; attachment metadata only']);
        $this->assertSame([$first, ['ordinal' => 2, 'key' => 'owned', 'imageHashes' => []]],
            $this->request('GET', '/model/test/requests?key=owned'));
        $this->assertSame([], $this->request('GET', '/model/test/requests?key=another'));

        // Finishing an older delayed response cannot overwrite a later arrival's record.
        new ReflectionProperty(StandGatewayHttpClient::class, 'releaseAt')->setValue($delayed, microtime(true) - 1);
        $delayed->write();
        $this->assertStringContainsString('first', $this->receive($peer));
        $this->assertCount(2, $this->request('GET', '/model/test/requests?key=owned'));
    }

    public function testForgetIsScopedAndIdempotentEvenWithAResidentResponseStillPending(): void
    {
        $this->request('POST', '/model/test/answer', ['key' => 'owned', 'response' => 'pending']);
        Store::pushBehavior('/model/api/generate', 'owned', Behavior::fromFields(['delayMs' => 60000]));
        [$delayed, $peer] = $this->send('POST', '/model/api/generate', ['prompt' => 'owned']);
        $this->request('POST', '/model/test/answer', ['key' => 'owned', 'response' => 'leftover']);
        $this->request('POST', '/model/test/answer', ['key' => 'foreign', 'response' => 'kept']);
        Store::pushBehavior('/model/api/generate', 'owned', Behavior::fromFields(['status' => 502]));
        Store::pushBehavior('/model/api/generate', 'foreign', Behavior::fromFields(['holdMs' => 100]));
        Store::pushBehavior('/sms/send', 'owned', Behavior::fromFields(['status' => 503]));
        $this->request('POST', '/model/api/generate', ['prompt' => 'foreign']);
        $this->request('POST', '/model/test/answer', ['key' => 'foreign', 'response' => 'still kept']);
        Store::pushBehavior('/model/api/generate', 'foreign', Behavior::fromFields(['status' => 501]));
        $foreign = $this->request('GET', '/model/test/requests?key=foreign');

        $this->assertSame(['ok' => true], $this->request('POST', '/model/test/forget', ['key' => 'owned']));
        $this->assertSame(['ok' => true], $this->request('POST', '/model/test/forget', ['key' => 'owned']));
        $this->assertSame([], $this->request('GET', '/model/test/requests?key=owned'));
        $this->assertNull(Store::takeAnswer('owned'));
        $this->assertNull(Store::takeBehavior('/model/api/generate', 'owned'));
        $this->assertSame($foreign, $this->request('GET', '/model/test/requests?key=foreign'));
        $this->assertSame('still kept', Store::takeAnswer('foreign'));
        $this->assertSame(501, Store::takeBehavior('/model/api/generate', 'foreign')?->status);
        $this->assertSame(503, Store::takeBehavior('/sms/send', 'owned')?->status);
        new ReflectionProperty(StandGatewayHttpClient::class, 'releaseAt')->setValue($delayed, microtime(true) - 1);
        $delayed->write();
        $this->assertStringContainsString('pending', $this->receive($peer));
        $this->assertSame([], $this->request('GET', '/model/test/requests?key=owned'));
    }

    public function testBehaviorOnlyCallIsObservedEvenWhenTheResidentRefusesAnUndictatedAnswer(): void
    {
        Store::pushBehavior('/model/api/generate', 'behavior-only', Behavior::fromFields(['status' => 503]));
        $this->request('POST', '/model/api/generate', ['prompt' => 'behavior-only'], 503);
        $this->assertSame([['ordinal' => 1, 'key' => 'behavior-only', 'imageHashes' => []]],
            $this->request('GET', '/model/test/requests?key=behavior-only'));
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid scoped request bodies */
    public static function malformedKeys(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['key' => '']];
        yield 'integer' => [['key' => 7]];
        yield 'null' => [['key' => null]];
        yield 'array' => [['key' => ['owned']]];
        yield 'boolean' => [['key' => true]];
        yield 'unknown' => [['key' => 'owned', 'other' => 'field']];
    }

    /** @param array<string, mixed> $body Invalid cleanup body */
    #[DataProvider('malformedKeys')]
    public function testCleanupRequiresOneStrictNonemptyKey(array $body): void
    {
        $reply = $this->request('POST', '/model/test/forget', $body, 400);
        $this->assertFalse($reply['ok']);
    }

    public function testObservationRequiresAKeyAndRoutesRefuseOtherMethods(): void
    {
        $this->request('GET', '/model/test/requests', [], 400);
        $this->request('GET', '/model/test/requests?key=', [], 400);
        $this->request('POST', '/model/test/requests', ['key' => 'owned'], 404);
        $this->request('GET', '/model/test/forget?key=owned', [], 404);
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
        $response = $this->receive($peer);
        $this->assertStringStartsWith('HTTP/1.1 ' . $status . ' ', $response);
        return json_decode(explode("\r\n\r\n", $response, 2)[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $method HTTP method
     * @param string $path Request path and optional query
     * @param array<string, mixed> $body JSON body
     * @return array{StandGatewayHttpClient, Socket} Real resident connection and its caller
     */
    private function send(string $method, string $path, array $body): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->sockets = [...$this->sockets, ...$pair];
        $this->assertTrue(socket_set_nonblock($pair[0]));
        $client = new StandGatewayHttpClient($pair[0]);
        $router = new HttpRouter();
        $client->setRouter($router);
        new ModelRoutes()->register(new GatewayRoutes($router, $client));
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
