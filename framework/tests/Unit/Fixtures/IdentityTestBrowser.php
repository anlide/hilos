<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fixtures;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\PageAccessGate;

/**
 * Browser context whose every connection is one given person - an admin or not - or a session
 * without an account.
 *
 * Stands in for a project's identity where a test needs the page gate ({@see PageAccessGate}) to
 * answer as it would for that person: a frame of an ADMIN page leaves only for a connection the gate
 * lets through, and without an identity nobody is.
 *
 * Not final: a fixture that records what a page delivered extends it, so the connection it records
 * for is somebody.
 */
class IdentityTestBrowser extends BrowserContext
{
    /**
     * @param ?int $userId User behind every connection, or null for a session without an account
     * @param bool $admin Whether that user is an admin
     */
    public function __construct(
        private readonly ?int $userId,
        private readonly bool $admin,
    ) {
        parent::__construct();
    }

    /**
     * Returns the injected user as a settled identity, standing in for the connection registry.
     *
     * @param string $acceptKey Connection accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity carrying the injected user id
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->userId);
    }

    /**
     * Returns the injected admin verdict, standing in for the project's admin flag.
     *
     * @param int $userId Authenticated durable user id (unused in the fixture)
     * @return bool Injected admin verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }
}
