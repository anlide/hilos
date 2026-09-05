<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\ImpersonateDoneSignalData;
use Hilos\Auth\Session\DTO\ImpersonateRequestSignalData;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Pages\Users\AbstractHilosUsersPage;
use Hilos\Tests\Unit\Notification\DeliveryRetryTwoStepTest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two halves of the admin takeover (HIL-824), drawn on the peer this
 * shape already has for the delivery retry ({@see DeliveryRetryTwoStepTest}).
 *
 * The takeover's start is the submit that LEFT its owner in this leaf, and what it got
 * instead is four declarations that only hold together: the action is declared on the Hilos
 * users page, that page carries the ADMIN level which is the whole reason the name is there,
 * the write frame is declared by the library that owns the session, and the answer frame is
 * declared back on the page, so the admin is answered by the surface they submitted to.
 *
 * Each one alone has a defect with no symptom in the others' tests. The action declared on
 * the library again would put the takeover behind a project seam instead of a level - the
 * state it was in until this leaf. A page at any level below ADMIN would hand the takeover
 * to anybody signed in, and nothing else in the route would notice. The answer frame
 * declared nowhere would leave every tracked takeover hanging, because the page defers its
 * own ack when it forwards the work.
 *
 * The fifth case is the leaf's boundary rather than its shape: the STOP did not move, and a
 * later leaf tidying the pair together would be undoing a decision rather than making one.
 */
final class ImpersonationTwoStepTest extends TestCase
{
    public function testTheTakeoverActionMovedToTheAdminPage(): void
    {
        self::assertSame(
            ImpersonateStartActionDTO::class,
            AbstractHilosUsersPage::ACTIONS[HilosSignalConstants::HILOS_IMPERSONATE_START] ?? null,
        );

        self::assertArrayNotHasKey(
            HilosSignalConstants::HILOS_IMPERSONATE_START,
            AbstractSessionsLibraryAgent::AGENT_ACTIONS,
        );
    }

    /**
     * The level is the point of the move, so it is pinned rather than left to the base class.
     *
     * It is inherited and not written on the page, which is exactly why it is worth a case:
     * a later leaf declaring PUBLIC or AUTHENTICATED there would read as a page-scoped
     * decision and would silently be a decision about this action.
     */
    public function testTheAdminPageIsWhatClosesIt(): void
    {
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosUsersPage::ACCESS_LEVEL);
    }

    public function testTheLibraryIsAddressedByTheWriteFrame(): void
    {
        self::assertSame(
            ImpersonateRequestSignalData::class,
            AbstractSessionsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_IMPERSONATE_REQUEST] ?? null,
        );
    }

    public function testThePageIsAddressedByTheAnswerFrame(): void
    {
        self::assertSame(
            ImpersonateDoneSignalData::class,
            AbstractHilosUsersPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_IMPERSONATE_DONE] ?? null,
        );
    }

    /**
     * The stop stays a library action, and stays the worked example of when a name may.
     *
     * Its right is read off the session's own marker - no stronger than "you have a session"
     * - and while a takeover is on there is no admin page guaranteed to hold it. Moving it
     * for symmetry with its start would take the control away from the app shell, which is
     * the one place it can live.
     */
    public function testTheStopDidNotMove(): void
    {
        self::assertSame(
            ImpersonateStopActionDTO::class,
            AbstractSessionsLibraryAgent::AGENT_ACTIONS[HilosSignalConstants::HILOS_IMPERSONATE_STOP] ?? null,
        );

        self::assertArrayNotHasKey(
            HilosSignalConstants::HILOS_IMPERSONATE_STOP,
            AbstractHilosUsersPage::ACTIONS,
        );
    }
}
