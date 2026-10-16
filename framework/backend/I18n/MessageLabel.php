<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;

/** How a success message of the languages and countries section names its object. */
final class MessageLabel
{
    /**
     * @param Language $language Language item
     * @return string Native name and code
     */
    public static function language(Language $language): string
    {
        return "{$language->nativeName} ({$language->code})";
    }

    /**
     * @param Country $country Country item
     * @return string Base name in the default language with code, or the code alone
     * @throws DatabaseException When the language or country name lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public static function country(Country $country): string
    {
        $name = $country->id === null ? null : Hilos::$db->countries->nameInDefaultLanguage($country->id);

        return $name === null ? $country->code : "{$name} ({$country->code})";
    }
}
