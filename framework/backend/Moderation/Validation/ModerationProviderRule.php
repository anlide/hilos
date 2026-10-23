<?php

declare(strict_types=1);

namespace Hilos\Moderation\Validation;

use Hilos\Constants\LLMConstants;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts the name of one of the two LLM providers a moderation role may run on (HIL-1625).
 *
 * The match is exact: the stored value is read back as the provider name, so a different case or
 * a padded name would be saved and then fail to resolve to a provider when the next check starts.
 */
final class ModerationProviderRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value is not one of the provider names. */
    private const string REFUSAL = 'Value must be "'
        . LLMConstants::PROVIDER_LOCAL
        . '" or "'
        . LLMConstants::PROVIDER_EXTERNAL
        . '"';

    /**
     * Checks that the value names the local or the external provider.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if ($value === LLMConstants::PROVIDER_LOCAL || $value === LLMConstants::PROVIDER_EXTERNAL) {
            return null;
        }

        return self::REFUSAL;
    }
}
