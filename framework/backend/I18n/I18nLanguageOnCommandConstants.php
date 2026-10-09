<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Constants\CliCommands;
/** Wire keys shared by the CLI half and the i18n library for {@see CliCommands::I18N_TEST_LANGUAGE_ON}. */
final class I18nLanguageOnCommandConstants
{
    /** @var string Request and reply key: language code */
    public const string FIELD_CODE = 'code';

    /** @var string Reply key: whether the language is enabled */
    public const string FIELD_ENABLED = 'enabled';
}
