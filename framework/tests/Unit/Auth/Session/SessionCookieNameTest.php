<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\SessionCookieName;
use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for session cookie name resolution and derivation (HIL-904).
 */
final class SessionCookieNameTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv('HILOS_SESSION_COOKIE_NAME');
        putenv('DB_DATABASE');
        putenv('DB_NAME');
    }

    public function testExplicitOverrideIsReturnedVerbatimAndNotDigested(): void
    {
        putenv('HILOS_SESSION_COOKIE_NAME=custom_session_cookie');

        $this->assertSame('custom_session_cookie', SessionCookieName::resolve());
    }

    public function testEmptyOverrideDerives(): void
    {
        putenv('HILOS_SESSION_COOKIE_NAME=');
        putenv('DB_DATABASE=test_database');

        $resolved = SessionCookieName::resolve();

        $this->assertSame(SessionCookieName::derive('test_database'), $resolved);
        $this->assertStringStartsWith(SessionCookieName::DERIVED_PREFIX, $resolved);
    }

    public function testSameDatabaseNameAlwaysDerivesTheSameValue(): void
    {
        $first = SessionCookieName::derive('test_db');
        $second = SessionCookieName::derive('test_db');

        $this->assertSame($first, $second);
        $expected = SessionCookieName::DERIVED_PREFIX . substr(hash('sha256', 'test_db'), 0, 8);
        $this->assertSame($expected, $first);
    }

    public function testThreeDemoDatabaseNamesDeriveThreeDifferentValues(): void
    {
        $chat = SessionCookieName::derive('hilos-demo-chat');
        $tasks = SessionCookieName::derive('hilos-demo-tasks');
        $polls = SessionCookieName::derive('hilos-demo-polls');

        $this->assertNotSame($chat, $tasks);
        $this->assertNotSame($tasks, $polls);
        $this->assertNotSame($chat, $polls);
    }

    public function testDbDatabaseOutranksDbNameAndDbNameIsUsedWhenDbDatabaseIsEmpty(): void
    {
        putenv('HILOS_SESSION_COOKIE_NAME=');

        // DB_DATABASE outranks DB_NAME
        putenv('DB_DATABASE=primary_db');
        putenv('DB_NAME=fallback_db');
        $this->assertSame(SessionCookieName::derive('primary_db'), SessionCookieName::resolve());

        // DB_NAME is used when DB_DATABASE is empty
        putenv('DB_DATABASE=');
        putenv('DB_NAME=fallback_db');
        $this->assertSame(SessionCookieName::derive('fallback_db'), SessionCookieName::resolve());
    }

    public function testSessionRotationTicketCookieNameOverDerivedNameYieldsNamePlusRotate(): void
    {
        putenv('HILOS_SESSION_COOKIE_NAME=');
        putenv('DB_DATABASE=test_db');

        $derivedName = SessionCookieName::resolve();

        $this->assertSame($derivedName . '_rotate', SessionRotationTicket::cookieName($derivedName));
    }
}
