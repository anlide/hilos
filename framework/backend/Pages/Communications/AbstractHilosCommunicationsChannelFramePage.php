<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\HilosException;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Pages\Communications\DTO\HilosChannelPageSubscribeParams;
use Hilos\Pages\Communications\DTO\MailLetterFrameData;

/** Email-only frame tab; the Overview page keeps mutation ownership. */
abstract class AbstractHilosCommunicationsChannelFramePage extends AbstractHilosCommunicationsChannelContextPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_FRAME,
    ];

    /**
     * @param PageRouteParams $ownerParams Channel owner's route parameters
     * @return bool Whether the owner's channel supports an HTML letter frame
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        return $ownerParams->getString(HilosPageRouteParams::HILOS_COMMUNICATIONS_CHANNEL_ID) === MailDeliveryChannel::NAME;
    }

    /**
     * @param AbstractDeliveryChannel $descriptor Admitted channel descriptor
     * @param HilosChannelPageSubscribeParams $address Validated channel address
     * @return PagePayload Summary and server-rendered frame in the same answer
     * @throws PageResourceNotFoundException When a registered non-email channel is addressed directly
     * @throws HilosException When the summary, effective frame or preview cannot be built
     */
    protected function buildChannelPayload(AbstractDeliveryChannel $descriptor, HilosChannelPageSubscribeParams $address): PagePayload
    {
        if ($address->channelId !== MailDeliveryChannel::NAME) {
            throw new PageResourceNotFoundException('Letter frames require the email channel.');
        }

        return new PagePayload(data: [MailLetterFrameData::DATA => new CommunicationsLetterFrameReader()->read()->toArray()])
            ->over(parent::buildChannelPayload($descriptor, $address));
    }

    /** @return array<string, WireField> Same frame projection as the live publication */
    protected function dataFields(): array
    {
        return parent::dataFields() + [MailLetterFrameData::DATA => WireField::each(MailLetterFrameData::wireFields())];
    }
}
