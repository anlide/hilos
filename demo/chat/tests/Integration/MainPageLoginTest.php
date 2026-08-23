<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Database;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;

/**
 * Integration tests for the email+password login handler (HIL-162): a valid
 * login promotes the anonymous session to its user, an outdated hash is rehashed
 * on success, and each of the three ways a sign-in fails says which one it was
 * (HIL-414 - the epic traded the single generic sentence for the live lookup that
 * answers the same question outright). The password-reset request is here for the
 * same reason: it carried the second anti-enumeration stub, and its refusal is now
 * part of the same story.
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

            $this->assertSame($userId, $this->sessionOf('login-ak')?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['login-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A wrong password says so and leaves the session anonymous.
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
            $this->expectExceptionMessage('Incorrect password');

            $this->login($agent, 'wrong-ak', $email, 'not the password');
        } finally {
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * An unknown email says the address has no account (HIL-414, all-in messaging).
     *
     * @throws HilosException When setup or login handling fails
     */
    public function testUnknownEmailIsRejectedAsUnknown(): void
    {
        $agent = $this->bootAgent();
        $token = $this->openSession($agent, 'unknown-ak');

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('No account found for this email');

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
     * An account that was never given a password is told so, not that its password is wrong.
     *
     * The third of the three sentences: an account built by a link, a provider or a
     * phone has no password, and blaming the password sends somebody to a recovery
     * flow that has nothing to recover.
     *
     * @throws HilosException When setup or login handling fails
     */
    public function testPasswordlessAccountIsToldItHasNoPassword(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithoutPassword($email);
        $token = $this->openSession($agent, 'nopw-ak');

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('This account has no password');

            $this->login($agent, 'nopw-ak', $email, self::PASSWORD);
        } finally {
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A reset asked for an address with no password is refused out loud, not answered silently.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testResetIsRefusedForAnAddressWithNoPassword(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithoutPassword($email);

        $this->openSession($agent, 'reset-refused-ak');

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('No password to reset for this email');

            $this->requestPasswordReset($agent, 'reset-refused-ak', $email);
        } finally {
            $this->assertNull($this->activeResetChallenge($email), 'Nothing is issued for an address it refuses');
            $this->clearRecoveryWaiters();
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * An account that does have a password still gets its reset code.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testResetIssuesACodeForAnAccountWithAPassword(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email, self::PASSWORD, password_hash(self::PASSWORD, PASSWORD_DEFAULT));

        $this->openSession($agent, 'reset-ok-ak');

        try {
            $this->requestPasswordReset($agent, 'reset-ok-ak', $email);

            $this->assertNotNull($this->activeResetChallenge($email));
            $this->assertCount(
                1,
                Hilos::$rt->hilosRecoveryWaiters->forIdentifier($email),
                'The asking session waits on the code it just asked for',
            );
        } finally {
            $this->clearRecoveryWaiters();
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Empties the recovery waiters a case parked, so the next one starts on an empty stand.
     *
     * @throws HilosException When the runtime write fails
     */
    private function clearRecoveryWaiters(): void
    {
        $parked = [];
        foreach (Hilos::$rt->hilosRecoveryWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }
        foreach ($parked as $acceptKey) {
            Hilos::$rt->hilosRecoveryWaiters->actions->release($acceptKey);
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
        RtTruthSourceRegistry::register(StateRecoveryWaiter::RT_COLLECTION, true, self::TEST_AGENT_ID);
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
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_LOGIN,
            new LoginActionDTO($email, $password),
        );
        $this->deliverLibraryFrames($agent);
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
        $userId = (int)Hilos::$db->users->actions->createWithName('User')->id;

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
     * Dispatches a password-reset request through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @throws HilosException When the reset handler rejects the action
     */
    private function requestPasswordReset(ChatAgent $agent, string $acceptKey, string $email): void
    {
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET,
            new RequestPasswordResetActionDTO($email),
        );
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Creates a user whose only identity is a verified address - an account with no password.
     *
     * @param string $email Account email (also the identity identifier)
     * @return int New user id
     * @throws HilosException When user creation or the identity insert fails
     */
    private function seedUserWithoutPassword(string $email): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('User')->id;

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string(IdentityType::MAGIC_LINK));
        $params->add(SqlParam::string($email));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, '
            . '`' . EntityIdentity::identifier . '`, `' . EntityIdentity::verified . '`) '
            . 'VALUES (?, ?, ?, 1)',
            $params,
        );

        return $userId;
    }

    /**
     * Reads the live password-reset challenge for an address, if one was issued.
     *
     * @param string $email Address a reset was asked for
     * @return ?ObjectUserVerification Live challenge, or null when none was issued
     * @throws HilosException When the lookup fails
     */
    private function activeResetChallenge(string $email): ?ObjectUserVerification
    {
        /** @var ObjectUserVerifications $verifications */
        $verifications = Hilos::$db->getObjectCollection(HilosDbContext::verifications);

        return $verifications->findActive(
            VerificationType::PASSWORD_RESET,
            $email,
            max(1, Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS)),
        );
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
