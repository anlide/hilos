<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Environment\Exception\EnvException;

/**
 * VerificationDeliverer - transport seam for a freshly issued verification code.
 *
 * The verification service (HIL-365) mints a challenge and hands its plaintext to a
 * deliverer exactly once, at issue time. The framework ships a dev-stub
 * ({@see LogVerificationDeliverer}); the real email/SMS channel is supplied by
 * the Notifications delivery leaf (HIL-197), which swaps the framework default
 * without the service caring how the secret reaches the user.
 *
 * What travels is a {@see VerificationDeliverable} rather than a bare string, because
 * one letter may carry two secrets (HIL-606): magic-link sign-in delivers a clickable
 * URL and the code a person on a second device types instead.
 */
interface VerificationDeliverer
{
    /**
     * Delivers a freshly issued challenge to its target identifier.
     *
     * @param string $identifier Normalized target (lowercased email) the challenge was issued for
     * @param string $type Verification type (see VerificationType)
     * @param VerificationDeliverable $deliverable Plaintext content of the letter or message
     * @throws EnvException When the transport cannot read the env it shards the target by
     * @throws ValidationException When the challenge was issued for a blank target
     * @throws InvalidArgumentException When the transport's send signal cannot be named or queued
     */
    public function deliver(string $identifier, string $type, VerificationDeliverable $deliverable): void;
}
