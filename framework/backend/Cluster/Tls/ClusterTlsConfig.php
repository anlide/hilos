<?php

declare(strict_types=1);

namespace Hilos\Cluster\Tls;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use OpenSSLCertificate;

/**
 * The two files a clustered node's peer channel is verified with, checked once at start (HIL-1034).
 *
 * Every link between nodes is mutual TLS: a node presents its own certificate, whose common name
 * is its node id, and accepts a neighbour only when the neighbour's certificate is signed by an
 * authority in the trust file. Both files are checked here, before the peer port opens, and the
 * first check that fails stops the start with its reason - a node that started and then quietly
 * stayed out of the cluster would be worse than one that did not start and said why.
 *
 * The checks run in this order, and the order is part of the answer: both files read as PEM; the
 * node file carries the private key of its certificate; the certificate names this node; it has
 * not expired; an authority of the trust file signed it for both server and client use. Expiry
 * stands before the chain on purpose - the chain of an expired certificate does not verify
 * either, and would name the wrong reason.
 */
final readonly class ClusterTlsConfig
{
    /** Days before its end date from which a node certificate is warned about at start */
    private const int EXPIRY_WARNING_DAYS = 30;

    /** Format of a date told to the operator */
    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * @param string $certificateFile PEM file holding this node's certificate followed by its private key
     * @param string $trustFile PEM file holding the certificates of the authorities this node trusts
     */
    public function __construct(
        public string $certificateFile,
        public string $trustFile,
    ) {
    }

    /**
     * Reads both file paths from the environment and checks the files behind them.
     *
     * Called only for a clustered node, so an empty value is a configuration error rather than a
     * plain channel: there is no open mode for the peer port. Under
     * {@see EXPIRY_WARNING_DAYS} days to its end date the certificate is warned about, and the
     * node starts.
     *
     * @param NodeIdentity $identity Local node identity, whose node id the certificate must carry
     * @return self Checked file paths
     * @throws ClusterConfigurationException When a file is missing, unreadable, or its certificate is refused
     * @throws EnvException When a TLS env value cannot be read
     */
    public static function fromEnv(NodeIdentity $identity): self
    {
        $certificateFile = self::requirePath(EnvConstants::CLUSTER_TLS_CERT_FILE);
        $trustFile = self::requirePath(EnvConstants::CLUSTER_TLS_CA_FILE);

        [$certificatePem, $certificate] = self::readCertificate(EnvConstants::CLUSTER_TLS_CERT_FILE, $certificateFile);
        self::readCertificate(EnvConstants::CLUSTER_TLS_CA_FILE, $trustFile);

        // A file without a key warns here; false is refused on the next line.
        // warning-suppressed: false becomes ClusterConfigurationException::tlsKeyMismatch() below
        $key = @openssl_pkey_get_private($certificatePem);
        if ($key === false || !openssl_x509_check_private_key($certificate, $key)) {
            throw ClusterConfigurationException::tlsKeyMismatch($certificateFile);
        }

        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            throw ClusterConfigurationException::tlsFileUnreadable(EnvConstants::CLUSTER_TLS_CERT_FILE->name, $certificateFile);
        }

        $certificateName = $parsed['subject']['CN'] ?? null;
        if (!is_string($certificateName) || $certificateName !== $identity->nodeId) {
            throw ClusterConfigurationException::tlsNameMismatch(is_string($certificateName) ? $certificateName : null, $identity->nodeId);
        }

        $validTo = (int)$parsed['validTo_time_t'];
        $now = time();
        if ($validTo < $now) {
            throw ClusterConfigurationException::tlsExpired(gmdate(self::DATE_FORMAT, $validTo));
        }

        foreach ([X509_PURPOSE_SSL_SERVER, X509_PURPOSE_SSL_CLIENT] as $purpose) {
            if (openssl_x509_checkpurpose($certificate, $purpose, [$trustFile]) !== true) {
                throw ClusterConfigurationException::tlsNotTrusted();
            }
        }

        if ($validTo - $now < self::EXPIRY_WARNING_DAYS * TimeConstants::SECONDS_PER_DAY) {
            Logger::warning(sprintf(
                "Cluster TLS certificate of node '%s' expires on %s; issue a new one with %s",
                $identity->nodeId,
                gmdate(self::DATE_FORMAT, $validTo),
                CliCommands::CLUSTER_TLS_ISSUE,
            ));
        }

        return new self($certificateFile, $trustFile);
    }

    /**
     * Reads one file path out of the environment.
     *
     * @param EnvConstants $env Variable naming the file
     * @return string Path, trimmed
     * @throws ClusterConfigurationException When the variable is empty
     * @throws EnvException When the env value cannot be read
     */
    private static function requirePath(EnvConstants $env): string
    {
        $path = trim(Hilos::$env[$env]->string());
        if ($path === '') {
            throw ClusterConfigurationException::missingField($env->name);
        }

        return $path;
    }

    /**
     * Reads a PEM file and the first certificate in it.
     *
     * @param EnvConstants $env Variable that named the file, for the refusal
     * @param string $path Path of the file
     * @return array{0: string, 1: OpenSSLCertificate} PEM text of the file and its first certificate
     * @throws ClusterConfigurationException When the file cannot be read or holds no certificate
     */
    private static function readCertificate(EnvConstants $env, string $path): array
    {
        try {
            $pem = FsPath::read($path);
        } catch (FsException) {
            throw ClusterConfigurationException::tlsFileUnreadable($env->name, $path);
        }

        // A text that is not a certificate warns here; false is refused on the next line.
        // warning-suppressed: false becomes ClusterConfigurationException::tlsFileUnreadable() below
        $certificate = @openssl_x509_read($pem);
        if ($certificate === false) {
            throw ClusterConfigurationException::tlsFileUnreadable($env->name, $path);
        }

        return [$pem, $certificate];
    }
}
