<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for where an administrator's rename lives (HIL-771).
 *
 * A rename submitted by an administrator did NOT move to the library that owns the account, the
 * way a person's own rename did: what closes an admin submit is a page's ADMIN level, and an
 * agent action carries no level to inherit, so moving the name would have opened it to anybody
 * signed in. The submit stayed on the framework user page and only the write went, which makes
 * the rename a pair - a name on that page and a frame to the library - and the pair is what
 * these tests hold together.
 *
 * The page asks under the framework's one name and names in the ask the answer it is waiting on.
 * Chat keeps no ask name of its own.
 */
final class AdminRenameOwnershipTest extends TestCase
{
    public function testTheFrameworkSubmitStaysOnTheUserPage(): void
    {
        self::assertArrayHasKey(HilosSignalConstants::HILOS_USER_UPDATE, UserPage::ACTIONS);
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

    public function testTheAnswerComesBackToTheUserPage(): void
    {
        self::assertArrayHasKey(
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
            UserPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL],
        );
    }
}
