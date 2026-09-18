<?php

declare(strict_types=1);

namespace Hilos\Cluster\Tls;

use Hilos\Cluster\Exception\ClusterCertificateException;
use Hilos\Constants\TimeConstants;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Utils\Helpers\RandomHelper;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Random\RandomException;

/**
 * Issues the certificates a cluster's peer channel is verified with: one authority per cluster,
 * one certificate per node, carrying the node id as its common name (HIL-1034).
 *
 * Everything here is PEM in and PEM out. The authority is printed as its certificate followed by
 * its key and stays with the operator; a node certificate is printed the same way and goes to
 * that one node; the trust file every node gets is the authority's certificate alone. Nothing is
 * read from or written to the installation - the only file touched is the temporary OpenSSL
 * configuration that names the extensions, which OpenSSL can take from nowhere else.
 *
 * A node certificate is good for both sides of a link, because every node both accepts and
 * dials: extended key usage serverAuth and clientAuth. It never outlives its authority, and it
 * names that authority by the key identifier, so a trust file may carry the old and the new
 * authority one after the other while one replaces the other.
 */
final class ClusterCertificateIssuer
{
    /** Most characters a certificate's common name holds (the X.509 upper bound ub-common-name) */
    private const int NODE_NAME_MAX_LENGTH = 64;

    /** Common name of every cluster authority */
    private const string AUTHORITY_NAME = 'Hilos cluster CA';

    /** Days an authority, and a node certificate at most, is valid for */
    private const int VALID_DAYS = 3650;

    /** Section of the OpenSSL configuration holding the extensions of an authority */
    private const string AUTHORITY_EXTENSIONS = 'authority';

    /** Section of the OpenSSL configuration holding the extensions of a node certificate */
    private const string NODE_EXTENSIONS = 'node';

    /** Curve of every key issued here */
    private const string CURVE = 'prime256v1';

    /** Digest every certificate is signed with */
    private const string DIGEST = 'sha256';

    /** Format of a date told to the operator */
    private const string DATE_FORMAT = 'Y-m-d';

    /** Bytes of secure randomness a serial number is drawn from */
    private const int SERIAL_BYTES = 8;

    /** Prefix of the temporary OpenSSL configuration file */
    private const string CONFIGURATION_PREFIX = 'hilos-cluster-openssl';

    /** Mode of the temporary OpenSSL configuration file: nobody else has anything to read in it */
    private const int CONFIGURATION_MODE = 0600;

    /**
     * OpenSSL configuration naming the two sets of extensions, the only reason a file is written.
     *
     * The key identifiers are what lets a trust file hold two authorities while one replaces the
     * other: both carry {@see AUTHORITY_NAME}, and without an identifier OpenSSL takes the first
     * authority of that name as the signer and refuses every certificate of the second.
     */
    private const string CONFIGURATION = <<<'CNF'
        [req]
        distinguished_name = subject
        [subject]
        [authority]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash
        [node]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature, keyEncipherment
        extendedKeyUsage = serverAuth, clientAuth
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid

        CNF;

    /**
     * Issues a new cluster authority.
     *
     * @return string PEM of the authority's certificate followed by its private key
     * @throws ClusterCertificateException When OpenSSL fails a step or its configuration cannot be written
     * @throws RandomException When the secure random source refuses a serial number
     */
    public static function issueAuthority(): string
    {
        return self::withConfiguration(static function (array $options): string {
            $key = self::generateKey($options);
            $certificate = self::sign(self::AUTHORITY_NAME, $key, null, $key, self::VALID_DAYS, self::AUTHORITY_EXTENSIONS, $options);

            return self::export($certificate, $key, $options);
        });
    }

    /**
     * Issues the certificate of one node, signed by the given authority.
     *
     * The node certificate is valid for {@see VALID_DAYS} or for what is left of the authority,
     * whichever is shorter: a node that outlived its authority could not be verified anyway.
     *
     * @param string $nodeId Node id the certificate is issued to, the CLUSTER_NODE_ID of that node
     * @param string $authorityPem PEM of the authority's certificate followed by its private key
     * @return string PEM of the node certificate followed by its private key
     * @throws ClusterCertificateException When the node id or the authority is refused, or OpenSSL fails a step
     * @throws RandomException When the secure random source refuses a serial number
     */
    public static function issueNode(string $nodeId, string $authorityPem): string
    {
        $name = trim($nodeId);
        if ($name === '') {
            throw ClusterCertificateException::emptyNodeName();
        }
        if (mb_strlen($name) > self::NODE_NAME_MAX_LENGTH) {
            throw ClusterCertificateException::nodeNameTooLong($name, self::NODE_NAME_MAX_LENGTH);
        }

        [$authority, $authorityKey] = self::readAuthority($authorityPem);
        $validDays = min(self::VALID_DAYS, self::daysLeft($authority));

        return self::withConfiguration(static function (array $options) use ($name, $authority, $authorityKey, $validDays): string {
            $key = self::generateKey($options);
            $certificate = self::sign($name, $key, $authority, $authorityKey, $validDays, self::NODE_EXTENSIONS, $options);

            return self::export($certificate, $key, $options);
        });
    }

    /**
     * Gives the authority's certificate without its key: the trust file every node gets.
     *
     * @param string $authorityPem PEM of the authority's certificate followed by its private key
     * @return string PEM of the authority's certificate alone
     * @throws ClusterCertificateException When the authority is refused or its certificate cannot be exported
     */
    public static function trustOf(string $authorityPem): string
    {
        [$authority] = self::readAuthority($authorityPem);

        $certificatePem = '';
        if (!openssl_x509_export($authority, $certificatePem)) {
            throw ClusterCertificateException::openSslFailed('export the authority certificate', self::lastOpenSslError());
        }

        return $certificatePem;
    }

    /**
     * Reads an authority: its certificate and the private key that fits it.
     *
     * @param string $authorityPem PEM of the authority's certificate followed by its private key
     * @return array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey} Certificate and key of the authority
     * @throws ClusterCertificateException When the PEM holds no certificate, no key, or a key of another certificate
     */
    private static function readAuthority(string $authorityPem): array
    {
        // A text that is not a certificate warns here; false is refused on the next line.
        // warning-suppressed: false becomes ClusterCertificateException::authorityUnreadable() below
        $certificate = @openssl_x509_read($authorityPem);
        // warning-suppressed: false becomes ClusterCertificateException::authorityUnreadable() below
        $key = @openssl_pkey_get_private($authorityPem);

        if ($certificate === false || $key === false || !openssl_x509_check_private_key($certificate, $key)) {
            throw ClusterCertificateException::authorityUnreadable();
        }

        return [$certificate, $key];
    }

    /**
     * Counts the whole days an authority can still sign for.
     *
     * @param OpenSSLCertificate $authority Authority certificate
     * @return int Whole days left, at least one
     * @throws ClusterCertificateException When less than one day is left
     */
    private static function daysLeft(OpenSSLCertificate $authority): int
    {
        $parsed = openssl_x509_parse($authority);
        if ($parsed === false) {
            throw ClusterCertificateException::authorityUnreadable();
        }

        $validTo = (int)$parsed['validTo_time_t'];
        $daysLeft = intdiv($validTo - time(), TimeConstants::SECONDS_PER_DAY);
        if ($daysLeft < 1) {
            throw ClusterCertificateException::authorityExpired(gmdate(self::DATE_FORMAT, $validTo));
        }

        return $daysLeft;
    }

    /**
     * Runs one issue with the OpenSSL configuration written to a temporary file, and removes it after.
     *
     * @template TResult
     * @param callable(array{config: string, digest_alg: string}): TResult $issue Issue to run with the OpenSSL options
     * @return TResult What the issue returned
     * @throws ClusterCertificateException When the configuration cannot be written, or the issue refuses
     * @throws RandomException When the secure random source refuses a serial number
     */
    private static function withConfiguration(callable $issue): mixed
    {
        try {
            $configuration = FsPath::createTempFile(self::CONFIGURATION_PREFIX, self::CONFIGURATION_MODE);
            FsPath::write($configuration, self::CONFIGURATION);
        } catch (FsException $failure) {
            throw ClusterCertificateException::configurationUnwritable($failure);
        }

        try {
            return $issue(['config' => $configuration, 'digest_alg' => self::DIGEST]);
        } finally {
            try {
                FsPath::delete($configuration);
            } catch (FileDeleteException) {
                // best-effort cleanup: the file names two extension sections and nothing secret
            }
        }
    }

    /**
     * Generates a fresh key on the cluster's curve.
     *
     * @param array{config: string, digest_alg: string} $options OpenSSL options
     * @return OpenSSLAsymmetricKey New private key
     * @throws ClusterCertificateException When OpenSSL cannot generate it
     */
    private static function generateKey(array $options): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => self::CURVE] + $options);
        if ($key === false) {
            throw ClusterCertificateException::openSslFailed('generate a key', self::lastOpenSslError());
        }

        return $key;
    }

    /**
     * Builds and signs one certificate.
     *
     * @param string $commonName Common name of the certificate
     * @param OpenSSLAsymmetricKey $key Key the certificate is issued for
     * @param ?OpenSSLCertificate $issuer Certificate of the signer, null for a self-signed authority
     * @param OpenSSLAsymmetricKey $issuerKey Key of the signer
     * @param int $validDays Days the certificate is valid for
     * @param string $extensions Section of the OpenSSL configuration holding its extensions
     * @param array{config: string, digest_alg: string} $options OpenSSL options
     * @return OpenSSLCertificate Signed certificate
     * @throws ClusterCertificateException When OpenSSL cannot build or sign it
     * @throws RandomException When the secure random source refuses a serial number
     */
    private static function sign(
        string $commonName,
        OpenSSLAsymmetricKey $key,
        ?OpenSSLCertificate $issuer,
        OpenSSLAsymmetricKey $issuerKey,
        int $validDays,
        string $extensions,
        array $options,
    ): OpenSSLCertificate {
        $request = openssl_csr_new(['commonName' => $commonName], $key, $options);
        if ($request === false || $request === true) {
            throw ClusterCertificateException::openSslFailed('build a certificate request', self::lastOpenSslError());
        }

        $certificate = openssl_csr_sign(
            $request,
            $issuer,
            $issuerKey,
            $validDays,
            $options + ['x509_extensions' => $extensions],
            self::serialNumber(),
        );
        if ($certificate === false) {
            throw ClusterCertificateException::openSslFailed('sign a certificate', self::lastOpenSslError());
        }

        return $certificate;
    }

    /**
     * Writes a certificate and its key out as one PEM, the certificate first.
     *
     * @param OpenSSLCertificate $certificate Certificate
     * @param OpenSSLAsymmetricKey $key Its private key
     * @param array{config: string, digest_alg: string} $options OpenSSL options
     * @return string PEM of the certificate followed by its private key
     * @throws ClusterCertificateException When OpenSSL cannot export either
     */
    private static function export(OpenSSLCertificate $certificate, OpenSSLAsymmetricKey $key, array $options): string
    {
        $certificatePem = '';
        $keyPem = '';
        if (!openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($key, $keyPem, null, $options)) {
            throw ClusterCertificateException::openSslFailed('export a certificate and its key', self::lastOpenSslError());
        }

        return $certificatePem . $keyPem;
    }

    /**
     * Draws the serial number of a certificate.
     *
     * Two certificates of one authority are told apart by their serial, and one an outsider could
     * predict would let him prepare a collision ahead of the signing, so it comes from the
     * secure source and its refusal travels.
     *
     * @return int Positive serial number
     * @throws RandomException When the secure random source refuses
     */
    private static function serialNumber(): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', RandomHelper::secureBytes(self::SERIAL_BYTES));

        return max(1, $unpacked[1] & PHP_INT_MAX);
    }

    /**
     * Empties OpenSSL's error queue and gives its newest entry.
     *
     * @return ?string Newest OpenSSL error, null when the queue was empty
     */
    private static function lastOpenSslError(): ?string
    {
        $last = null;
        while (($error = openssl_error_string()) !== false) {
            $last = $error;
        }

        return $last;
    }
}
