<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\LanguageNameActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\LanguageName as ObjectLanguageName;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Read-facing translated language name.
 *
 * @extends DbItem<ObjectLanguageName>
 * @property-read LanguageNameActions $actions
 * @property-read ?int $id
 * @property-read int $languageId
 * @property-read int $inLanguageId
 * @property-read ?int $localeId
 * @property-read string $name
 * @property-read bool $locked
 * @property-read Language $language
 * @property-read Language $inLanguage
 * @property-read ?Locale $locale
 */
class LanguageName extends DbItem
{
    public const string language = 'language';
    public const string inLanguage = 'inLanguage';
    public const string locale = 'locale';

    /**
     * @param string $name Scalar property, relation or actions name
     * @return mixed Field value or inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When a relation or inherited member refuses lookup
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectLanguageName::id => $this->_object->id,
            ObjectLanguageName::languageId => $this->_object->languageId,
            ObjectLanguageName::inLanguageId => $this->_object->inLanguageId,
            ObjectLanguageName::localeId => $this->_object->localeId,
            ObjectLanguageName::name => $this->_object->name,
            ObjectLanguageName::locked => $this->_object->locked,
            self::language => Hilos::$db->languages[$this->_object->languageId],
            self::inLanguage => Hilos::$db->languages[$this->_object->inLanguageId],
            self::locale => Hilos::$db->locales[$this->_object->localeId],
            default => parent::__get($name),
        };
    }
}
