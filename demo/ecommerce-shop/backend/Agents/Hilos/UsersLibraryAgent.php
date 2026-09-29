<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents\Hilos;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\HilosFeature;

/**
 * The ecommerce-shop demo's users library - the project half of the framework sign-in feature.
 *
 * Every sign-in command lives in {@see AbstractUsersLibraryAgent}, and so does the person -
 * created, named and guarded against deletion on the framework's table. This demo has nothing
 * of its own to answer: it signs people in by password, so there is no provider wiring to
 * build, and it keeps no event log, so a new account or a new name is news to nobody here.
 * The class exists because the framework library is abstract: a project names its own.
 *
 * Registered under {@see HilosAgentType::HILOS_USERS_LIBRARY} by this demo's own topology,
 * and reached because the demo declares {@see HilosFeature::AUTH}: the feature is what
 * turns the library's command names into this project's door.
 */
final class UsersLibraryAgent extends AbstractUsersLibraryAgent
{
}
