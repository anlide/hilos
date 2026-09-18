<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Transport\TlsSocketTransport;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Both sides of TLS verifying each other over sockets the sockets extension carries (HIL-1034).
 *
 * A transport given a trust file vouches for the name in its peer's certificate and names every
 * refused handshake; one without it verifies nobody and asks for no client certificate. Each test
 * puts the two sides on one connection inside this process - both ends exported sockets, stepped
 * turn about without blocking - and reads what each end learned.
 *
 * The authorities and certificates are issued in the test, so nothing is read from disk but the
 * temporary files the test itself wrote.
 */
final class TlsSocketTransportMutualTest extends TestCase
{
    /** Time any one exchange below is given before the test gives up on it */
    private const float EXCHANGE_SECONDS = 5.0;

    /** Days every certificate issued here is valid for */
    private const int VALID_DAYS = 1;

    /** Pause between two turns of the handshake, in microseconds */
    private const int TURN_PAUSE_MICROSECONDS = 500;

    /** Name of the dialing end in the result of a handshake */
    private const string DIALING = 'dialing';

    /** Name of the accepting end in the result of a handshake */
    private const string ACCEPTING = 'accepting';

    /** @var list<string> Temporary files removed after each test */
    private array $temporaryFiles = [];

    /** @var list<Socket> Sockets closed after each test */
    private array $sockets = [];

    /** @var ?string OpenSSL configuration naming the extensions of an authority and of a node */
    private ?string $configFile = null;

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            // The transport may have closed it already.
            @socket_close($socket);
        }

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * Two nodes of one authority finish the handshake, each vouches for the other's name, and
     * bytes cross in both directions.
     */
    public function testBothSidesVerifyingFinishTheHandshakeAndVouchForEachOthersName(): void
    {
        $authority = $this->issueAuthority('Hilos cluster CA');
        $trustFile = $this->trustFile($authority);
        [$acceptedSocket, $dialedSocket] = $this->connectSockets();

        $accepting = TlsSocketTransport::accepting($acceptedSocket, $this->issueNode('m1', $authority), $trustFile);
        $dialing = TlsSocketTransport::dialing($dialedSocket, $this->issueNode('m2', $authority), $trustFile);

        $this->assertNull($accepting->verifiedPeerName(), 'no name is vouched for before the handshake finishes');
        $this->assertNull($dialing->verifiedPeerName(), 'no name is vouched for before the handshake finishes');

        $refusal = $this->runHandshake([
            self::DIALING => fn(): int|bool => $dialing->advanceHandshake(),
            self::ACCEPTING => fn(): int|bool => $accepting->advanceHandshake(),
        ]);

        $this->assertNull($refusal, 'two nodes of one authority must not refuse each other');
        $this->assertFalse($accepting->needsHandshake());
        $this->assertFalse($dialing->needsHandshake());
        $this->assertSame('m2', $accepting->verifiedPeerName());
        $this->assertSame('m1', $dialing->verifiedPeerName());

        $this->assertSame(4, $dialing->write('ping'));
        $this->assertSame('ping', $this->readExactly($accepting, 4));
        $this->assertSame(4, $accepting->write('pong'));
        $this->assertSame('pong', $this->readExactly($dialing, 4));
    }

    /**
     * A dialer that presents no certificate at all is refused by the verifying accepting side,
     * and the refusal names the dialer's address and OpenSSL's reason.
     */
    public function testAVerifyingAcceptorNamesADialerWithoutACertificate(): void
    {
        $authority = $this->issueAuthority('Hilos cluster CA');
        $trustFile = $this->trustFile($authority);
        [$acceptedSocket, $dialedSocket] = $this->connectSockets();

        $accepting = TlsSocketTransport::accepting($acceptedSocket, $this->issueNode('m1', $authority), $trustFile);
        $dialer = $this->exportBareDialer($dialedSocket, $trustFile);

        $refusal = $this->runHandshake([
            self::DIALING => fn(): int|bool => stream_socket_enable_crypto($dialer, true, STREAM_CRYPTO_METHOD_TLS_CLIENT),
            self::ACCEPTING => fn(): int|bool => $accepting->advanceHandshake(),
        ]);

        $this->assertNotNull($refusal, 'a dialer without a certificate must be refused');
        [$end, $exception] = $refusal;
        $this->assertSame(self::ACCEPTING, $end);
        $this->assertStringStartsWith('Socket TLS handshake failed: 127.0.0.1:', $exception->getMessage());
        $this->assertStringContainsString('Could not get peer certificate', $exception->getMessage());
        $this->assertNull($accepting->verifiedPeerName());
    }

    /**
     * A dialer whose certificate is signed by another authority is refused by the accepting side,
     * which is the only end that learns why: the dialer's own handshake has already finished.
     */
    public function testAVerifyingAcceptorNamesADialerOfAForeignAuthority(): void
    {
        $authority = $this->issueAuthority('Hilos cluster CA');
        $foreign = $this->issueAuthority('Someone else CA');
        $trustFile = $this->trustFile($authority);
        [$acceptedSocket, $dialedSocket] = $this->connectSockets();

        $accepting = TlsSocketTransport::accepting($acceptedSocket, $this->issueNode('m1', $authority), $trustFile);
        $dialing = TlsSocketTransport::dialing($dialedSocket, $this->issueNode('x1', $foreign), $trustFile);

        $refusal = $this->runHandshake([
            self::DIALING => fn(): int|bool => $dialing->advanceHandshake(),
            self::ACCEPTING => fn(): int|bool => $accepting->advanceHandshake(),
        ]);

        $this->assertNotNull($refusal, 'a dialer of a foreign authority must be refused');
        [$end, $exception] = $refusal;
        $this->assertSame(self::ACCEPTING, $end);
        $this->assertStringStartsWith('Socket TLS handshake failed: 127.0.0.1:', $exception->getMessage());
        $this->assertStringContainsString('certificate verify failed', $exception->getMessage());
    }

    /**
     * An accepting side whose certificate is signed by another authority is refused by the
     * dialer, which names the refusal with the address it dialed.
     */
    public function testADialerNamesAnAcceptorOfAForeignAuthority(): void
    {
        $authority = $this->issueAuthority('Hilos cluster CA');
        $foreign = $this->issueAuthority('Someone else CA');
        $trustFile = $this->trustFile($authority);
        [$acceptedSocket, $dialedSocket] = $this->connectSockets();

        $accepting = TlsSocketTransport::accepting($acceptedSocket, $this->issueNode('x1', $foreign), $trustFile);
        $dialing = TlsSocketTransport::dialing($dialedSocket, $this->issueNode('m2', $authority), $trustFile);

        $refusal = $this->runHandshake([
            self::DIALING => fn(): int|bool => $dialing->advanceHandshake(),
            self::ACCEPTING => fn(): int|bool => $accepting->advanceHandshake(),
        ]);

        $this->assertNotNull($refusal, 'an acceptor of a foreign authority must be refused');
        [$end, $exception] = $refusal;
        $this->assertSame(self::DIALING, $end);
        $this->assertStringStartsWith('Socket TLS handshake failed: 127.0.0.1:', $exception->getMessage());
        $this->assertStringContainsString('certificate verify failed', $exception->getMessage());
        $this->assertNull($dialing->verifiedPeerName());
    }

    /**
     * An accepting side without a trust file - a public port - asks no client for a certificate,
     * so a dialer presenting one of a foreign authority still finishes, and nobody is vouched for.
     */
    public function testAnAcceptorWithoutATrustFileAsksForNoCertificateAndVouchesForNobody(): void
    {
        $authority = $this->issueAuthority('Hilos cluster CA');
        $foreign = $this->issueAuthority('Someone else CA');
        [$acceptedSocket, $dialedSocket] = $this->connectSockets();

        $accepting = TlsSocketTransport::accepting($acceptedSocket, $this->issueNode('gateway', $authority));
        $dialing = TlsSocketTransport::dialing($dialedSocket, $this->issueNode('x1', $foreign), $this->trustFile($authority));

        $refusal = $this->runHandshake([
            self::DIALING => fn(): int|bool => $dialing->advanceHandshake(),
            self::ACCEPTING => fn(): int|bool => $accepting->advanceHandshake(),
        ]);

        $this->assertNull($refusal, 'a public port must not ask for, let alone refuse, a client certificate');
        $this->assertFalse($accepting->needsHandshake());
        $this->assertNull($accepting->verifiedPeerName());
        $this->assertSame('gateway', $dialing->verifiedPeerName());
    }

    /**
     * Steps every end of one handshake turn about until all of them finish or one names a refusal.
     *
     * @param array<string, callable(): (int|bool)> $ends Handshake step of each end, keyed by the end's name
     * @return ?array{0: string, 1: SocketTlsHandshakeException} End that named the first refusal and
     *                                                           the refusal, null when every end finished
     */
    private function runHandshake(array $ends): ?array
    {
        $finished = [];
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;

        while (count($finished) < count($ends) && microtime(true) < $deadline) {
            foreach ($ends as $end => $step) {
                if (isset($finished[$end])) {
                    continue;
                }

                try {
                    $result = $step();
                } catch (SocketTlsHandshakeException $refusal) {
                    return [$end, $refusal];
                }

                $this->assertNotFalse($result, "the {$end} end refused the handshake without naming it");
                if ($result === true) {
                    $finished[$end] = true;
                }
            }

            usleep(self::TURN_PAUSE_MICROSECONDS);
        }

        $this->assertCount(count($ends), $finished, 'the handshake did not finish in time');

        return null;
    }

    /**
     * Reads one end until the given number of bytes arrived or the exchange runs out of time.
     *
     * @param TlsSocketTransport $transport End to read
     * @param int $length Bytes expected
     * @return string Bytes read
     */
    private function readExactly(TlsSocketTransport $transport, int $length): string
    {
        $received = '';
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while (strlen($received) < $length && microtime(true) < $deadline) {
            $received .= (string)$transport->read($length - strlen($received));
            usleep(self::TURN_PAUSE_MICROSECONDS);
        }

        return $received;
    }

    /**
     * Connects a non-blocking dialed socket to a non-blocking accepted one, the way the peer mesh does.
     *
     * @return array{0: Socket, 1: Socket} Accepted socket, dialed socket
     */
    private function connectSockets(): array
    {
        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $listener);
        $this->sockets[] = $listener;
        $this->assertTrue(socket_bind($listener, '127.0.0.1', 0));
        $this->assertTrue(socket_listen($listener));
        $this->assertTrue(socket_set_nonblock($listener));
        $this->assertTrue(socket_getsockname($listener, $address, $port));

        $dialed = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $dialed);
        $this->sockets[] = $dialed;
        $this->assertTrue(socket_set_nonblock($dialed));
        // A non-blocking connect answers EINPROGRESS; the accept below is what tells it went through.
        @socket_connect($dialed, '127.0.0.1', $port);

        $accepted = false;
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while ($accepted === false && microtime(true) < $deadline) {
            $accepted = @socket_accept($listener);
        }
        $this->assertInstanceOf(Socket::class, $accepted, 'the listener did not accept the dialed socket');
        $this->sockets[] = $accepted;
        $this->assertTrue(socket_set_nonblock($accepted));

        $read = null;
        $write = [$dialed];
        $except = null;
        $this->assertSame(1, socket_select($read, $write, $except, (int)self::EXCHANGE_SECONDS), 'the dial did not complete');

        return [$accepted, $dialed];
    }

    /**
     * Exports a dialed socket into a TLS client stream that verifies the acceptor but presents no certificate.
     *
     * @param Socket $socket Dialed, non-blocking socket
     * @param string $trustFile Authorities the acceptor's certificate must be signed by
     * @return resource Stream to step the client handshake on
     */
    private function exportBareDialer(Socket $socket, string $trustFile)
    {
        $stream = socket_export_stream($socket);
        $this->assertIsResource($stream);
        stream_set_blocking($stream, false);
        stream_context_set_option($stream, 'ssl', 'cafile', $trustFile);
        stream_context_set_option($stream, 'ssl', 'verify_peer', true);
        stream_context_set_option($stream, 'ssl', 'verify_peer_name', false);

        return $stream;
    }

    /**
     * Issues a self-signed authority.
     *
     * @param string $name Common name of the authority
     * @return array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} Certificate and key of the authority
     */
    private function issueAuthority(string $name): array
    {
        $key = $this->generateKey();
        $request = openssl_csr_new(['commonName' => $name], $key, $this->options());
        $this->assertNotFalse($request, 'authority request could not be generated');

        $certificate = openssl_csr_sign(
            $request,
            null,
            $key,
            self::VALID_DAYS,
            $this->options() + ['x509_extensions' => 'authority'],
            random_int(1, PHP_INT_MAX),
        );
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificate, 'authority could not be signed');

        return [$certificate, $key];
    }

    /**
     * Issues a node certificate signed by the given authority and writes it with its key.
     *
     * @param string $name Common name of the node
     * @param array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} $authority Certificate and key of the signer
     * @return string Path of the PEM file holding the certificate and its key
     */
    private function issueNode(string $name, array $authority): string
    {
        $key = $this->generateKey();
        $request = openssl_csr_new(['commonName' => $name], $key, $this->options());
        $this->assertNotFalse($request, 'node request could not be generated');

        $certificate = openssl_csr_sign(
            $request,
            $authority[0],
            $authority[1],
            self::VALID_DAYS,
            $this->options() + ['x509_extensions' => 'node'],
            random_int(1, PHP_INT_MAX),
        );
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificate, 'node certificate could not be signed');

        $certificatePem = '';
        $keyPem = '';
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem, null, $this->options()));

        return $this->writeTemporaryFile('hilos-tls-mutual-node', $certificatePem . $keyPem);
    }

    /**
     * Writes the certificate of an authority, without its key, as a trust file.
     *
     * @param array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} $authority Certificate and key of the authority
     * @return string Path of the trust file
     */
    private function trustFile(array $authority): string
    {
        $certificatePem = '';
        $this->assertTrue(openssl_x509_export($authority[0], $certificatePem));

        return $this->writeTemporaryFile('hilos-tls-mutual-trust', $certificatePem);
    }

    /**
     * @return OpenSSLAsymmetricKey Fresh EC key on prime256v1
     */
    private function generateKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(
            ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $this->options(),
        );
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key, 'key could not be generated');

        return $key;
    }

    /**
     * Options every OpenSSL call here takes: the configuration naming both extension sections.
     *
     * @return array{config: string, digest_alg: string} OpenSSL options
     */
    private function options(): array
    {
        $this->configFile ??= $this->writeTemporaryFile('hilos-tls-mutual-cnf', implode("\n", [
            '[req]',
            'distinguished_name = subject',
            '[subject]',
            '[authority]',
            'basicConstraints = critical, CA:TRUE',
            'keyUsage = critical, keyCertSign, cRLSign',
            '[node]',
            'basicConstraints = critical, CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            'extendedKeyUsage = serverAuth, clientAuth',
            '',
        ]));

        return ['config' => $this->configFile, 'digest_alg' => 'sha256'];
    }

    /**
     * Writes one file for the duration of the test.
     *
     * @param string $prefix Temporary file name prefix
     * @param string $contents File contents
     * @return string Path of the written file
     */
    private function writeTemporaryFile(string $prefix, string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($file, 'temporary file could not be created');
        $this->temporaryFiles[] = $file;
        $this->assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }
}
