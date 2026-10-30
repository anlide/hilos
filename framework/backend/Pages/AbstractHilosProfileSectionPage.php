<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;

/** Shared personal snapshot and live membership of every current-user profile section. */
abstract class AbstractHilosProfileSectionPage extends AbstractPage
{
    public const PageReach REACH = PageReach::ROUTE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const string PROFILE_PHOTO_SECTION = 'profilePhoto';
    public const string SECOND_FACTOR_SECTION = 'secondFactor';

    public const array REQUIRED_LISTS = [HilosProfileIdentitiesBrowserList::class];

    /** @var list<string> Second-factor sources; subclasses append their own reads. */
    public const array READS_DB = [
        HilosDbContext::secondFactors,
        HilosDbContext::secondFactorBackupCodes,
        HilosDbContext::secondFactorResets,
        HilosDbContext::secondFactorSettings,
    ];

    /**
     * Keeps the common snapshot authoritative even when a project contributes its own body.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Parameters passed to the section's body hook
     * @return ?PagePayload Common snapshot with the section's own payload, or null for a guest
     * @throws HilosException When the common snapshot or the section's body cannot be read
     */
    final protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            self::PROFILE_PHOTO_SECTION => Hilos::hasFeature(HilosFeature::PROFILE_PHOTO),
            self::SECOND_FACTOR_SECTION => SecondFactorStateProjector::stateFor($userId)->toArray(),
        ])->over($this->buildProfilePagePayload($acceptKey, $params) ?? new PagePayload());
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Section route parameters
     * @return ?PagePayload Section contribution, absent by default
     * @throws HilosException When the section's own data cannot be read
     */
    protected function buildProfilePagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return null;
    }

    /**
     * Joins the common live source before running the section's own after-response work.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Parameters passed to the section's after-response hook
     * @throws InvalidArgumentException When the group join cannot be named
     * @throws HilosException When the section's after-response work fails
     */
    final protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return;
        }

        SecondFactorGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        $this->onProfileSubscribeAfterResponse($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Section route parameters
     * @throws HilosException When the section's after-response work fails
     */
    protected function onProfileSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
    }
}
