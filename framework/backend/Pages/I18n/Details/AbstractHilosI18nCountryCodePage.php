<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageResource;
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
     * Names the country this page is about by its primary id.
     *
     * The address is the country code. The reference is the row's primary id,
     * read through the countries collection, which accepts either.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @return ?PageResource Country row, addressed by primary id
     * @throws MissingPageRouteParamException When countryCode is missing or empty
     * @throws InvalidPageRouteParamException When countryCode has an invalid format
     * @throws PageResourceNotFoundException When the country code is unknown
     * @throws PageInternalErrorException When the country id is empty
     * @throws DatabaseException When the country lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded country has the wrong type
     */
    final protected function resolvePageResource(string $acceptKey, PageRouteParams $params): ?PageResource
    {
        $address = HilosI18nCountryPageSubscribeParams::fromPageRouteParams($params);
        $country = Hilos::$db->countries[$address->countryCode]
            ?? throw new PageResourceNotFoundException('Unknown country: ' . $address->countryCode);

        return PageResource::db(HilosDbContext::countries, $country->id);
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
