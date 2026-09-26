<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for where a profile submit lands and what still closes it (HIL-771, HIL-1137).
 *
 * The submits that write a person left {@see ProfilePage} for {@see UsersLibraryAgent}, which
 * owns those tables: the rename stays the chat's own, and the ways in and the email change came
 * from the framework's library (HIL-1137), which the chat's inherits. Each is made of two
 * declarations that have to hold together:
 * the name is routed to the library, and the library lists it as needing a session. Either one
 * alone is a defect with no symptom in the other's test - a name routed but not listed opens a
 * profile submit to a guest, because the page level that used to close it does not travel with
 * the name.
 */
final class ProfileSubmitOwnershipTest extends TestCase
{
    /** @var list<string> The submits that write, by wire name - the chat's own, then the ten the framework brought (HIL-1137). */
    private const array MOVED_SUBMITS = [
        ChatSignalConstants::RENAME,
        HilosSignalConstants::PROFILE_SET_PASSWORD,
        HilosSignalConstants::PROFILE_UNLINK_IDENTITY,
        HilosSignalConstants::PROFILE_ADD_SMS_REQUEST,
        HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
    ];

    public function testEveryMovedSubmitIsRoutedToTheUsersLibrary(): void
    {
        $routes = Hilos::getAgentActionRoutes();

        foreach (self::MOVED_SUBMITS as $action) {
            self::assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $routes[$action] ?? null, $action);
        }
    }

    public function testEveryMovedSubmitStillNeedsASignedInSession(): void
    {
        foreach (self::MOVED_SUBMITS as $action) {
            self::assertContains($action, UsersLibraryAgent::AUTH_ACTIONS, $action);
        }
    }

    /**
     * The page kept exactly one action, and it is the one that writes nothing: starting an OAuth
     * link mints a URL. It is the framework base's now (HIL-1137), inherited rather than declared.
     * Pinned as a list rather than as an absence, so a submit added back to the page has to be
     * argued for here.
     */
    public function testTheProfilePageHostsOnlyTheLinkStart(): void
    {
        self::assertSame(
            [HilosSignalConstants::HILOS_LINK_OAUTH_START],
            array_keys(ProfilePage::ACTIONS),
        );
    }
}
