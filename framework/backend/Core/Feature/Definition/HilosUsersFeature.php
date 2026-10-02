<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Pages\Users\AbstractHilosUsersPage;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;

/**
 * Framework user list and user detail admin pages.
 *
 * The rows and the reading of the people are the framework's: every project keeps its people
 * in the framework's own table. The project extends the users table only to name its runtime
 * connections collection, whose key is its own. The card of one person is the framework's too
 * ({@see HilosUserDetailBrowserTable}, HIL-1254): the detail page must be bound to it, and a
 * project that registers its user page without the card is refused here rather than drawing
 * an empty one.
 *
 * The presence source is a requirement rather than an optional extra because the user list
 * shows who is online; without a runtime collection implementing {@see HilosPresenceSource}
 * the page renders everyone as offline, which reads as a bug rather than as a feature that
 * was not switched on.
 */
final class HilosUsersFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Users feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::HILOS_USERS;
    }

    /**
     * @return FeatureRequirements Both user pages with their table bindings, the users table and a presence source
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [AbstractHilosUsersPage::class, AbstractHilosUserPage::class],
            requiredTables: [AbstractHilosUsersTable::class],
            requiredPageTables: [
                AbstractHilosUsersPage::class => AbstractHilosUsersTable::class,
                AbstractHilosUserPage::class => HilosUserDetailBrowserTable::class,
            ],
            requiresPresenceSource: true,
        );
    }
}
