<?php

declare(strict_types=1);

namespace Hilos\I18n;

/** Payload and reply keys shared by the i18n library's test commands. */
final class I18nTestCommandConstants
{
    /** @var string Request and reply key: language code */
    public const string FIELD_CODE = 'code';

    /** @var string Request and reply key: whether the row is enabled */
    public const string FIELD_ENABLED = 'enabled';

    public const string FIELD_ID = 'id';
    public const string FIELD_NATIVE_NAME = 'nativeName';
    public const string FIELD_RTL = 'rtl';
    public const string FIELD_CURRENCY_SYMBOL = 'currencySymbol';
    public const string FIELD_CURRENCY_CODE = 'currencyCode';
    public const string FIELD_LANGUAGE_ID = 'languageId';
    public const string FIELD_COUNTRY_ID = 'countryId';
}
