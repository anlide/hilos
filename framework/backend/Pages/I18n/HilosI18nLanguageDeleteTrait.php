<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\DefaultLanguageProtectedException;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nLanguageDeleteActionDTO;

/** One transactional removal, entered through either language page. */
trait HilosI18nLanguageDeleteTrait
{
    /**
     * @param AbstractHilosI18nLanguageDeleteActionDTO $dto Language address
     * @throws TableActionException When the language does not exist
     * @throws DefaultLanguageProtectedException When this is the configured default
     * @throws ValidationException When locales or manual names reference the language
     * @throws HilosException When ownership, lookup or transactional removal fails
     */
    protected function deleteLanguage(AbstractHilosI18nLanguageDeleteActionDTO $dto): void
    {
        $language = Hilos::$db->languages[$dto->languageId]
            ?? throw new TableActionException('Unknown language: ' . $dto->languageId);
        $language->actions->delete();
        $this->setActionSuccessMessage('Language deleted.');
    }
}
