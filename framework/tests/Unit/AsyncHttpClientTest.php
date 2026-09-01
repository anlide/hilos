<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpBusyException;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\API\Exception\AsyncHttpTlsHandshakeException;
use Hilos\Constants\HttpConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for async HTTP client result and exception contracts.
 */
final class AsyncHttpClientTest extends TestCase
{
    /** @var list<string> PEM files written by this test, removed when it ends */
    private array $temporaryFiles = [];

    /**
     * Successful responses are consumed once through consumeResult().
     */
    public function testConsumesSuccessfulResponse(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $client = new AsyncHttpClient('127.0.0.1', $port, '/status');
            $client->startNewRequest(microtime(true) * 1000);
            $this->serveResponseUntilFinished($server, $client, $this->response(200, 'hello'));

            $this->assertTrue($client->hasResult());
            $response = $client->consumeResult();
            $this->assertSame(200, $response->statusCode);
            $this->assertSame('hello', $response->body);
            $this->assertFalse($client->hasResult());
        } finally {
            fclose($server);
        }
    }

    /**
     * Starting a second request while connecting fails through exception.
     */
    public function testStartNewRequestThrowsWhenBusy(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $client = new AsyncHttpClient('127.0.0.1', $port, '/status');
            $client->startNewRequest(microtime(true) * 1000);

            $this->expectException(AsyncHttpBusyException::class);
            $client->startNewRequest(microtime(true) * 1000);
        } finally {
            fclose($server);
        }
    }

    /**
     * Non-success HTTP status codes are failures, not false result payloads.
     */
    public function testNonSuccessStatusThrows(): void
    {
        [$server, $port] = $this->createServer();

        try {
            $client = new AsyncHttpClient('127.0.0.1', $port, '/status');
            $client->startNewRequest(microtime(true) * 1000);

            $this->expectException(AsyncHttpStatusException::class);
            $this->serveResponseUntilFinished($server, $client, $this->response(500, 'broken'));
        } finally {
            fclose($server);
        }
    }

    /**
     * A handshake needing several non-blocking steps is not an error: the request goes on.
     */
    public function testTlsHandshakeCompletesAcrossTicks(): void
    {
        $client = new ScriptedCryptoAsyncHttpClient('127.0.0.1', 443, '/token', true);
        $client->cryptoOutcomes = [0, 0, true];

        $client->startNewRequest(microtime(true) * 1000);
        $this->servePairResponseUntilFinished($client, $this->response(200, 'granted'));

        $this->assertSame(3, $client->cryptoSteps);
        $this->assertFalse($client->isBusy());
        $this->assertSame('granted', $client->consumeResult()->body);
    }

    /**
     * A refused handshake fails as itself and carries the captured reason, not as a parse error.
     */
    public function testTlsHandshakeFailureThrowsHandshakeException(): void
    {
        $client = new ScriptedCryptoAsyncHttpClient('127.0.0.1', 443, '/token', true);
        $client->cryptoOutcomes = [false];

        $client->startNewRequest(microtime(true) * 1000);

        $this->expectException(AsyncHttpTlsHandshakeException::class);
        $this->expectExceptionMessageMatches('/scripted handshake failure/');
        $this->servePairResponseUntilFinished($client, $this->response(200, 'granted'));
    }

    /**
     * The phase holds the request back: no byte reaches the peer until crypto is up.
     */
    public function testNoBytesWrittenBeforeHandshakeCompletes(): void
    {
        $client = new ScriptedCryptoAsyncHttpClient('127.0.0.1', 443, '/token', true);
        $client->cryptoOutcomes = array_fill(0, 6, 0);

        $client->startNewRequest(microtime(true) * 1000);
        for ($tick = 0; $tick < 6; $tick++) {
            $client->tick(microtime(true) * 1000);
        }

        $this->assertSame(5, $client->cryptoSteps);
        $this->assertSame('', fread($client->peer, 8192));
    }

    /**
     * The response of a real TLS peer is parsed: an empty read is not the end of it.
     *
     * The peer runs in a forked child on purpose. A server sharing this process can only take its
     * turn between the client's ticks, and by then the decrypted bytes are already waiting - the
     * false empty read this test is about needs a peer that answers while the client is reading.
     */
    public function testHttpsRequestOverRealTlsServer(): void
    {
        [$certificateFile, $bundleFile] = $this->issueSelfSignedCertificate();
        [$server, $port] = $this->createTlsServer($bundleFile);
        $child = $this->serveTlsResponseInChild($server, $this->response(200, 'granted'));

        try {
            $client = new TrustingAsyncHttpClient('127.0.0.1', $port, '/token', true);
            $client->caFile = $certificateFile;
            $client->startNewRequest(microtime(true) * 1000);

            $deadline = microtime(true) + 5.0;
            while (!$client->hasResult() && microtime(true) < $deadline) {
                $client->tick(microtime(true) * 1000);
                usleep(1000);
            }

            $this->assertTrue($client->hasResult(), 'Async HTTP client did not finish within timeout');
            $this->assertSame('granted', $client->consumeResult()->body);
        } finally {
            pcntl_waitpid($child, $status);
        }
    }

    /**
     * A certificate whose name does not match the verified peer name is refused.
     */
    public function testTlsHandshakeRejectsCertificateNameMismatch(): void
    {
        [$certificateFile, $bundleFile] = $this->issueSelfSignedCertificate();
        [$server, $port] = $this->createTlsServer($bundleFile);

        try {
            $client = new TrustingAsyncHttpClient('127.0.0.1', $port, '/token', true);
            $client->caFile = $certificateFile;
            $client->peerNameOverride = 'wrong.invalid.example';
            $client->startNewRequest(microtime(true) * 1000);

            $this->expectException(AsyncHttpTlsHandshakeException::class);
            $this->expectExceptionMessageMatches('/did not match/');
            $this->serveTlsResponseUntilFinished($server, $client, $this->response(200, 'granted'));
        } finally {
            fclose($server);
        }
    }

    /**
     * A certificate signed by nobody the client trusts is refused: verification stays on.
     */
    public function testTlsHandshakeRejectsUntrustedCertificate(): void
    {
        [, $bundleFile] = $this->issueSelfSignedCertificate();
        [$server, $port] = $this->createTlsServer($bundleFile);

        try {
            $client = new AsyncHttpClient('127.0.0.1', $port, '/token', true);
            $client->startNewRequest(microtime(true) * 1000);

            $this->expectException(AsyncHttpTlsHandshakeException::class);
            $this->serveTlsResponseUntilFinished($server, $client, $this->response(200, 'granted'));
        } finally {
            fclose($server);
        }
    }

    /**
     * Removes the PEM files the TLS tests issued for themselves.
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    /**
     * Creates a non-blocking local TCP server.
     *
     * @return array{0: resource, 1: int} Server socket and port
     */
    private function createServer(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        stream_set_blocking($server, false);

        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return [$server, $port];
    }

    /**
     * Creates a local TCP listener whose accepted connection this test secures step by step.
     *
     * The listener is plain TCP on purpose. A `tls://` listener runs the server handshake inside
     * stream_socket_accept(), which blocks this single process while the only side able to answer
     * that handshake - the client under test - is waiting for its next tick.
     *
     * @param string $bundleFile Certificate and private key the server presents
     * @return array{0: resource, 1: int} Server socket and port
     */
    private function createTlsServer(string $bundleFile): array
    {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $bundleFile,
                'allow_self_signed' => true,
                'verify_peer' => false,
            ],
        ]);

        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        $this->assertIsResource($server, $errstr);
        stream_set_blocking($server, false);

        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return [$server, $port];
    }

    /**
     * Issues a self-signed certificate for 127.0.0.1, so the TLS tests need no fixture on disk.
     *
     * @return array{0: string, 1: string} Certificate-only PEM file, and certificate plus key file
     */
    private function issueSelfSignedCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key, 'test certificate key could not be generated');

        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
        $this->assertNotFalse($request, 'test certificate request could not be generated');

        $certificate = openssl_csr_sign($request, null, $key, 1);
        $this->assertNotFalse($certificate, 'test certificate could not be signed');

        $certificatePem = '';
        $keyPem = '';
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem));

        return [
            $this->writeTemporaryPem('hilos-tls-ca', $certificatePem),
            $this->writeTemporaryPem('hilos-tls-server', $certificatePem . $keyPem),
        ];
    }

    /**
     * Writes one PEM file for the duration of the test.
     *
     * @param string $prefix Temporary file name prefix
     * @param string $contents PEM contents
     * @return string Path of the written file
     */
    private function writeTemporaryPem(string $prefix, string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($file, 'temporary PEM file could not be created');
        $this->temporaryFiles[] = $file;
        $this->assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }

    /**
     * Runs the client/server loop until the client result or exception arrives.
     *
     * @param resource $server Server socket
     * @param AsyncHttpClient $client Client under test
     * @param string $response Raw HTTP response to send
     */
    private function serveResponseUntilFinished($server, AsyncHttpClient $client, string $response): void
    {
        $connection = null;
        $requestBuffer = '';
        $deadline = microtime(true) + 2.0;

        try {
            while (!$client->hasResult() && microtime(true) < $deadline) {
                $client->tick(microtime(true) * 1000);

                if ($connection === null) {
                    $read = [$server];
                    $write = [];
                    $except = [];
                    if (stream_select($read, $write, $except, 0, 0) > 0) {
                        $accepted = stream_socket_accept($server, 0);
                        if (is_resource($accepted)) {
                            $connection = $accepted;
                            stream_set_blocking($connection, false);
                        }
                    }
                }

                if (is_resource($connection)) {
                    $chunk = fread($connection, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $requestBuffer .= $chunk;
                    }

                    if (str_contains($requestBuffer, HttpConstants::HTTP_DELIMITER)) {
                        fwrite($connection, $response);
                        fclose($connection);
                        $connection = null;
                    }
                }

                usleep(1000);
            }
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }

        $this->assertTrue($client->hasResult(), 'Async HTTP client did not finish within timeout');
    }

    /**
     * Runs the client against a real TLS peer, driving the server handshake one step per tick.
     *
     * @param resource $server Server socket
     * @param AsyncHttpClient $client Client under test
     * @param string $response Raw HTTP response to send once the request arrives
     */
    private function serveTlsResponseUntilFinished($server, AsyncHttpClient $client, string $response): void
    {
        $connection = null;
        $secured = false;
        $answered = false;
        $requestBuffer = '';
        $deadline = microtime(true) + 5.0;

        try {
            while (!$client->hasResult() && microtime(true) < $deadline) {
                $client->tick(microtime(true) * 1000);

                if ($connection === null) {
                    $accepted = stream_socket_accept($server, 0);
                    if (is_resource($accepted)) {
                        $connection = $accepted;
                        stream_set_blocking($connection, false);
                    }
                }

                if (is_resource($connection) && !$secured) {
                    // warning-suppressed: the int|bool result decides below, and a failed server
                    // handshake is what the negative tests of this file are about
                    $enabled = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                    if ($enabled === true) {
                        $secured = true;
                    } elseif ($enabled === false) {
                        fclose($connection);
                        $connection = null;
                    }
                }

                if ($secured && !$answered && is_resource($connection)) {
                    $chunk = fread($connection, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $requestBuffer .= $chunk;
                    }

                    if (str_contains($requestBuffer, HttpConstants::HTTP_DELIMITER)) {
                        fwrite($connection, $response);
                        fclose($connection);
                        $answered = true;
                    }
                }

                usleep(1000);
            }
        } finally {
            if (is_resource($connection) && !$answered) {
                fclose($connection);
            }
        }

        $this->assertTrue($client->hasResult(), 'Async HTTP client did not finish within timeout');
    }

    /**
     * Serves one TLS response from a forked child, so the peer answers while the client reads.
     *
     * @param resource $server Server socket, closed in this process and owned by the child
     * @param string $response Raw HTTP response to send once the request arrives
     * @return int Pid of the child serving the response
     */
    private function serveTlsResponseInChild($server, string $response): int
    {
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child, 'TLS peer could not be forked');

        if ($child !== 0) {
            fclose($server);

            return $child;
        }

        $connection = null;
        $secured = false;
        $deadline = microtime(true) + 10.0;
        $requestBuffer = '';

        while (microtime(true) < $deadline) {
            if ($connection === null) {
                $accepted = stream_socket_accept($server, 0);
                if (is_resource($accepted)) {
                    $connection = $accepted;
                    stream_set_blocking($connection, false);
                }
            }

            if (is_resource($connection) && !$secured) {
                // warning-suppressed: the int|bool result decides below, and a handshake this peer
                // cannot finish ends the child rather than the test
                $enabled = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                if ($enabled === true) {
                    $secured = true;
                } elseif ($enabled === false) {
                    break;
                }
            }

            if ($secured) {
                $chunk = fread($connection, 8192);
                if (is_string($chunk) && $chunk !== '') {
                    $requestBuffer .= $chunk;
                }

                if (str_contains($requestBuffer, HttpConstants::HTTP_DELIMITER)) {
                    fwrite($connection, $response);
                    usleep(50000);
                    fclose($connection);
                    break;
                }
            }

            usleep(1000);
        }

        exit(0);
    }

    /**
     * Runs the client against the server end of its own socket pair until it finishes.
     *
     * @param ScriptedCryptoAsyncHttpClient $client Client under test
     * @param string $response Raw HTTP response to send once the request arrives
     */
    private function servePairResponseUntilFinished(ScriptedCryptoAsyncHttpClient $client, string $response): void
    {
        $requestBuffer = '';
        $answered = false;
        $deadline = microtime(true) + 2.0;

        while (!$client->hasResult() && microtime(true) < $deadline) {
            $client->tick(microtime(true) * 1000);

            if (!$answered) {
                $chunk = fread($client->peer, 8192);
                if (is_string($chunk) && $chunk !== '') {
                    $requestBuffer .= $chunk;
                }

                if (str_contains($requestBuffer, HttpConstants::HTTP_DELIMITER)) {
                    fwrite($client->peer, $response);
                    fclose($client->peer);
                    $answered = true;
                }
            }

            usleep(1000);
        }

        $this->assertTrue($client->hasResult(), 'Async HTTP client did not finish within timeout');
    }

    /**
     * Builds a raw HTTP response.
     *
     * @param int $statusCode HTTP status code
     * @param string $body Response body
     * @return string Raw HTTP response
     */
    private function response(int $statusCode, string $body): string
    {
        return "HTTP/1.1 {$statusCode} Test" . HttpConstants::HTTP_LINE_SEPARATOR
            . HttpConstants::HEADER_CONTENT_LENGTH . ': ' . strlen($body) . HttpConstants::HTTP_LINE_SEPARATOR
            . HttpConstants::HEADER_CONNECTION . ': close'
            . HttpConstants::HTTP_DELIMITER
            . $body;
    }
}

/**
 * Async HTTP client whose socket is an in-memory pair and whose handshake the test scripts,
 * so the state machine is driven without a real TLS peer.
 */
final class ScriptedCryptoAsyncHttpClient extends AsyncHttpClient
{
    /** @var resource The client end's peer, read and written by the test. */
    public $peer;

    /** @var list<int|bool> Handshake outcomes handed out one per step, oldest first. */
    public array $cryptoOutcomes = [];

    /** @var int Handshake steps the client has taken. */
    public int $cryptoSteps = 0;

    /**
     * Returns the client end of a socket pair and keeps the server end for the test.
     *
     * @return resource The client end of the in-memory socket pair
     */
    protected function establishSocket()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->peer = $pair[1];

        return $pair[0];
    }

    /**
     * Hands out the next scripted handshake outcome, with a reason to carry on failure.
     *
     * @param ?string $warning Reason the scripted failure reports
     * @return int|bool The next scripted outcome
     */
    protected function enableCrypto(?string &$warning): int|bool
    {
        $this->cryptoSteps++;
        $warning = 'scripted handshake failure';

        return array_shift($this->cryptoOutcomes);
    }
}

/**
 * Async HTTP client trusting the certificate authority one test issued for itself, and able to
 * verify against another peer name than the host it connects to.
 */
final class TrustingAsyncHttpClient extends AsyncHttpClient
{
    /** @var string PEM file of the authority this client trusts on top of the system ones */
    public string $caFile = '';

    /** @var ?string Peer name verified instead of the request host, or null to keep the host */
    public ?string $peerNameOverride = null;

    /**
     * Returns the production TLS options plus this test's own trust anchor.
     *
     * @return array<string, array<string, mixed>> Stream context options
     */
    protected function streamContextOptions(): array
    {
        $options = parent::streamContextOptions();
        $options['ssl']['cafile'] = $this->caFile;

        if ($this->peerNameOverride !== null) {
            $options['ssl']['peer_name'] = $this->peerNameOverride;
        }

        return $options;
    }
}
