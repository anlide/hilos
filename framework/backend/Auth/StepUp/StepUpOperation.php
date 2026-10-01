<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Exception\InvalidArgumentException;

/**
 * One operation a project protects with a fresh proof of identity (HIL-495).
 *
 * The key is stable storage and wire vocabulary. The label names the operation on the
 * administration screen, while the purpose completes the confirmation copy. An operation
 * whose own first step proves the account address may suppress an identical step-up code, and
 * one whose own first step asks a code from a connected authenticator app may suppress the
 * step-up that would ask that same code (HIL-1138).
 *
 * Most operations ask until an administrator switches them off. An operation declared off by
 * default - one the real administrator undoes with a single action on returning to the browser -
 * asks only once an administrator switches it on (HIL-1275).
 *
 * An operation that touches the sign-in of the account is closed inside a takeover unless the
 * administrator allowed it; allowed, its confirmation is asked of the ADMINISTRATOR, by their own
 * method, and never of the person whose account it is (HIL-1170). Every other operation of a
 * person's own account stays closed to a takeover whatever the setting says.
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
     * @param bool $opensOnBlockedCard Whether a browser holding the person's block notice may confirm this operation
     * @param bool $passesWithNothingToConfirm Whether an account with no available proof passes without a step
     * @param bool $opensWithSecondFactorProof Whether the operation itself first asks a code from a connected
     *     authenticator app, so an account with one is not asked twice
     * @param bool $enabledByDefault Whether the operation asks until an administrator switches it off (true)
     *     or only once one switches it on (false)
     * @param bool $accountAccess Whether the operation touches the sign-in of the account, so that a takeover
     *     allowed to touch it may run it, confirmed by the administrator
     * @throws InvalidArgumentException When the operation key is empty or malformed
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $purpose,
        public bool $opensWithAddressCode,
        public bool $opensOnBlockedCard = false,
        public bool $passesWithNothingToConfirm = false,
        public bool $opensWithSecondFactorProof = false,
        public bool $enabledByDefault = true,
        public bool $accountAccess = false,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid step-up operation key: {$key}");
        }
    }
}
