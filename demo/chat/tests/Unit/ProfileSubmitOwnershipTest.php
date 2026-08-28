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
 * Unit tests for where a profile submit lands and what still closes it (HIL-771).
 *
 * The seven submits that write a person left {@see ProfilePage} for {@see UsersLibraryAgent},
 * which owns those tables, and the move is made of two declarations that have to hold together:
 * the name is routed to the library, and the library lists it as needing a session. Either one
 * alone is a defect with no symptom in the other's test - a name routed but not listed opens a
 * profile submit to a guest, because the page level that used to close it does not travel with
 * the name.
 */
final class ProfileSubmitOwnershipTest extends TestCase
{
    /** @var list<string> The submits that write, by wire name - the whole of what moved. */
    private const array MOVED_SUBMITS = [
        ChatSignalConstants::RENAME,
        ChatSignalConstants::UNLINK_IDENTITY,
        ChatSignalConstants::SET_PASSWORD,
        ChatSignalConstants::ADD_SMS_REQUEST,
        ChatSignalConstants::ADD_SMS_CONFIRM,
        ChatSignalConstants::ADD_PASSWORD_REQUEST,
        ChatSignalConstants::ADD_PASSWORD_CONFIRM,
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
     * link mints a URL. Pinned as a list rather than as an absence, so a submit added back to the
     * page has to be argued for here.
     */
    public function testTheProfilePageHostsOnlyTheLinkStart(): void
    {
        self::assertSame(
            [HilosSignalConstants::HILOS_LINK_OAUTH_START],
            array_keys(ProfilePage::ACTIONS),
        );
    }
}
