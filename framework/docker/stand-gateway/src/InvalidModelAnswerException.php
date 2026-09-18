<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Hilos\Core\Exception\ValidationException;

/**
 * InvalidModelAnswerException - the gateway refused a model answer a spec dictated (HIL-925).
 *
 * It carries a code rather than only a sentence, for the same reason
 * {@see InvalidBehaviorException} does: POST /model/test/answer answers it as a 400 with the code
 * in `error`, and the spec helper fails on the spot with that reason. A dictation taken silently
 * would show up much later as a model that refused to answer, which reads as a product defect
 * rather than as a typo in the test.
 */
final class InvalidModelAnswerException extends ValidationException
{
    /** A key the dictation does not know - most often a misspelt field. */
    public const string FIELD_UNKNOWN = 'FIELD_UNKNOWN';

    /** The key is missing, not a string, or empty. */
    public const string KEY_REQUIRED = 'KEY_REQUIRED';

    /** The response is missing or not a string; an empty string is a legitimate answer. */
    public const string RESPONSE_REQUIRED = 'RESPONSE_REQUIRED';

    /**
     * Refuses a model answer dictation.
     *
     * @param string $error Refusal code the spec reads, one of the constants above
     */
    public function __construct(public readonly string $error)
    {
        parent::__construct('Model answer declaration refused: ' . $error);
    }
}
