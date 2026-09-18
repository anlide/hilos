<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Hilos\Core\Exception\ValidationException;

/**
 * InvalidOAuthAccountException - the gateway refused an account a spec declared (HIL-923).
 *
 * It carries a code rather than only a sentence, for the same reason
 * {@see InvalidBehaviorException} does: POST /oauth/test/account answers it as a 400 with the code
 * in `error`, and the spec helper fails on the spot with that reason. A declaration taken silently
 * would show up much later as a consent screen with nobody to pick, which reads as a product defect
 * rather than as a typo in the test.
 *
 * The order for an expired code, POST /oauth/test/expired-code, is refused by this same exception
 * with the same codes (HIL-926): it names an account the same way a declaration does.
 */
final class InvalidOAuthAccountException extends ValidationException
{
    /** A key the declaration does not know - most often a misspelt field. */
    public const string FIELD_UNKNOWN = 'FIELD_UNKNOWN';

    /** The profile is missing or is not a provider the emulator plays. */
    public const string PROFILE_UNKNOWN = 'PROFILE_UNKNOWN';

    /** The account id is missing, not a string, or not a non-empty run of digits. */
    public const string SUBJECT_REQUIRED = 'SUBJECT_REQUIRED';

    /** The login, the name or the email is present but is not a string. */
    public const string FIELD_INVALID = 'FIELD_INVALID';

    /**
     * Refuses an account declaration.
     *
     * @param string $error Refusal code the spec reads, one of the constants above
     */
    public function __construct(public readonly string $error)
    {
        parent::__construct('OAuth account declaration refused: ' . $error);
    }
}
