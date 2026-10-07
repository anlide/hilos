<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Catalog\LanguageDefinition;

/** Resolves the current installation default from the process environment. */
final class DefaultLanguage
{
    /**
     * @return string Exact built-in language code
     * @throws EnvException When the configured key is absent from the environment catalog
     * @throws EnvInvalidValueException When the configured code is absent or unknown
     */
    public static function code(): string
    {
        return self::definition()->code;
    }

    /**
     * @return LanguageDefinition Built-in definition of the configured language
     * @throws EnvException When the configured key is absent from the environment catalog
     * @throws EnvInvalidValueException When the configured code is absent or unknown
     */
    public static function definition(): LanguageDefinition
    {
        $code = Hilos::$env[EnvConstants::HILOS_DEFAULT_LANGUAGE]->string();
        $definition = BuiltInI18nCatalog::language($code);
        if ($definition === null) {
            throw new EnvInvalidValueException(
                "Environment variable 'HILOS_DEFAULT_LANGUAGE' has unknown language code '{$code}'",
            );
        }

        return $definition;
    }
}
