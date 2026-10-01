<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Impersonation;

use Hilos\Auth\Impersonation\ImpersonationAccountAccess;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Pages\AbstractHilosProfileSignInPage;
use PHPUnit\Framework\TestCase;

/**
 * The list of actions that touch the sign-in of an account, as the takeover's check reads it (HIL-1170).
 *
 * A name in the list that no owner declares would close nothing; an exit in it - or among the
 * actions that need a session, which "only look" closes - would lock an administrator inside
 * someone else's account.
 */
final class ImpersonationAccountAccessTest extends TestCase
{
    public function testEveryListedActionIsDeclaredByItsOwner(): void
    {
        $declared = [
            ...array_keys(AbstractUsersLibraryAgent::AGENT_ACTIONS),
            ...array_keys(AbstractSessionsLibraryAgent::AGENT_ACTIONS),
            ...array_keys(AbstractHilosProfileSignInPage::ACTIONS),
        ];

        foreach (ImpersonationAccountAccess::ACTIONS as $action) {
            self::assertContains($action, $declared, $action);
        }
        self::assertSame(array_values(array_unique(ImpersonationAccountAccess::ACTIONS)), ImpersonationAccountAccess::ACTIONS);
    }

    public function testTheExitsOfATakeoverAreClosedByNothing(): void
    {
        foreach ([HilosSignalConstants::HILOS_IMPERSONATE_STOP, HilosSignalConstants::HILOS_LOGOUT] as $exit) {
            self::assertNotContains($exit, ImpersonationAccountAccess::ACTIONS, $exit);
            self::assertNotContains($exit, AbstractSessionsLibraryAgent::AUTH_ACTIONS, $exit);
        }
    }

    /**
     * What a person does with their own account is closed to a takeover at its own commands, whatever the setting says.
     */
    public function testDeletingTheAccountAndAcceptingTheTermsAreNotOpenedBySignInAccess(): void
    {
        foreach ([
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT,
        ] as $action) {
            self::assertNotContains($action, ImpersonationAccountAccess::ACTIONS, $action);
        }
    }
}
