<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\TlsSocketTransport;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * A client reading and writing through the server side of TLS (HIL-921).
 *
 * The transport seam exists because three answers of an encrypted stream mean something else
 * than the same answers of a bare socket, and a client that read them the bare way would cut
 * connections short, lose writes or answer a peer that cannot read it. Each test puts a real
 * TLS peer on the other end and reads one of those answers through the client that relies on it.
 *
 * Both ends live in this one process: the peer steps its own handshake without blocking, turn
 * about with the client, so no child process is needed to answer while the client waits.
 */
final class TlsSocketTransportTest extends TestCase
{
    /** Time any one exchange below is given before the test gives up on it */
    private const float EXCHANGE_SECONDS = 5.0;

    /** Bytes queued for a peer that is not reading, enough to fill every buffer between the two ends */
    private const int LARGE_PAYLOAD_BYTES = 8 * 1024 * 1024;

    /** Most bytes the peer takes off its socket in one read */
    private const int PEER_READ_BYTES = 65536;

    /** @var list<string> Temporary files removed after each test */
    private array $temporaryFiles = [];

    /** @var list<Socket> Listening sockets closed after each test */
    private array $listeners = [];

    /** @var list<resource> Peer streams closed after each test */
    private array $peers = [];

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            if (is_resource($peer)) {
                fclose($peer);
            }
        }

        foreach ($this->listeners as $listener) {
            socket_close($listener);
        }

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * A handshake that answers 0 is still under way, and the client must neither close nor read
     * application bytes before it is done.
     *
     * @throws SocketException When the client's socket refuses a read
     */
    public function testAHandshakeThatTakesSeveralTurnsFinishesWithoutClosingTheConnection(): void
    {
        [$client, $transport, $peer] = $this->connectTlsPeer();

        $turns = $this->completeHandshake($client, $transport, $peer);

        $this->assertGreaterThan(1, $turns, 'the handshake is expected to need more than one turn of the client');
        $this->assertFalse($transport->needsHandshake());
        $this->assertFalse($client->shouldClose());

        fwrite($peer, 'ping');
        $this->pumpUntil(fn(): bool => $client->received === 'ping', $client);

        $this->assertSame('ping', $client->received);
    }

    /**
     * On an encrypted stream an empty read is "nothing decrypted yet": the connection stays open
     * through it and closes only at the end of the stream.
     *
     * @throws SocketException When the client's socket refuses a read
     */
    public function testAnEmptyReadClosesTheClientOnlyAtTheEndOfTheStream(): void
    {
        [$client, $transport, $peer] = $this->connectTlsPeer();
        $this->completeHandshake($client, $transport, $peer);

        for ($read = 0; $read < 10; $read++) {
            $client->read();
        }
        $this->assertFalse($client->shouldClose(), 'an empty read on a live TLS connection must not close it');

        fwrite($peer, 'still here');
        $this->pumpUntil(fn(): bool => $client->received === 'still here', $client);
        $this->assertSame('still here', $client->received);
        $this->assertFalse($client->shouldClose());

        fclose($peer);
        $this->pumpUntil(fn(): bool => $client->shouldClose(), $client);

        $this->assertTrue($client->shouldClose(), 'the end of the stream must close the client');
    }

    /**
     * A write the stream takes only in part, or not at all, keeps the rest for a later turn, and
     * every byte reaches the peer once it reads again.
     *
     * @throws SocketException When the client's socket refuses a read or a write
     */
    public function testAPartialWriteIsFinishedOnALaterTurnAndZeroBytesTakenIsNoError(): void
    {
        [$client, $transport, $peer] = $this->connectTlsPeer();
        $this->completeHandshake($client, $transport, $peer);

        $payload = str_repeat('0123456789abcdef', intdiv(self::LARGE_PAYLOAD_BYTES, 16));
        $client->queue($payload);

        $nothingTaken = false;
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while (!$nothingTaken && microtime(true) < $deadline) {
            $pendingBefore = strlen($client->pendingOutbound());
            $client->write();
            $nothingTaken = strlen($client->pendingOutbound()) === $pendingBefore;
        }

        $this->assertTrue($nothingTaken, 'a peer that does not read must make the stream take zero bytes');
        $this->assertFalse($client->shouldClose(), 'zero bytes taken is not a failure');
        $this->assertNotSame('', $client->pendingOutbound());

        $delivered = '';
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while (strlen($delivered) < strlen($payload) && microtime(true) < $deadline) {
            $client->write();
            $delivered .= (string)fread($peer, self::PEER_READ_BYTES);
        }

        $this->assertSame('', $client->pendingOutbound());
        $this->assertSame(strlen($payload), strlen($delivered));
        $this->assertSame(md5($payload), md5($delivered));
        $this->assertFalse($client->shouldClose());
    }

    /**
     * A peer that does not speak TLS is refused, and gets nothing - not even the answer the
     * client had already queued.
     *
     * @throws SocketException When the client's socket refuses a read or a write
     */
    public function testARefusedHandshakeClosesTheConnectionWithoutWritingAByte(): void
    {
        [$client, , $peer] = $this->connectPeer(null);
        $client->queue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        fwrite($peer, "GET /test/health HTTP/1.1\r\nHost: stand-gateway\r\n\r\n");
        $this->pumpUntil(fn(): bool => $client->shouldClose(), $client);
        $client->write();

        $this->assertTrue($client->shouldClose(), 'a refused handshake must close the connection');

        $client->close();
        $arrived = '';
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while (!feof($peer) && microtime(true) < $deadline) {
            $arrived .= (string)fread($peer, self::PEER_READ_BYTES);
        }

        $this->assertSame('', $arrived, 'the refused peer must receive no byte at all');
    }

    public function testAHandshakeRefusalNamedToACallerCarriesItsReason(): void
    {
        $exception = new SocketTlsHandshakeException('certificate verify failed');

        $this->assertInstanceOf(SocketException::class, $exception);
        $this->assertSame('Socket TLS handshake failed: certificate verify failed', $exception->getMessage());
    }

    /**
     * Connects a verifying TLS peer to a client created with the server side of TLS.
     *
     * @return array{0: TlsSocketTransportTestClient, 1: TlsSocketTransport, 2: resource} Client, its transport, peer
     */
    private function connectTlsPeer(): array
    {
        [$caFile, $bundleFile] = $this->issueSelfSignedCertificate();

        return $this->connectPeer([$caFile, $bundleFile]);
    }

    /**
     * Accepts one connection the way AbstractServer does and wraps it in the TLS transport.
     *
     * @param ?array{0: string, 1: string} $certificate CA file the peer trusts and bundle the server presents,
     *                                                  or null for a plain TCP peer facing a throwaway certificate
     * @return array{0: TlsSocketTransportTestClient, 1: TlsSocketTransport, 2: resource} Client, its transport, peer
     */
    private function connectPeer(?array $certificate): array
    {
        [$caFile, $bundleFile] = $certificate ?? $this->issueSelfSignedCertificate();

        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $listener);
        $this->listeners[] = $listener;
        $this->assertTrue(socket_bind($listener, '127.0.0.1', 0));
        $this->assertTrue(socket_listen($listener));
        $this->assertTrue(socket_set_nonblock($listener));
        $this->assertTrue(socket_getsockname($listener, $address, $port));

        $context = stream_context_create([
            'ssl' => [
                'cafile' => $caFile,
                'peer_name' => '127.0.0.1',
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $peer = stream_socket_client(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            self::EXCHANGE_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        $this->assertIsResource($peer, $errstr);
        $this->peers[] = $peer;
        stream_set_blocking($peer, false);

        $accepted = false;
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while ($accepted === false && microtime(true) < $deadline) {
            $accepted = @socket_accept($listener);
        }
        $this->assertInstanceOf(Socket::class, $accepted, 'the listener did not accept the peer');
        $this->assertTrue(socket_set_nonblock($accepted));

        $transport = TlsSocketTransport::accepting($accepted, $bundleFile);

        return [new TlsSocketTransportTestClient($accepted, $transport), $transport, $peer];
    }

    /**
     * Steps both handshakes turn about until both ends are done.
     *
     * @param TlsSocketTransportTestClient $client Server end
     * @param TlsSocketTransport $transport Transport of the server end
     * @param resource $peer Client end
     * @return int Turns the server end took, counting the one that finished
     * @throws SocketException When the client's socket refuses a read
     */
    private function completeHandshake(TlsSocketTransportTestClient $client, TlsSocketTransport $transport, $peer): int
    {
        $peerDone = false;
        $turns = 0;
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;

        while ((!$peerDone || $transport->needsHandshake()) && microtime(true) < $deadline) {
            if (!$peerDone) {
                $step = stream_socket_enable_crypto($peer, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->assertNotFalse($step, 'the peer refused the handshake');
                $peerDone = $step === true;
            }

            if ($transport->needsHandshake()) {
                $client->read();
                $turns++;
                $this->assertFalse($client->shouldClose(), 'a handshake still under way must not close the client');
            }
        }

        $this->assertTrue($peerDone, 'the peer handshake did not finish in time');
        $this->assertFalse($transport->needsHandshake(), 'the server handshake did not finish in time');

        return $turns;
    }

    /**
     * Reads the client until the condition holds or the exchange runs out of time.
     *
     * @param callable(): bool $condition Condition to wait for
     * @param TlsSocketTransportTestClient $client Client to read
     * @throws SocketException When the client's socket refuses a read
     */
    private function pumpUntil(callable $condition, TlsSocketTransportTestClient $client): void
    {
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while (!$condition() && microtime(true) < $deadline) {
            $client->read();
            usleep(1000);
        }
    }

    /**
     * Issues a self-signed certificate for 127.0.0.1, so the tests need no fixture on disk.
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
            $this->writeTemporaryPem('hilos-tls-transport-ca', $certificatePem),
            $this->writeTemporaryPem('hilos-tls-transport-server', $certificatePem . $keyPem),
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
}

/**
 * The smallest client there is: it keeps what it read and sends what it is given.
 */
final class TlsSocketTransportTestClient extends AbstractClient
{
    /** @var string Every application byte read so far */
    public string $received = '';

    /**
     * Queues bytes for the next write.
     *
     * @param string $bytes Bytes to send
     */
    public function queue(string $bytes): void
    {
        $this->writeBuffer .= $bytes;
    }

    /**
     * @return string Bytes queued and not yet taken by the transport
     */
    public function pendingOutbound(): string
    {
        return $this->writeBuffer;
    }

    /**
     * Nothing to do on a tick.
     */
    public function onTick(): void
    {
    }

    /**
     * Moves everything read into {@see self::$received}.
     */
    protected function processReadBuffer(): void
    {
        $this->received .= $this->readBuffer;
        $this->readBuffer = '';
    }

    /**
     * Nothing to announce on close.
     */
    protected function onClose(): void
    {
    }
}
