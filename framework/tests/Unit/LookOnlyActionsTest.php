<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\LookOnlyActions;
use PHPUnit\Framework\TestCase;

/** The common opening for every look-only gate (HIL-1290). */
final class LookOnlyActionsTest extends TestCase
{
    public function testEverySessionActionBelongsToTheSessionsLibraryAndOccursOnce(): void
    {
        foreach (LookOnlyActions::SESSION_ACTIONS as $action) {
            self::assertArrayHasKey($action, AbstractSessionsLibraryAgent::AGENT_ACTIONS, $action);
        }
        self::assertSame(array_values(array_unique(LookOnlyActions::SESSION_ACTIONS)), LookOnlyActions::SESSION_ACTIONS);
    }

    public function testAnOwnersReadingActionIsOpen(): void
    {
        self::assertTrue(LookOnlyActions::opens('host_read', ['host_read']));
    }

    public function testEverySessionActionIsOpenWithoutAReadingDeclaration(): void
    {
        foreach (LookOnlyActions::SESSION_ACTIONS as $action) {
            self::assertTrue(LookOnlyActions::opens($action, []), $action);
        }
    }

    public function testOtherActionsStayClosed(): void
    {
        self::assertFalse(LookOnlyActions::opens('host_write', ['host_read']));
        self::assertFalse(LookOnlyActions::opens(HilosSignalConstants::HILOS_LOGIN, []));
        self::assertFalse(LookOnlyActions::opens(HilosSignalConstants::HILOS_LEGAL_ACCEPT, []));
    }
}
