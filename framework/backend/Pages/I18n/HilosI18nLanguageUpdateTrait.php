<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nLanguageUpdateActionDTO;

/** One save, two doors: the language card and a row of the languages table. */
trait HilosI18nLanguageUpdateTrait
{
    /**
     * @param AbstractHilosI18nLanguageUpdateActionDTO $dto Language address and new values
     * @throws TableActionException When the language does not exist
     * @throws I18nRowFrozenException When the language is switched on
     * @throws ValidationException When the native name is invalid
     * @throws HilosException When the lookup or write fails
     */
    private function updateLanguage(AbstractHilosI18nLanguageUpdateActionDTO $dto): void
    {
        $language = Hilos::$db->languages[$dto->languageCode]
            ?? throw new TableActionException('Unknown language: ' . $dto->languageCode);
        $language->actions->update($dto->nativeName, $dto->rtl);
        $this->setActionSuccessMessage('Language saved.');
    }
}
