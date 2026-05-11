<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\HilosUserSubscriptionSignalData;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Projection\Util\UserPresenceDeliveryBuilder;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\Rule\CustomRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;

final class HilosUserPageProjection extends PageProjection
{
    public function page(): string
    {
        return HilosPageConstants::HILOS_USER;
    }

    public function subscribeSnapshotSignalName(): string
    {
        return HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER;
    }

    protected function rules(): iterable
    {
        yield new CustomRule(
            triggers: [RtChatContext::connections],
            snapshot: static function (): void {
            },
            broadcast: function (SourceChange $change, array $audienceAcceptKeys): iterable {
                if (Hilos::$sr === null) {
                    return;
                }
                $userId = UserPresenceDeliveryBuilder::userIdFromConnectionChange($change);
                if ($userId <= 0) {
                    return;
                }
                $payload = UserPresenceDeliveryBuilder::buildForConnectionChange(
                    change: $change,
                    includeConnectionStats: true,
                );
                if ($payload === null) {
                    return;
                }
                $targetAcceptKeys = Hilos::$sr->getAcceptKeysForPage(
                    HilosPageConstants::HILOS_USER,
                    HilosPageRouteParams::HILOS_USER_USER_ID,
                    (string)$userId,
                );
                foreach (array_values(array_unique($targetAcceptKeys)) as $acceptKey) {
                    if ($acceptKey === '') {
                        continue;
                    }
                    yield new ProjectionDelivery(
                        ChatSignalConstants::USER_PRESENCE_UPDATE,
                        $payload,
                        $acceptKey,
                    );
                }
            },
        );
    }

    protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface {
        if (Hilos::$db === null) {
            return null;
        }
        $typedParams = HilosUserPageSubscribeParams::fromPageRouteParams($params);
        $user = Hilos::$db->users[$typedParams->userId]
            ?? throw new PageResourceNotFoundException("User #{$typedParams->userId} not found");

        return HilosUserSubscriptionSignalData::fromFrontendChanges(
            $typedParams->userId,
            UserFrontendStateProjector::fullForUser($user, includeConnectionStats: true),
        );
    }
}
