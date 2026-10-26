<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Pages\Communications\DTO\CommunicationsChannelSummary;
use Hilos\Pages\Communications\DTO\HilosChannelPageSubscribeParams;
use Hilos\Pages\Communications\DTO\MailLetterFrameData;
use Throwable;

/** Defers settings reactions to the serving worker's tick without retaining channel data or subscribers. */
final class CommunicationsChannelSummaryAudience implements SourceChangeSubscriberInterface
{
    private static bool $dirty = false;

    /**
     * Delete and clear need no setting key; remote facts invalidate the same local projection.
     *
     * @param SourceChange $change Committed source fact
     * @param SourceChangeProvenance $provenance Local or applied-remote origin, treated alike
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isDb() && $change->sourceKey === HilosDbContext::settings) {
            self::$dirty = true;
        }
    }

    /** Forgets the deferred notification when the serving agent stops. */
    public static function reset(): void
    {
        self::$dirty = false;
    }

    /**
     * Reads only inside the guarded browser callback, once per channel in this pass.
     * The browser contains a channel failure and rechecks the immutable record after the build.
     *
     * @param AgentInterface $agent Current serving agent
     */
    public static function onAgentTick(AgentInterface $agent): void
    {
        if (!self::$dirty) {
            return;
        }
        self::$dirty = false;

        /** @var array<string, CommunicationsChannelSummary|Throwable> $summaries Memo private to this pass, including failed reads */
        $summaries = [];
        /** @var MailLetterFrameData|Throwable|null $frame Memo private to this pass */
        $frame = null;
        foreach (Hilos::$sr?->pageSubscriptionRecords() ?? [] as $acceptKey => $subscription) {
            $acceptKey = (string) $acceptKey;
            if (!in_array($subscription->page, [
                HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
                HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
                HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
                HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME,
            ], true) || $subscription->agentType !== $agent->getType() || $subscription->agentIndex !== $agent->getIndex()
                || Hilos::$sr->pageSubscription($acceptKey) !== $subscription
            ) {
                continue;
            }

            Hilos::$browser?->publishPageData(
                $subscription->page,
                $acceptKey,
                static function () use ($acceptKey, $subscription, &$summaries, &$frame): PagePayload {
                    if (Hilos::$sr?->pageSubscription($acceptKey) !== $subscription) {
                        return new PagePayload();
                    }
                    $channel = HilosChannelPageSubscribeParams::fromPageRouteParams(
                        new PageRouteParams($subscription->params),
                    )->channelId;
                    if (!isset($summaries[$channel])) {
                        try {
                            $descriptor = Hilos::notificationChannelRegistryClass()::get($channel);
                            if ($descriptor === null) {
                                throw new PageResourceNotFoundException('Unknown channel: ' . $channel);
                            }
                            $summaries[$channel] = new CommunicationsChannelSummaryReader()->read($descriptor);
                        } catch (Throwable $failure) {
                            $summaries[$channel] = $failure;
                        }
                    }
                    if ($summaries[$channel] instanceof Throwable) {
                        throw $summaries[$channel];
                    }

                    $data = ['channelSummary' => $summaries[$channel]->toArray()];
                    if ($subscription->page === HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME) {
                        if ($channel !== MailDeliveryChannel::NAME) {
                            throw new PageResourceNotFoundException('Letter frames require the email channel.');
                        }
                        if ($frame === null) {
                            try {
                                $frame = new CommunicationsLetterFrameReader()->read();
                            } catch (Throwable $failure) {
                                $frame = $failure;
                            }
                        }
                        if ($frame instanceof Throwable) {
                            throw $frame;
                        }
                        $data[MailLetterFrameData::DATA] = $frame->toArray();
                    }

                    return new PagePayload(data: $data);
                },
                [
                    'channelSummary' => WireField::each(CommunicationsChannelSummary::wireFields()),
                    MailLetterFrameData::DATA => WireField::each(MailLetterFrameData::wireFields()),
                ],
            );
        }
    }
}
