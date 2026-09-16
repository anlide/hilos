<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two-step shape of an admin rename (HIL-771).
 *
 * A rename submitted by an administrator did NOT move to the library that owns the account, the
 * way a person's own rename did: what closes an admin submit is a page's ADMIN level, and an
 * agent action carries no level to inherit, so moving the name would have opened it to anybody
 * signed in. The submit stayed and only the write went, which makes each rename a pair - a name
 * on a page and a frame to the library - and the pair is what these tests hold together.
 *
 * Both surfaces ask under the framework's one name, and since HIL-1001 each names in the ask the
 * answer it is waiting on. The ANSWER names are two because the two admin surfaces are served by
 * different agents, and a page-owned answer is routed to the agent serving THAT page. One shared
 * answer name would send both acks to one page, and the other surface's modal would wait forever.
 */
final class AdminRenameOwnershipTest extends TestCase
{
    public function testBothAdminSubmitsStayOnTheirPages(): void
    {
        self::assertArrayHasKey(ChatSignalConstants::USER_UPDATE, AdminUsersPage::ACTIONS);
        self::assertArrayHasKey(HilosSignalConstants::HILOS_USER_UPDATE, UserPage::ACTIONS);

        self::assertArrayNotHasKey(ChatSignalConstants::USER_UPDATE, UsersLibraryAgent::AGENT_ACTIONS);
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_USER_UPDATE, UsersLibraryAgent::AGENT_ACTIONS);
    }

    public function testTheOneWriteFrameIsAddressedToTheUsersLibrary(): void
    {
        self::assertSame(
            HilosAgentType::HILOS_USERS_LIBRARY,
            Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_ADMIN_RENAME] ?? null,
        );
    }

    /**
     * The reply name travels in the ask, so a second ask name would only be a map the library
     * has to keep in step with the pages - the one this leaf removed.
     */
    public function testChatKeepsNoAskNameOfItsOwn(): void
    {
        self::assertFalse(defined(ChatSignalConstants::class . '::USER_ADMIN_RENAME'));
    }

    public function testEachAnswerComesBackToTheSurfaceThatAsked(): void
    {
        self::assertSame(
            ChatSignalConstants::USER_ADMIN_RENAME_DONE,
            array_key_first(AdminUsersPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]),
        );

        self::assertSame(
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
            array_key_first(UserPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]),
        );
    }
}
