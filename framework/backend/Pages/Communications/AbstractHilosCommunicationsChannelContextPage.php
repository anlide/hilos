<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Communications\DTO\CommunicationsChannelSummary;
use Hilos\Pages\Communications\DTO\HilosChannelPageSubscribeParams;

/** Shared registry admission and page data for the three channel tabs. */
abstract class AbstractHilosCommunicationsChannelContextPage extends AbstractHilosPage
{
    /** Settings read through the channel config resolver and the typed setting accessor. */
    public const array READS_DB = [HilosDbContext::settings];

    /**
     * Revalidates the merged address and sends one complete subscription response.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged subscription route parameters
     * @throws HilosException When the address is refused or the page response fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * The registry check precedes the browser snapshot, so a refusal registers no table window.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Channel route parameters
     * @return PagePayload Channel summary to combine with identity and the page's mounted tables
     * @throws MissingPageRouteParamException When channelId is missing, empty or not a string
     * @throws PageResourceNotFoundException When the channel is not registered
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings metadata or values are invalid
     * @throws EnvException When an env-backed config field is invalid
     * @throws InvalidArgumentException When the resolved object type does not match its collection
     * @throws LogicException When a settings collection is not configured
     */
    final protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $address = HilosChannelPageSubscribeParams::fromPageRouteParams($params);
        $descriptor = Hilos::notificationChannelRegistryClass()::get($address->channelId);
        if ($descriptor === null) {
            throw new PageResourceNotFoundException('Unknown channel: ' . $address->channelId);
        }

        return new PagePayload(data: ['channelSummary' => new CommunicationsChannelSummaryReader()->read($descriptor)->toArray()]);
    }

    /**
     * @return array<string, WireField> Summary fields shared with the live projection
     */
    protected function dataFields(): array
    {
        return ['channelSummary' => WireField::each(CommunicationsChannelSummary::wireFields())];
    }
}
