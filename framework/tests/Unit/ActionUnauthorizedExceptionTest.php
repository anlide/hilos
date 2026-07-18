<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\PageException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the action-level auth seam: requireUser() returns the id of an
 * authenticated session and throws for an anonymous one, carrying 401 semantics.
 */
final class ActionUnauthorizedExceptionTest extends TestCase
{
    public function testRequireUserReturnsAuthenticatedUserId(): void
    {
        $this->assertSame(42, ActionUnauthorizedException::requireUser(42));
    }

    public function testRequireUserThrowsForAnonymousSession(): void
    {
        $this->expectException(ActionUnauthorizedException::class);
        ActionUnauthorizedException::requireUser(null);
    }

    public function testExceptionIsAPageSubsystemErrorWith401Code(): void
    {
        $exception = new ActionUnauthorizedException();

        $this->assertInstanceOf(PageException::class, $exception);
        $this->assertSame(401, $exception->getCode());
        $this->assertSame('unauthorized', $exception->errorCode);
    }
}
