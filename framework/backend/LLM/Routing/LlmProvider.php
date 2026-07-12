<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

use Hilos\Constants\LLMConstants;
use Hilos\LLM\Exception\LLMConfigurationException;

/**
 * The provider a resolved LLM profile runs on.
 *
 * An explicit dimension of a profile, decoupled from credentials: a profile
 * states its provider directly rather than having it inferred from whether an
 * API key is present.
 */
enum LlmProvider: string
{
    case LOCAL = LLMConstants::PROVIDER_LOCAL;
    case EXTERNAL = LLMConstants::PROVIDER_EXTERNAL;

    /**
     * Parses a provider string (as read from env) into an enum case.
     *
     * @param string $value Provider value, e.g. 'local' or 'external'
     * @return self Matching provider
     * @throws LLMConfigurationException When the value is not a known provider
     */
    public static function fromValue(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new LLMConfigurationException("Unknown LLM provider '{$value}'");
    }
}
