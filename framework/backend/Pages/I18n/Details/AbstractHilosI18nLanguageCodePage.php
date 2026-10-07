<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguagePageSubscribeParams;

/** Shared admission for pages addressed to one language code. */
abstract class AbstractHilosI18nLanguageCodePage extends AbstractHilosPage
{
    /** @var list<string> Language collection read on subscribe */
    public const array READS_DB = [HilosDbContext::languages];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When languageCode is missing or empty
     * @throws InvalidPageRouteParamException When languageCode has an invalid format
     * @throws PageResourceNotFoundException When the language code is unknown
     * @throws DatabaseException When the language lookup fails
     * @throws HilosException When the database context is unavailable
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $address = HilosI18nLanguagePageSubscribeParams::fromPageRouteParams($params);
        if (Hilos::$db->languages[$address->languageCode] === null) {
            throw new PageResourceNotFoundException('Unknown language: ' . $address->languageCode);
        }
    }

    /**
     * Revalidates the address before a complete subscription response.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When the address is invalid or the page response fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }
}
