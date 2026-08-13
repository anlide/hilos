<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Main\RegisterActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the email+password registration handler (HIL-164): a
 * valid submission creates a user row and its `password` identity, stores a
 * verifiable bcrypt hash inside the identity layer, and (auto-login default)
 * upgrades the anonymous session to the new user; a taken email is rejected.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPageRegisterTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string PASSWORD = 'correct horse battery';

    /**
     * A valid registration creates a user + password identity and logs it in.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testRegisterCreatesUserAndPasswordIdentity(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'reg-ak');

        try {
            $this->register($agent, 'reg-ak', $email);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity);
            $userId = $identity->userId;
            $this->assertNotNull($userId);
            $this->assertFalse($identity->verified);

            // The hash never leaves the identity layer: the object surface omits it (HIL-370, area 1e).
            $this->assertArrayNotHasKey('secret', $identity->toArray());

            $this->assertSame($this->localPart($email), Hilos::$db->users[$userId]?->name);
            $this->assertSame($userId, $this->sessionOf('reg-ak')?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['reg-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Registration stores a current-cost bcrypt hash that verifies the password.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testRegisterStoresVerifiableBcryptHash(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'reg-hash-ak');

        try {
            $this->register($agent, 'reg-hash-ak', $email);

            $storedHash = $this->readSecret($email);
            $this->assertIsString($storedHash);
            $this->assertNotSame(self::PASSWORD, $storedHash);
            $this->assertTrue(password_verify(self::PASSWORD, $storedHash));
            $this->assertFalse(password_needs_rehash($storedHash, PASSWORD_DEFAULT));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A second registration with a taken email is rejected (UNIQUE (type, identifier)).
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testDuplicateEmailIsRejected(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'dup-first-ak');
        $this->register($agent, 'dup-first-ak', $email);

        $this->openSession($agent, 'dup-second-ak');

        try {
            $this->expectException(DuplicateValueException::class);
            $this->expectExceptionMessage('email already used');

            $this->register($agent, 'dup-second-ak', $email);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Registers the truth sources and signal router the register path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'listener-ak',
            PageConstants::MAIN,
            [],
        ));

        return new ChatAgent();
    }

    /**
     * Opens an anonymous session and connection for an accept key and marks it current.
     *
     * @param ChatAgent $agent Agent under test
     * @param string $acceptKey WebSocket accept key to open the session under
     * @return string The session cookie token registered for the connection
     * @throws HilosException When the handshake fails
     */
    private function openSession(ChatAgent $agent, string $acceptKey): string
    {
        $token = RandomHelper::hex(16);
        $agent->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $token,
            ),
            '',
            '',
        );
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
    }

    /**
     * Dispatches a register action through the main page for the current connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email (used verbatim as the confirmation matches)
     * @throws HilosException When the register handler rejects the action
     */
    private function register(ChatAgent $agent, string $acceptKey, string $email): void
    {
        new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::REGISTER,
            new RegisterActionDTO($email, self::PASSWORD, self::PASSWORD),
        );
    }

    /**
     * Reads the stored password hash for a `password` identity by email.
     *
     * @param string $email Identity identifier
     * @return ?string Stored secret hash or null when absent
     * @throws HilosException When the lookup query fails
     */
    private function readSecret(string $email): ?string
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($email));
        $resultSet = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '` '
            . 'WHERE `' . EntityIdentity::type . '` = ? AND `' . EntityIdentity::identifier . '` = ?',
            $params,
        )->first();
        $row = $resultSet?->first();
        $secret = is_array($row) ? ($row[EntityIdentity::secret] ?? null) : null;

        return is_string($secret) ? $secret : null;
    }

    /**
     * Returns the local part of an email (the default display name on register).
     *
     * @param string $email Account email
     * @return string Substring before the first `@`
     */
    private function localPart(string $email): string
    {
        $atPosition = strpos($email, '@');

        return $atPosition === false ? $email : substr($email, 0, $atPosition);
    }

    /**
     * Builds a unique lowercase email for one test.
     *
     * @return string Unique email identifier
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }
}
