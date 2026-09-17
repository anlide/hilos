<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Hilos\Core\Exception\ValidationException;

/**
 * InvalidBehaviorException - the gateway refused a behavior a spec declared.
 *
 * It carries a code rather than only a sentence, because the code is what the spec reads:
 * POST /test/behavior answers it as a 400 with the code in `error`, and the spec helper fails
 * on the spot with that reason. A declaration taken silently would show up much later as a
 * provider that answered as usual, which reads as a product defect rather than as a typo in
 * the test.
 */
final class InvalidBehaviorException extends ValidationException
{
    /** A key the declaration does not know - most often a misspelt lever. */
    public const string FIELD_UNKNOWN = 'FIELD_UNKNOWN';

    /** The path is not a provider route of any resident. */
    public const string PATH_NOT_PROVIDER = 'PATH_NOT_PROVIDER';

    /** The key is missing, not a string, or empty. */
    public const string KEY_REQUIRED = 'KEY_REQUIRED';

    /** The status is not an integer from 400 to 599. */
    public const string STATUS_OUT_OF_RANGE = 'STATUS_OUT_OF_RANGE';

    /** A delay or a hold is not a non-negative integer. */
    public const string DURATION_INVALID = 'DURATION_INVALID';

    /** The cut is not a boolean. */
    public const string CUT_INVALID = 'CUT_INVALID';

    /**
     * Refuses a behavior declaration.
     *
     * @param string $error Refusal code the spec reads, one of the constants above
     */
    public function __construct(public readonly string $error)
    {
        parent::__construct('Behavior declaration refused: ' . $error);
    }
}
