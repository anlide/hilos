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
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryPageSubscribeParams;

/** Shared admission for pages addressed to one country code. */
abstract class AbstractHilosI18nCountryCodePage extends AbstractHilosPage
{
    /** @var list<string> Country collection read on subscribe */
    public const array READS_DB = [HilosDbContext::countries];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When countryCode is missing or empty
     * @throws InvalidPageRouteParamException When countryCode has an invalid format
     * @throws PageResourceNotFoundException When the country code is unknown
     * @throws DatabaseException When the country lookup fails
     * @throws HilosException When the database context is unavailable
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $address = HilosI18nCountryPageSubscribeParams::fromPageRouteParams($params);
        if (Hilos::$db->countries[$address->countryCode] === null) {
            throw new PageResourceNotFoundException('Unknown country: ' . $address->countryCode);
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
