<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\View\Collection\HilosUserBlockSource;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Pages\Users\AbstractHilosUsersPage;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\AbstractHilosUserTableRow;
use Hilos\Users\AccountBlockReader;

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
 *
 * The block source is required for the same reason in the other direction: the user list
 * already renders the block flag ({@see AbstractHilosUserTableRow}), and the framework reads
 * that fact through {@see AccountBlockReader} - block enforcement (HIL-289) and the composed
 * account standing (HIL-945) both do. A project that activates the users feature therefore owes
 * a database collection implementing {@see HilosUserBlockSource}, read in every process.
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
     * @return FeatureRequirements Both user pages with their table bindings, the users table, a presence source and a block source
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
            requiresUserBlockSource: true,
        );
    }
}
