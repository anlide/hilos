<?php

declare(strict_types=1);

namespace Hilos\Moderation;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Moderation\Validation\ModerationModelRule;
use Hilos\Moderation\Validation\ModerationProviderRule;
use Hilos\Moderation\Validation\ModerationTimeoutRule;
use Hilos\Moderation\Validation\ModerationUrlRule;

/**
 * The framework settings-catalog fragment for the common moderator: the provider, model, local
 * address and request timeout of its two LLM profiles, and how long one check may take (HIL-1625).
 *
 * The text role and the image role each own four keys, so an administrator can move one to another
 * model without touching the other. The environment stays the default under every role key, the way
 * it does under {@see LogSettingsCatalog}: with no row written the key reads what the node's
 * env says, and a written row overrides it for every node of the cluster. The value is shown as the
 * environment gives it, judged by nothing: a misspelled provider reads as misspelled, rather than
 * as a local one nobody asked for.
 *
 * An empty URL is a value of its own and means no override: the profile keeps the address its
 * environment resolved. There is no key for an API key. A secret stays in the environment, and the
 * settings of a role can only choose which provider uses it.
 *
 * The fragment is part of every settings catalog the way the delivery-window fragment is: the
 * accessor folds it in under the project's own catalog.
 */
final class ModerationSettingsCatalog implements CatalogProviderInterface
{
    /** Provider of the text profile: local or external. */
    public const string TEXT_PROVIDER = 'moderation.text.provider';

    /** Model the text profile asks, for either provider. */
    public const string TEXT_MODEL = 'moderation.text.model';

    /** Local address of the text profile; empty keeps the address its environment resolved. */
    public const string TEXT_URL = 'moderation.text.url';

    /** Seconds one request of the text profile may take. */
    public const string TEXT_TIMEOUT_SEC = 'moderation.text.timeout_sec';

    /** Provider of the image profile: local or external. */
    public const string IMAGE_PROVIDER = 'moderation.image.provider';

    /** Vision model the image profile asks, for either provider. */
    public const string IMAGE_MODEL = 'moderation.image.model';

    /** Local address of the image profile; empty keeps the address its environment resolved. */
    public const string IMAGE_URL = 'moderation.image.url';

    /** Seconds one request of the image profile may take. */
    public const string IMAGE_TIMEOUT_SEC = 'moderation.image.timeout_sec';

    /** Seconds one whole check may take before its answer is unavailable. */
    public const string CHECK_TIMEOUT_SEC = 'moderation.check_timeout_sec';

    /** Seconds a whole check may take when no row says otherwise. */
    public const int DEFAULT_CHECK_TIMEOUT_SEC = 90;

    /**
     * Builds the moderation settings entries, each role key defaulting to its environment value.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::TEXT_PROVIDER => self::entry(
                SettingsCatalogConstants::TYPE_STRING,
                self::envString(EnvConstants::HILOS_MODERATION_TEXT_PROVIDER, LLMConstants::PROVIDER_LOCAL),
                ModerationProviderRule::class,
            ),
            self::TEXT_MODEL => self::entry(
                SettingsCatalogConstants::TYPE_STRING,
                self::envString(EnvConstants::HILOS_MODERATION_TEXT_MODEL, ModerationLlmConstants::DEFAULT_TEXT_MODEL),
                ModerationModelRule::class,
            ),
            self::TEXT_URL => self::entry(
                SettingsCatalogConstants::TYPE_STRING,
                self::envString(EnvConstants::HILOS_MODERATION_TEXT_URL, ''),
                ModerationUrlRule::class,
            ),
            self::TEXT_TIMEOUT_SEC => self::entry(
                SettingsCatalogConstants::TYPE_FLOAT,
                self::envFloat(EnvConstants::HILOS_MODERATION_TEXT_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC),
                ModerationTimeoutRule::class,
            ),
            self::IMAGE_PROVIDER => self::entry(
                SettingsCatalogConstants::TYPE_STRING,
                self::envString(EnvConstants::HILOS_MODERATION_IMAGE_PROVIDER, LLMConstants::PROVIDER_LOCAL),
                ModerationProviderRule::class,
            ),
            self::IMAGE_MODEL => self::entry(
                SettingsCatalogConstants::TYPE_STRING,
                self::envString(EnvConstants::HILOS_MODERATION_IMAGE_MODEL, ModerationLlmConstants::DEFAULT_IMAGE_MODEL),
                ModerationModelRule::class,
            ),
            self::IMAGE_URL => self::entry(
                SettingsCatalogConstants::TYPE_STRING,
                self::envString(EnvConstants::HILOS_MODERATION_IMAGE_URL, ''),
                ModerationUrlRule::class,
            ),
            self::IMAGE_TIMEOUT_SEC => self::entry(
                SettingsCatalogConstants::TYPE_FLOAT,
                self::envFloat(EnvConstants::HILOS_MODERATION_IMAGE_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC),
                ModerationTimeoutRule::class,
            ),
            self::CHECK_TIMEOUT_SEC => self::entry(
                SettingsCatalogConstants::TYPE_INTEGER,
                self::DEFAULT_CHECK_TIMEOUT_SEC,
                PositiveIntegerRule::class,
            ),
        ];
    }

    /**
     * Builds one catalog entry, open to an admin view mode viewer.
     *
     * @param string $type Setting type
     * @param string|int|float $default Default value
     * @param class-string<SettingValueRuleInterface> $rule Rule a written value must pass
     * @return array<string, mixed> Catalog entry
     */
    private static function entry(string $type, string|int|float $default, string $rule): array
    {
        return [
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            SettingsCatalogConstants::CATALOG_ENTRY_RULE => $rule,
        ];
    }

    /**
     * Reads a textual default from the environment.
     *
     * A project whose environment catalog does not carry the key at all falls back to the literal:
     * the fragment has to describe a complete key even where the environment says nothing.
     *
     * @param EnvConstants $key Environment variable backing this setting
     * @param string $fallback Value to use when the environment cannot answer
     * @return string Default value for the catalog entry
     */
    private static function envString(EnvConstants $key, string $fallback): string
    {
        $env = Hilos::$env;
        if ($env === null) {
            return $fallback;
        }

        try {
            return $env[$key]->string();
        } catch (EnvException) {
            return $fallback;
        }
    }

    /**
     * Reads a numeric default from the environment.
     *
     * @param EnvConstants $key Environment variable backing this setting
     * @param float $fallback Value to use when the environment cannot answer
     * @return float Default value for the catalog entry
     */
    private static function envFloat(EnvConstants $key, float $fallback): float
    {
        $env = Hilos::$env;
        if ($env === null) {
            return $fallback;
        }

        try {
            return $env[$key]->float();
        } catch (EnvException) {
            return $fallback;
        }
    }
}
