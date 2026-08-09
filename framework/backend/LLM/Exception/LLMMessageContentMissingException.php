<?php

declare(strict_types=1);

namespace Hilos\LLM\Exception;

use Hilos\LLM\DTO\Message;

/**
 * A chat message was built or normalized without the content it is made of.
 *
 * {@see Message} declares content required, and the providers put it straight into the request
 * body. A missing one used to travel as an empty string, so the model was asked to answer a
 * turn that says nothing — billed, plausible-looking, and impossible to tell from a model that
 * simply replied badly. Refusing names the fault at the call site instead.
 */
class LLMMessageContentMissingException extends LLMException
{
}
