<?php

declare(strict_types=1);

/**
 * Reissues the stand gateway's fixed certificate pair (HIL-921). Run by hand, never at start.
 *
 * The pair lives in the repository on purpose: a certificate issued when the container starts
 * would need a volume shared with every daemon that trusts it and an order of "who issued
 * first", and a restarted gateway would break the trust of a daemon already running. Fixed, it
 * is one file the gateway presents and one the callers trust, and neither moves.
 *
 * One name serves every stack: the gateway is reached by the network alias `stand-gateway` in
 * all of them, so a new demo does not mean a new certificate. `localhost` and 127.0.0.1 are
 * there for the container's own healthcheck.
 *
 * The subjectAltName is not optional: a certificate that names its host only in the CN is
 * rejected by current clients outright, and PHP writes the extension only when the signing
 * config names it - which is why a config file is written below instead of passing a CN alone.
 *
 * Usage, from the repository root:
 *   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/hilos -w /hilos php:8.4-cli \
 *       php framework/docker/stand-gateway/tls/issue.php
 *
 * Writes, next to this script:
 *   server.pem  certificate followed by its private key - what the gateway presents
 *   ca.pem      the certificate alone - what a caller trusts
 */

const COMMON_NAME = 'stand-gateway';
const SUBJECT_ALT_NAME = 'DNS:stand-gateway, DNS:localhost, IP:127.0.0.1';
const VALID_DAYS = 36500;
const KEY_BITS = 2048;

$config = tempnam(sys_get_temp_dir(), 'stand-gateway-openssl');
if ($config === false) {
    fwrite(STDERR, "issue: no temporary file for the OpenSSL config\n");
    exit(1);
}

$written = file_put_contents($config, implode("\n", [
    '[req]',
    'distinguished_name = subject',
    '[subject]',
    '[server]',
    'subjectAltName = ' . SUBJECT_ALT_NAME,
    'basicConstraints = critical, CA:FALSE',
    'keyUsage = critical, digitalSignature, keyEncipherment',
    'extendedKeyUsage = serverAuth',
    '',
]));

try {
    if ($written === false) {
        throw new RuntimeException('the OpenSSL config could not be written');
    }

    $options = ['config' => $config, 'digest_alg' => 'sha256'];

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => KEY_BITS] + $options);
    if ($key === false) {
        throw new RuntimeException('the private key could not be generated: ' . openssl_error_string());
    }

    $request = openssl_csr_new(['commonName' => COMMON_NAME], $key, $options + ['req_extensions' => 'server']);
    if ($request === false) {
        throw new RuntimeException('the certificate request could not be built: ' . openssl_error_string());
    }

    $certificate = openssl_csr_sign($request, null, $key, VALID_DAYS, $options + ['x509_extensions' => 'server'], random_int(1, PHP_INT_MAX));
    if ($certificate === false) {
        throw new RuntimeException('the certificate could not be signed: ' . openssl_error_string());
    }

    if (!openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($key, $keyPem, null, $options)) {
        throw new RuntimeException('the certificate or its key could not be exported: ' . openssl_error_string());
    }

    foreach (['ca.pem' => $certificatePem, 'server.pem' => $certificatePem . $keyPem] as $file => $contents) {
        if (file_put_contents(__DIR__ . '/' . $file, $contents) === false) {
            throw new RuntimeException("{$file} could not be written");
        }
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'issue: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    unlink($config);
}

echo 'issue: wrote ca.pem and server.pem for ' . SUBJECT_ALT_NAME . ', valid ' . VALID_DAYS . " days\n";
