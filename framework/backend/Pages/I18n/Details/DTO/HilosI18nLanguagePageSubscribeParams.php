<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Typed address of a language page. */
final class HilosI18nLanguagePageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param string $languageCode Two-letter lowercase language code
     */
    public function __construct(public readonly string $languageCode)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Validated language address
     * @throws MissingPageRouteParamException When languageCode is missing or empty
     * @throws InvalidPageRouteParamException When languageCode is not two lowercase ASCII letters
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        $languageCode = $params->requireString(HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE);
        if (preg_match('/^[a-z]{2}$/D', $languageCode) !== 1) {
            throw new InvalidPageRouteParamException(HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE, 'expected two lowercase letters');
        }

        return new static($languageCode);
    }
}
