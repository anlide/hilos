<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Exception\InvalidArgumentException;

/**
 * One operation a project protects with a fresh proof of identity (HIL-495).
 *
 * The key is stable storage and wire vocabulary. The label names the operation on the
 * administration screen, while the purpose completes the confirmation copy. An operation
 * whose own first step proves the account address may suppress an identical step-up code.
 */
final readonly class StepUpOperation
{
    /** Operation-key grammar shared by the registry, setting and wire. */
    private const string KEY_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * @param string $key Stable operation key
     * @param string $label Administration-screen label
     * @param string $purpose Phrase completing "To ..."
     * @param bool $opensWithAddressCode Whether the operation itself first proves the account address
     * @throws InvalidArgumentException When the operation key is empty or malformed
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $purpose,
        public bool $opensWithAddressCode,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid step-up operation key: {$key}");
        }
    }
}
