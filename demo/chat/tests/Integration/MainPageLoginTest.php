<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Main\LoginActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\ValidationException;
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
 * Integration tests for the email+password login handler (HIL-162): a valid
 * login promotes the anonymous session to its user, invalid credentials fail
 * with a single generic message, and an outdated hash is rehashed on success.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPageLoginTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string PASSWORD = 'correct horse battery';

    /**
     * A valid email+password binds the session to the identity's user.
     *
     * @throws HilosException When setup or login handling fails
     */
    public function testValidCredentialsBindSessionToUser(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $userId = $this->seedUserWithPassword($email, self::PASSWORD, password_hash(self::PASSWORD, PASSWORD_DEFAULT));
        $token = $this->openSession($agent, 'login-ak');

        try {
            $this->login($agent, 'login-ak', $email, self::PASSWORD);

            $this->assertSame($userId, Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['login-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A wrong password fails generically and leaves the session anonymous.
     *
     * @throws HilosException When setup or login handling fails
     */
    public function testWrongPasswordIsRejectedAndSessionStaysAnonymous(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email, self::PASSWORD, password_hash(self::PASSWORD, PASSWORD_DEFAULT));
        $token = $this->openSession($agent, 'wrong-ak');

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Invalid email or password');

            $this->login($agent, 'wrong-ak', $email, 'not the password');
        } finally {
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * An unknown email fails with the same generic message (no enumeration).
     *
     * @throws HilosException When setup or login handling fails
     */
    public function testUnknownEmailIsRejectedGenerically(): void
    {
        $agent = $this->bootAgent();
        $token = $this->openSession($agent, 'unknown-ak');

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Invalid email or password');

            $this->login($agent, 'unknown-ak', $this->uniqueEmail(), self::PASSWORD);
        } finally {
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A successful login silently upgrades an outdated (low-cost) hash.
     *
     * @throws HilosException When setup or login handling fails
     */
    public function testOutdatedHashIsRehashedOnLogin(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $outdatedHash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->seedUserWithPassword($email, self::PASSWORD, $outdatedHash);
        $this->openSession($agent, 'rehash-ak');
        $this->assertTrue(password_needs_rehash($outdatedHash, PASSWORD_DEFAULT));

        try {
            $this->login($agent, 'rehash-ak', $email, self::PASSWORD);

            $storedHash = $this->readSecret($email);
            $this->assertNotSame($outdatedHash, $storedHash);
            $this->assertFalse(password_needs_rehash((string)$storedHash, PASSWORD_DEFAULT));
            $this->assertTrue(password_verify(self::PASSWORD, (string)$storedHash));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Registers the truth sources and signal router the login path needs.
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
     * Dispatches a login action through the main page for the current connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @param string $password Submitted password
     * @throws HilosException When the login handler rejects the action
     */
    private function login(ChatAgent $agent, string $acceptKey, string $email, string $password): void
    {
        (new MainPage($agent))->onAction(
            $acceptKey,
            ChatSignalConstants::LOGIN,
            new LoginActionDTO($email, $password),
        );
    }

    /**
     * Creates a user and its `password` identity with the given stored hash.
     *
     * @param string $email Account email (also the identity identifier)
     * @param string $password Plaintext password (unused by the row, kept for intent)
     * @param string $secretHash Precomputed hash to store in the identity secret
     * @return int New user id
     * @throws HilosException When user creation or the identity insert fails
     */
    private function seedUserWithPassword(string $email, string $password, string $secretHash): int
    {
        $userId = (int)Hilos::$db->users->actions->register(RandomHelper::hex(16))->id;

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($email));
        $params->add(SqlParam::string($secretHash));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, '
            . '`' . EntityIdentity::identifier . '`, `' . EntityIdentity::secret . '`, '
            . '`' . EntityIdentity::verified . '`) VALUES (?, ?, ?, ?, 1)',
            $params,
        );

        return $userId;
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
     * Builds a unique lowercase email for one test.
     *
     * @return string Unique email identifier
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }
}
