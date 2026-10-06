<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\LanguageActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\HilosException;

/**
 * Read-facing language row.
 *
 * @extends DbItem<ObjectLanguage>
 * @property-read LanguageActions $actions
 * @property-read ?int $id
 * @property-read string $code
 * @property-read string $nativeName
 * @property-read bool $rtl
 * @property-read bool $enabled
 */
class Language extends DbItem
{
    /**
     * @param string $name Scalar property or actions name
     * @return mixed Field value or the inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When the inherited getter refuses the member
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectLanguage::id => $this->_object->id,
            ObjectLanguage::code => $this->_object->code,
            ObjectLanguage::nativeName => $this->_object->nativeName,
            ObjectLanguage::rtl => $this->_object->rtl,
            ObjectLanguage::enabled => $this->_object->enabled,
            default => parent::__get($name),
        };
    }
}
