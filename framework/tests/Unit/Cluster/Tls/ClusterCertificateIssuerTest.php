<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Tls;

use Hilos\Cluster\Exception\ClusterCertificateException;
use Hilos\Cluster\Tls\ClusterCertificateIssuer;
use Hilos\Constants\TimeConstants;
use OpenSSLCertificate;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * The certificates the cluster's peer channel is verified with (HIL-1034).
 *
 * The authority must be able to sign, a node certificate must serve both sides of a link and
 * carry the node id, the trust file must carry no key, and nothing issued may outlive the
 * authority that signed it. Each test reads the PEM the issuer printed, the way a node reads it.
 */
final class ClusterCertificateIssuerTest extends TestCase
{
    /** Most characters a certificate name holds, as X.509 bounds it */
    private const int NAME_LIMIT = 64;

    /** Lifetime of the short authority the lifetime test signs with, in days */
    private const int SHORT_AUTHORITY_DAYS = 5;

    /** @var list<string> Temporary files removed after each test */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testTheAuthorityIsACertificateAuthorityPrintedWithItsKey(): void
    {
        $authorityPem = ClusterCertificateIssuer::issueAuthority();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $authorityPem);
        $this->assertStringContainsString('PRIVATE KEY-----', $authorityPem);

        $parsed = $this->parse($authorityPem);
        $this->assertSame('Hilos cluster CA', $parsed['subject']['CN']);
        $this->assertStringContainsString('CA:TRUE', $parsed['extensions']['basicConstraints']);
        $this->assertStringContainsString('Certificate Sign', $parsed['extensions']['keyUsage']);
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testANodeCertificateNamesTheNodeAndServesBothSidesOfALink(): void
    {
        $authorityPem = ClusterCertificateIssuer::issueAuthority();
        $nodePem = ClusterCertificateIssuer::issueNode('m1', $authorityPem);

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $nodePem);
        $this->assertStringContainsString('PRIVATE KEY-----', $nodePem);

        $parsed = $this->parse($nodePem);
        $this->assertSame('m1', $parsed['subject']['CN']);
        $this->assertStringContainsString('CA:FALSE', $parsed['extensions']['basicConstraints']);
        $this->assertStringContainsString('TLS Web Server Authentication', $parsed['extensions']['extendedKeyUsage']);
        $this->assertStringContainsString('TLS Web Client Authentication', $parsed['extensions']['extendedKeyUsage']);

        $key = openssl_pkey_get_private($nodePem);
        $this->assertNotFalse($key, 'the node file must carry its private key');
        $this->assertTrue(openssl_x509_check_private_key($this->certificate($nodePem), $key));
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testANodeCertificateIsTrustedByItsAuthorityOnlyForBothPurposes(): void
    {
        $authorityPem = ClusterCertificateIssuer::issueAuthority();
        $foreignPem = ClusterCertificateIssuer::issueAuthority();
        $node = $this->certificate(ClusterCertificateIssuer::issueNode('m1', $authorityPem));

        $trustFile = $this->writeTemporaryFile(ClusterCertificateIssuer::trustOf($authorityPem));
        $foreignTrustFile = $this->writeTemporaryFile(ClusterCertificateIssuer::trustOf($foreignPem));

        $this->assertTrue(openssl_x509_checkpurpose($node, X509_PURPOSE_SSL_SERVER, [$trustFile]));
        $this->assertTrue(openssl_x509_checkpurpose($node, X509_PURPOSE_SSL_CLIENT, [$trustFile]));
        $this->assertNotTrue(openssl_x509_checkpurpose($node, X509_PURPOSE_SSL_SERVER, [$foreignTrustFile]));
        $this->assertNotTrue(openssl_x509_checkpurpose($node, X509_PURPOSE_SSL_CLIENT, [$foreignTrustFile]));
    }

    /**
     * Every authority carries one name, so while one replaces another the trust file holds two
     * authorities of that name: a node of either is trusted, whichever of them stands first,
     * because the node certificate names its authority by the key identifier.
     *
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testATrustFileOfTwoAuthoritiesTrustsANodeOfEitherWhicheverStandsFirst(): void
    {
        $oldPem = ClusterCertificateIssuer::issueAuthority();
        $newPem = ClusterCertificateIssuer::issueAuthority();
        $nodes = [
            'm1 of the old authority' => ClusterCertificateIssuer::issueNode('m1', $oldPem),
            'm2 of the new authority' => ClusterCertificateIssuer::issueNode('m2', $newPem),
        ];

        $newIdentifier = $this->parse($newPem)['extensions']['subjectKeyIdentifier'];
        $this->assertStringContainsString($newIdentifier, $this->parse($nodes['m2 of the new authority'])['extensions']['authorityKeyIdentifier']);

        $trustFiles = [
            'old first' => ClusterCertificateIssuer::trustOf($oldPem) . ClusterCertificateIssuer::trustOf($newPem),
            'new first' => ClusterCertificateIssuer::trustOf($newPem) . ClusterCertificateIssuer::trustOf($oldPem),
        ];
        foreach ($trustFiles as $order => $trustPem) {
            $trustFile = $this->writeTemporaryFile($trustPem);
            foreach ($nodes as $name => $nodePem) {
                $node = $this->certificate($nodePem);
                $this->assertTrue(openssl_x509_checkpurpose($node, X509_PURPOSE_SSL_SERVER, [$trustFile]), "{$name}, {$order}");
                $this->assertTrue(openssl_x509_checkpurpose($node, X509_PURPOSE_SSL_CLIENT, [$trustFile]), "{$name}, {$order}");
            }
        }
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testTheTrustFileIsTheAuthorityCertificateWithoutItsKey(): void
    {
        $authorityPem = ClusterCertificateIssuer::issueAuthority();

        $trustPem = ClusterCertificateIssuer::trustOf($authorityPem);

        $this->assertStringNotContainsString('PRIVATE KEY', $trustPem);
        $this->assertSame(
            openssl_x509_fingerprint($this->certificate($authorityPem), 'sha256'),
            openssl_x509_fingerprint($this->certificate($trustPem), 'sha256'),
        );
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testANodeCertificateNeverOutlivesItsAuthority(): void
    {
        $authorityPem = $this->issueShortAuthority(self::SHORT_AUTHORITY_DAYS);

        $node = $this->parse(ClusterCertificateIssuer::issueNode('m1', $authorityPem));
        $authority = $this->parse($authorityPem);

        $this->assertLessThanOrEqual($authority['validTo_time_t'], $node['validTo_time_t']);
        $this->assertGreaterThan(
            // What is left of the authority, in whole days, is one day short of its lifetime.
            time() + (self::SHORT_AUTHORITY_DAYS - 2) * TimeConstants::SECONDS_PER_DAY,
            $node['validTo_time_t'],
            'the node keeps what is left of the authority, not ten years and not nothing',
        );
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testAnExpiredAuthoritySignsNothing(): void
    {
        // Zero days ends the authority the second it is issued: no whole day is left to sign for.
        $authorityPem = $this->issueShortAuthority(0);

        $this->expectException(ClusterCertificateException::class);
        $this->expectExceptionMessage('can sign nothing more');

        ClusterCertificateIssuer::issueNode('m1', $authorityPem);
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testANodeNameLongerThanACertificateNameHoldsIsRefused(): void
    {
        $authorityPem = ClusterCertificateIssuer::issueAuthority();

        $longest = str_repeat('n', self::NAME_LIMIT);
        $this->assertSame($longest, $this->parse(ClusterCertificateIssuer::issueNode($longest, $authorityPem))['subject']['CN']);

        $this->expectException(ClusterCertificateException::class);
        $this->expectExceptionMessage(self::NAME_LIMIT . ' characters');

        ClusterCertificateIssuer::issueNode($longest . 'n', $authorityPem);
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testAnEmptyNodeNameIsRefused(): void
    {
        $authorityPem = ClusterCertificateIssuer::issueAuthority();

        $this->expectException(ClusterCertificateException::class);
        $this->expectExceptionMessage('empty');

        ClusterCertificateIssuer::issueNode('  ', $authorityPem);
    }

    /**
     * @throws ClusterCertificateException When the issuer refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    public function testAnAuthorityWithoutItsKeyIsRefused(): void
    {
        $trustPem = ClusterCertificateIssuer::trustOf(ClusterCertificateIssuer::issueAuthority());

        $this->expectException(ClusterCertificateException::class);
        $this->expectExceptionMessage('certificate followed by its private key');

        ClusterCertificateIssuer::issueNode('m1', $trustPem);
    }

    /**
     * Issues an authority of the given lifetime by hand, since the issuer always gives ten years.
     *
     * @param int $days Days the authority is valid for; zero for one that ends as it is issued
     * @return string PEM of the authority certificate followed by its key
     */
    private function issueShortAuthority(int $days): string
    {
        $options = [
            'config' => $this->writeTemporaryFile(implode("\n", [
                '[req]',
                'distinguished_name = subject',
                '[subject]',
                '[authority]',
                'basicConstraints = critical, CA:TRUE',
                'keyUsage = critical, keyCertSign, cRLSign',
                '',
            ])),
            'digest_alg' => 'sha256',
        ];

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $options);
        $this->assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'Short-lived CA'], $key, $options);
        $this->assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, $days, $options + ['x509_extensions' => 'authority'], 7);
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificate);

        $certificatePem = '';
        $keyPem = '';
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem, null, $options));

        return $certificatePem . $keyPem;
    }

    /**
     * @param string $pem PEM holding a certificate first
     * @return OpenSSLCertificate The certificate
     */
    private function certificate(string $pem): OpenSSLCertificate
    {
        $certificate = openssl_x509_read($pem);
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificate);

        return $certificate;
    }

    /**
     * @param string $pem PEM holding a certificate first
     * @return array<string, mixed> What openssl_x509_parse() reads out of it
     */
    private function parse(string $pem): array
    {
        $parsed = openssl_x509_parse($this->certificate($pem));
        $this->assertIsArray($parsed);

        return $parsed;
    }

    /**
     * Writes one file for the duration of the test.
     *
     * @param string $contents File contents
     * @return string Path of the written file
     */
    private function writeTemporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'hilos-cluster-issuer');
        $this->assertIsString($file);
        $this->temporaryFiles[] = $file;
        $this->assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }
}
