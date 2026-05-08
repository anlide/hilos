<?php

declare(strict_types=1);

namespace Hilos\LLM\Exception;

/**
 * Exception thrown when generated text is consumed before it is available.
 */
class LLMResultUnavailableException extends LLMException
{
}
