<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

/**
 * URL-safe, unpadded base64 codec (RFC 4648 §5) for WebAuthn binary fields (HIL-284).
 *
 * WebAuthn carries every binary value — challenge, credential id, attestation
 * object, authenticator data, signature — as base64url over the wire and inside
 * clientDataJSON. The whole subsystem (challenge signer, verifiers) shares this
 * one codec so encoding rules cannot drift between the mint and the check.
 */
final class Base64Url
{
    /**
     * @param string $value Raw bytes to encode
     * @return string URL-safe, unpadded base64
     */
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decodes URL-safe base64, rejecting any input that is not strictly valid.
     *
     * @param string $value URL-safe base64 to decode
     * @return ?string Decoded bytes, or null when the input is not valid base64url
     */
    public static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
