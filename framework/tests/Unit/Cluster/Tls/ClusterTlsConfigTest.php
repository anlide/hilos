<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Tls;

use Hilos\Cluster\Exception\ClusterCertificateException;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Tls\ClusterCertificateIssuer;
use Hilos\Cluster\Tls\ClusterTlsConfig;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * The start-up check of a clustered node's two TLS files (HIL-1034).
 *
 * A node refuses to start on a file it cannot use, naming the first check that failed, and the
 * order of the checks is part of what it says: an expired certificate is called expired, not
 * untrusted. Under thirty days to its end date the certificate is warned about and the node
 * starts. Each test puts the two paths in the environment the way the stack does.
 */
final class ClusterTlsConfigTest extends TestCase
{
    /** Node id every test starts as */
    private const string NODE_ID = 'm1';

    /** Days a certificate is issued for when it must fall inside the warning window */
    private const int EXPIRING_DAYS = 10;

    /** Days a hand-issued certificate is valid for when its lifetime does not matter */
    private const int LONG_DAYS = 365;

    /** Pause between two looks at the clock while a certificate runs out, in microseconds */
    private const int EXPIRY_POLL_MICROSECONDS = 50000;

    /** @var list<string> Environment variables this suite writes */
    private const array ENV_KEYS = ['CLUSTER_TLS_CERT_FILE', 'CLUSTER_TLS_CA_FILE'];

    private ?EnvAccessor $previousEnv = null;

    private string $logFile;

    /** @var list<string> Temporary files removed after each test */
    private array $temporaryFiles = [];

    /** @var ?array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} Authority the hand-issued certificates are signed by */
    private ?array $authority = null;

    /** @var ?string OpenSSL configuration naming the extension sections of the hand-issued certificates */
    private ?string $configFile = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();

        $this->logFile = $this->writeTemporaryFile('');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        Hilos::$env = $this->previousEnv;
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws ClusterCertificateException When the issuer refuses
     * @throws EnvException When a TLS env value cannot be read
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testFilesIssuedForThisNodePassAndWarnOfNothing(): void
    {
        $authorityPem = ClusterCertificateIssuer::issueAuthority();
        $certificateFile = $this->writeTemporaryFile(ClusterCertificateIssuer::issueNode(self::NODE_ID, $authorityPem));
        $trustFile = $this->writeTemporaryFile(ClusterCertificateIssuer::trustOf($authorityPem));
        $this->configure($certificateFile, $trustFile);

        $config = ClusterTlsConfig::fromEnv($this->identity());

        $this->assertSame($certificateFile, $config->certificateFile);
        $this->assertSame($trustFile, $config->trustFile);
        $this->assertStringNotContainsString('expires on', (string)file_get_contents($this->logFile));
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testAnEmptyCertificateFileIsAMissingField(): void
    {
        $this->configure('', $this->trustFile());

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage("required node config 'CLUSTER_TLS_CERT_FILE' is empty");

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testAnEmptyTrustFileIsAMissingField(): void
    {
        $this->configure($this->nodeFile(self::NODE_ID, 'node', self::LONG_DAYS), '');

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage("required node config 'CLUSTER_TLS_CA_FILE' is empty");

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testAFileThatIsNotPemIsUnreadable(): void
    {
        $certificateFile = $this->writeTemporaryFile('not a certificate');
        $this->configure($certificateFile, $this->trustFile());

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage("CLUSTER_TLS_CERT_FILE names '{$certificateFile}', which cannot be read as PEM");

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testATrustFileThatDoesNotExistIsUnreadable(): void
    {
        $missing = sys_get_temp_dir() . '/hilos-cluster-tls-no-such-file.pem';
        $this->configure($this->nodeFile(self::NODE_ID, 'node', self::LONG_DAYS), $missing);

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage("CLUSTER_TLS_CA_FILE names '{$missing}', which cannot be read as PEM");

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testACertificateWithoutItsKeyIsRefused(): void
    {
        $certificateFile = $this->nodeFile(self::NODE_ID, 'node', self::LONG_DAYS, withKey: false);
        $this->configure($certificateFile, $this->trustFile());

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage("The certificate in '{$certificateFile}' has no matching private key beside it");

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testACertificateOfAnotherNodeIsRefused(): void
    {
        $this->configure($this->nodeFile('m2', 'node', self::LONG_DAYS), $this->trustFile());

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage("The node certificate names 'm2', but CLUSTER_NODE_ID is 'm1'");

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * Expiry is checked before the chain: the chain of an expired certificate fails too, and would
     * name the wrong reason.
     *
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testAnExpiredCertificateIsCalledExpiredRatherThanUntrusted(): void
    {
        $certificateFile = $this->nodeFile(self::NODE_ID, 'node', 0);
        $this->waitPastEndOf($certificateFile);
        $this->configure($certificateFile, $this->trustFile());

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessageMatches('/^The node certificate expired on \d{4}-\d{2}-\d{2}; issue a new one with cluster:tls:issue$/');

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws ClusterCertificateException When the issuer refuses
     * @throws EnvException When a TLS env value cannot be read
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testACertificateOfAnotherAuthorityIsNotTrusted(): void
    {
        $foreignPem = ClusterCertificateIssuer::issueAuthority();
        $certificateFile = $this->writeTemporaryFile(ClusterCertificateIssuer::issueNode(self::NODE_ID, $foreignPem));
        $this->configure($certificateFile, $this->trustFile());

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage(
            'The node certificate is not signed for both server and client use by an authority in CLUSTER_TLS_CA_FILE',
        );

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * A node both accepts and dials, so a certificate good for one side only is refused.
     *
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testACertificateForTheServerSideOnlyIsNotTrusted(): void
    {
        $this->configure($this->nodeFile(self::NODE_ID, 'server_only', self::LONG_DAYS), $this->trustFile());

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage('not signed for both server and client use');

        ClusterTlsConfig::fromEnv($this->identity());
    }

    /**
     * @throws ClusterConfigurationException When the check refuses the files
     * @throws EnvException When a TLS env value cannot be read
     */
    public function testACertificateCloseToItsEndIsWarnedAboutAndTheNodeStarts(): void
    {
        $certificateFile = $this->nodeFile(self::NODE_ID, 'node', self::EXPIRING_DAYS);
        $this->configure($certificateFile, $this->trustFile());

        $config = ClusterTlsConfig::fromEnv($this->identity());

        $this->assertSame($certificateFile, $config->certificateFile);
        $this->assertMatchesRegularExpression(
            "/Cluster TLS certificate of node 'm1' expires on \\d{4}-\\d{2}-\\d{2}; issue a new one with cluster:tls:issue/",
            (string)file_get_contents($this->logFile),
        );
    }

    /**
     * @param string $certificateFile Value of CLUSTER_TLS_CERT_FILE
     * @param string $trustFile Value of CLUSTER_TLS_CA_FILE
     */
    private function configure(string $certificateFile, string $trustFile): void
    {
        putenv("CLUSTER_TLS_CERT_FILE={$certificateFile}");
        putenv("CLUSTER_TLS_CA_FILE={$trustFile}");
    }

    /**
     * @return NodeIdentity Identity every test starts as
     */
    private function identity(): NodeIdentity
    {
        return NodeIdentity::of(self::NODE_ID, NodeRole::Master, []);
    }

    /**
     * Writes the certificate of the test's authority as a trust file.
     *
     * @return string Path of the trust file
     */
    private function trustFile(): string
    {
        $certificatePem = '';
        $this->assertTrue(openssl_x509_export($this->authority()[0], $certificatePem));

        return $this->writeTemporaryFile($certificatePem);
    }

    /**
     * Issues a node certificate by hand, for the shapes the issuer never prints.
     *
     * @param string $name Common name of the certificate
     * @param string $extensions Section of the test's OpenSSL configuration holding its extensions
     * @param int $days Days it is valid for; zero for one that ends the second it is issued
     * @param bool $withKey Whether its private key follows it in the file
     * @return string Path of the written file
     */
    private function nodeFile(string $name, string $extensions, int $days, bool $withKey = true): string
    {
        [$authority, $authorityKey] = $this->authority();
        $key = $this->generateKey();
        $request = openssl_csr_new(['commonName' => $name], $key, $this->options());
        $this->assertNotFalse($request);
        $certificate = openssl_csr_sign(
            $request,
            $authority,
            $authorityKey,
            $days,
            $this->options() + ['x509_extensions' => $extensions],
            random_int(1, PHP_INT_MAX),
        );
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificate);

        $certificatePem = '';
        $keyPem = '';
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem, null, $this->options()));

        return $this->writeTemporaryFile($withKey ? $certificatePem . $keyPem : $certificatePem);
    }

    /**
     * Waits until the second a certificate ends in has passed, so it reads as expired.
     *
     * @param string $certificateFile File holding the certificate
     */
    private function waitPastEndOf(string $certificateFile): void
    {
        $parsed = openssl_x509_parse((string)file_get_contents($certificateFile));
        $this->assertIsArray($parsed);

        while (time() <= $parsed['validTo_time_t']) {
            usleep(self::EXPIRY_POLL_MICROSECONDS);
        }
    }

    /**
     * @return array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} Authority of this test, issued once
     */
    private function authority(): array
    {
        if ($this->authority === null) {
            $key = $this->generateKey();
            $request = openssl_csr_new(['commonName' => 'Test cluster CA'], $key, $this->options());
            $this->assertNotFalse($request);
            $certificate = openssl_csr_sign(
                $request,
                null,
                $key,
                self::LONG_DAYS,
                $this->options() + ['x509_extensions' => 'authority'],
                random_int(1, PHP_INT_MAX),
            );
            $this->assertInstanceOf(OpenSSLCertificate::class, $certificate);
            $this->authority = [$certificate, $key];
        }

        return $this->authority;
    }

    /**
     * @return OpenSSLAsymmetricKey Fresh EC key on prime256v1
     */
    private function generateKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $this->options());
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    /**
     * @return array{config: string, digest_alg: string} OpenSSL options naming the test's extension sections
     */
    private function options(): array
    {
        $this->configFile ??= $this->writeTemporaryFile(implode("\n", [
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
            '[server_only]',
            'basicConstraints = critical, CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            'extendedKeyUsage = serverAuth',
            '',
        ]));

        return ['config' => $this->configFile, 'digest_alg' => 'sha256'];
    }

    /**
     * Writes one file for the duration of the test.
     *
     * @param string $contents File contents
     * @return string Path of the written file
     */
    private function writeTemporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'hilos-cluster-tls-config');
        $this->assertIsString($file);
        $this->temporaryFiles[] = $file;
        $this->assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }
}
