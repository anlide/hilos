<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;

/** ADMIN subscription sharing the document declaration with its adopted standard clauses window. */
abstract class AbstractHilosLegalDocumentSetPage extends AbstractHilosLegalDocumentPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL_DOCUMENT_SET;
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_DOCUMENT_SET,
    ];
}
