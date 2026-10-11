<?php

declare(strict_types=1);

namespace Hilos\I18n;

/** Payload and reply keys shared by the i18n library's test commands. */
final class I18nTestCommandConstants
{
    /** @var string Request and reply key: language code */
    public const string FIELD_CODE = 'code';

    /** @var string Reply key: whether the language is enabled */
    public const string FIELD_ENABLED = 'enabled';
}
