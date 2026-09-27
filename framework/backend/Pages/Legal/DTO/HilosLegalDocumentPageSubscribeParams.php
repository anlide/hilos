<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Typed route keys; catalog existence is judged by the page. */
final class HilosLegalDocumentPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param string $documentKey Requested route key
     */
    public function __construct(
        public readonly string $documentKey,
    ) {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Parsed non-empty keys
     * @throws MissingPageRouteParamException When a required key is missing
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            documentKey: $params->requireString(HilosPageRouteParams::HILOS_LEGAL_DOCUMENT_KEY),
        );
    }
}
