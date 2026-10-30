<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;

/**
 * AbstractHilosProfileSecurityPage - the profile's security page: the second factor (HIL-494).
 *
 * The framework owns the page's identity - key, route `/profile/security`, subscription signal
 * - so every project that signs people in exposes the same section; the concrete subclass
 * binds the agent that serves it. The common profile base supplies authenticated access,
 * the shared snapshot and the second-factor membership.
 *
 * It is a READING surface. The subscription answers with the whole section of the person
 * behind the connection ({@see SecondFactorStateProjector}); every submit of the section - the
 * app connected or removed, the codes shown or renewed, the wait chosen, the removal asked for
 * or canceled - is a command of the users library, which owns the tables. After the answer the
 * connection joins the person's group ({@see SecondFactorGroup}), so a change made anywhere
 * reaches every tab showing the section.
 *
 * The account deletion's danger zone is not here: it sits at the bottom of the profile root,
 * whose page answers with its state ({@see AbstractHilosProfilePage}, HIL-1169).
 */
abstract class AbstractHilosProfileSecurityPage extends AbstractHilosProfileSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_SECURITY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_SECURITY,
    ];
}
