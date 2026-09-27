<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/** The consent form and the treatment of an outstanding acceptance after its deadline. */
final class LegalSettings
{
    public const string CONSENT_FORM_KEY = 'legal.consent_form';
    public const string CONSENT_FORM_CHECKBOX = 'checkbox';
    public const string CONSENT_FORM_LINE = 'line';
    public const string REFUSAL_KEY = 'legal.refusal_after_deadline';
    public const string REFUSAL_FREEZE = 'freeze';
    public const string REFUSAL_REMIND = 'remind';
    public const array KEYS = [self::CONSENT_FORM_KEY, self::REFUSAL_KEY];

    /**
     * @return string Consent form, defaulting to checkbox when the catalog is not mounted
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function consentForm(): string
    {
        $settings = Hilos::$setting;

        return $settings === null || !isset($settings[self::CONSENT_FORM_KEY])
            ? self::CONSENT_FORM_CHECKBOX : $settings[self::CONSENT_FORM_KEY]->string();
    }

    /**
     * @return string Deadline treatment, defaulting to freeze when the catalog is not mounted
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function refusal(): string
    {
        $settings = Hilos::$setting;

        return $settings === null || !isset($settings[self::REFUSAL_KEY])
            ? self::REFUSAL_FREEZE : $settings[self::REFUSAL_KEY]->string();
    }
}
