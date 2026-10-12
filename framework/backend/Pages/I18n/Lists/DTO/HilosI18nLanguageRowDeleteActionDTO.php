<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nLanguageDeleteActionDTO;

/** Language list row's removal request. */
final class HilosI18nLanguageRowDeleteActionDTO extends AbstractHilosI18nLanguageDeleteActionDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Language list row action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE;
    }
}
