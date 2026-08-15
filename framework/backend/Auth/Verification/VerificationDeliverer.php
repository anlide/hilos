<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Environment\Exception\EnvException;

/**
 * VerificationDeliverer - transport seam for a freshly issued verification code.
 *
 * The verification service (HIL-365) mints a code and hands the plaintext to a
 * deliverer exactly once, at issue time. The framework ships a dev-stub
 * ({@see LogVerificationDeliverer}); the real email/SMS channel is supplied by
 * the Notifications delivery leaf (HIL-197), which swaps the framework default
 * without the service caring how the code reaches the user.
 */
interface VerificationDeliverer
{
    /**
     * Delivers a plaintext verification code to its target identifier.
     *
     * @param string $identifier Normalized target (lowercased email) the code was issued for
     * @param string $type Verification type (see VerificationType)
     * @param string $code Plaintext code to deliver
     * @throws EnvException When the transport cannot read the env it shards the target by
     * @throws ValidationException When the code was issued for a blank target
     * @throws InvalidArgumentException When the transport's send signal cannot be named or queued
     */
    public function deliver(string $identifier, string $type, string $code): void;
}
