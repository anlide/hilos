<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Utils\Helpers\JsonHelper;

/**
 * The parsed and checked clientDataJSON of a WebAuthn ceremony (HIL-284).
 *
 * clientDataJSON is the browser-authored context the authenticator signs over:
 * the ceremony {@see $type} (`webauthn.create` for register, `webauthn.get` for
 * login), the base64url {@see $challenge} echoed back from the server, and the
 * {@see $origin} the ceremony ran on. {@see assertValid()} checks all three
 * against the expected type, the challenge recovered from the signed token, and
 * the configured allowed origins — the binding that stops a signature captured
 * for one ceremony/origin from being replayed into another.
 */
final class ClientData
{
    public const string TYPE_CREATE = 'webauthn.create';
    public const string TYPE_GET = 'webauthn.get';

    private const string KEY_TYPE = 'type';
    private const string KEY_CHALLENGE = 'challenge';
    private const string KEY_ORIGIN = 'origin';

    /**
     * @param string $type Ceremony type string
     * @param string $challenge base64url challenge echoed by the client
     * @param string $origin Origin the ceremony ran on
     */
    private function __construct(
        public readonly string $type,
        public readonly string $challenge,
        public readonly string $origin,
    ) {
    }

    /**
     * Parses raw clientDataJSON bytes into their fields.
     *
     * @param string $json Raw clientDataJSON bytes
     * @return self Parsed client data
     * @throws WebAuthnVerificationException When the JSON is invalid or a required field is missing
     */
    public static function parse(string $json): self
    {
        $decoded = JsonHelper::tryDecode($json);
        if ($decoded === null) {
            throw new WebAuthnVerificationException('clientDataJSON is not valid JSON');
        }

        $type = $decoded[self::KEY_TYPE] ?? null;
        $challenge = $decoded[self::KEY_CHALLENGE] ?? null;
        $origin = $decoded[self::KEY_ORIGIN] ?? null;
        if (!is_string($type) || !is_string($challenge) || !is_string($origin)) {
            throw new WebAuthnVerificationException('clientDataJSON is missing required fields');
        }

        return new self($type, $challenge, $origin);
    }

    /**
     * Asserts the client data matches the expected ceremony type, challenge and an allowed origin.
     *
     * @param WebAuthnConfig $config Resolved WebAuthn configuration (allowed origins)
     * @param string $expectedType Expected ceremony type (TYPE_CREATE | TYPE_GET)
     * @param string $expectedChallenge base64url challenge recovered from the signed token
     * @throws WebAuthnVerificationException When the type, challenge or origin does not match
     */
    public function assertValid(WebAuthnConfig $config, string $expectedType, string $expectedChallenge): void
    {
        if (!hash_equals($expectedType, $this->type)) {
            throw new WebAuthnVerificationException('clientDataJSON has an unexpected ceremony type');
        }

        if (!hash_equals($expectedChallenge, $this->challenge)) {
            throw new WebAuthnVerificationException('clientDataJSON challenge does not match');
        }

        if (!$config->isAllowedOrigin($this->origin)) {
            throw new WebAuthnVerificationException('clientDataJSON origin is not allowed');
        }
    }
}
