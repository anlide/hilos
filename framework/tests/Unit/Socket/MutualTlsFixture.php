<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use PHPUnit\Framework\Assert;
use Socket;
use Error;

/** Certificates and sockets owned by one in-process mutual TLS test. */
final class MutualTlsFixture
{
    private const float EXCHANGE_SECONDS = 5.0;

    private const int VALID_DAYS = 1;

    /** @var list<string> Temporary files removed after each test */
    private array $temporaryFiles = [];

    /** @var list<Socket> Sockets closed after each test */
    private array $sockets = [];

    /** @var ?string OpenSSL configuration naming the extensions of an authority and of a node */
    private ?string $configFile = null;

    /** Releases sockets and temporary certificate files after one test. */
    public function close(): void
    {
        foreach ($this->sockets as $socket) {
            // The transport may have closed it already.
            try {
                @socket_close($socket);
            } catch (Error) {
                // Its transport already invalidated the PHP Socket.
            }
        }

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @return Socket Non-blocking TCP listener on an ephemeral loopback port */
    public function listen(): Socket
    {
        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        Assert::assertInstanceOf(Socket::class, $listener);
        $this->sockets[] = $listener;
        Assert::assertTrue(socket_bind($listener, '127.0.0.1', 0));
        Assert::assertTrue(socket_listen($listener));
        Assert::assertTrue(socket_set_nonblock($listener));

        return $listener;
    }

    /**
     * Connects a non-blocking dialed socket to a non-blocking accepted one, the way the peer mesh does.
     *
     * @return array{0: Socket, 1: Socket} Accepted socket, dialed socket
     */
    public function connectSockets(): array
    {
        $listener = $this->listen();
        Assert::assertTrue(socket_getsockname($listener, $address, $port));

        $dialed = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        Assert::assertInstanceOf(Socket::class, $dialed);
        $this->sockets[] = $dialed;
        Assert::assertTrue(socket_set_nonblock($dialed));
        // A non-blocking connect answers EINPROGRESS; the accept below is what tells it went through.
        @socket_connect($dialed, '127.0.0.1', $port);

        $accepted = false;
        $deadline = microtime(true) + self::EXCHANGE_SECONDS;
        while ($accepted === false && microtime(true) < $deadline) {
            $accepted = @socket_accept($listener);
        }
        Assert::assertInstanceOf(Socket::class, $accepted, 'the listener did not accept the dialed socket');
        $this->sockets[] = $accepted;
        Assert::assertTrue(socket_set_nonblock($accepted));

        $read = null;
        $write = [$dialed];
        $except = null;
        Assert::assertSame(1, socket_select($read, $write, $except, (int)self::EXCHANGE_SECONDS), 'the dial did not complete');

        return [$accepted, $dialed];
    }

    /**
     * Issues a self-signed authority.
     *
     * @param string $name Common name of the authority
     * @return array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} Certificate and key of the authority
     */
    public function issueAuthority(string $name): array
    {
        $key = $this->generateKey();
        $request = openssl_csr_new(['commonName' => $name], $key, $this->options());
        Assert::assertNotFalse($request, 'authority request could not be generated');

        $certificate = openssl_csr_sign(
            $request,
            null,
            $key,
            self::VALID_DAYS,
            $this->options() + ['x509_extensions' => 'authority'],
            random_int(1, PHP_INT_MAX),
        );
        Assert::assertInstanceOf(OpenSSLCertificate::class, $certificate, 'authority could not be signed');

        return [$certificate, $key];
    }

    /**
     * Issues a node certificate signed by the given authority and writes it with its key.
     *
     * @param string $name Common name of the node
     * @param array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} $authority Certificate and key of the signer
     * @return string Path of the PEM file holding the certificate and its key
     */
    public function issueNode(string $name, array $authority): string
    {
        $key = $this->generateKey();
        $request = openssl_csr_new(['commonName' => $name], $key, $this->options());
        Assert::assertNotFalse($request, 'node request could not be generated');

        $certificate = openssl_csr_sign(
            $request,
            $authority[0],
            $authority[1],
            self::VALID_DAYS,
            $this->options() + ['x509_extensions' => 'node'],
            random_int(1, PHP_INT_MAX),
        );
        Assert::assertInstanceOf(OpenSSLCertificate::class, $certificate, 'node certificate could not be signed');

        $certificatePem = '';
        $keyPem = '';
        Assert::assertTrue(openssl_x509_export($certificate, $certificatePem));
        Assert::assertTrue(openssl_pkey_export($key, $keyPem, null, $this->options()));

        return $this->writeTemporaryFile('hilos-tls-mutual-node', $certificatePem . $keyPem);
    }

    /**
     * Writes the certificate of an authority, without its key, as a trust file.
     *
     * @param array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} $authority Certificate and key of the authority
     * @return string Path of the trust file
     */
    public function trustFile(array $authority): string
    {
        $certificatePem = '';
        Assert::assertTrue(openssl_x509_export($authority[0], $certificatePem));

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
        Assert::assertInstanceOf(OpenSSLAsymmetricKey::class, $key, 'key could not be generated');

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
        Assert::assertIsString($file, 'temporary file could not be created');
        $this->temporaryFiles[] = $file;
        Assert::assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }
}
