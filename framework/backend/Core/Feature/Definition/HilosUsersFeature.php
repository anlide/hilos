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

/**
 * Framework user list and user detail admin pages.
 *
 * The rows themselves stay project-owned - who a user is differs per project - so the
 * framework requires the project's table to extend its base and leaves the shape alone.
 * The detail page carries no framework table class to name, so its binding is required
 * without naming a target: what it is bound to is the project's own browser table.
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
                AbstractHilosUserPage::class => null,
            ],
            requiresPresenceSource: true,
        );
    }
}
