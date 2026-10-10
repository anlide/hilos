<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Item\PasskeyCredential;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\DTO\UserAddressVerifyDoneSignalData;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserEmailChangeDoneSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkDoneSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * A person's ways in and passkeys are edited by that person's agent, and created by the libraries
 * alone (HIL-1405).
 *
 * The users library keeps judging a sign-in and doing what follows it, but its claim over the ways
 * in and the passkeys now reaches creation and nothing else: a library that rewrites a password,
 * marks an address or records a key's use itself is refused by the truth source, while a password
 * born verified and a key born with its counter still go through. The same edits handed to the
 * person's agent are written, and the agent answers every frame - a refused one included. The
 * OAuth agent links a provider account and edits none.
 *
 * Every writer runs in its own frame and under the claims its own class declares, and the frames
 * between them are carried by the case.
 */
final class PersonSignInEditsIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Accept key standing in for the person's browser. */
    private const string ACCEPT_KEY = 'accept-sign-in-edits';

    /** Owner id the OAuth agent's claim is laid under; the class is abstract and never built here. */
    private const string OAUTH_AGENT_ID = 'integration_sign_in_edits_oauth';

    private const string PASSWORD = 'old-password-1405';

    private const string NEW_PASSWORD = 'new-password-1405';

    private const string EMAIL = 'ada@example.test';

    private const string NEW_EMAIL = 'ada.new@example.test';

    private const string TAKEN_EMAIL = 'grace@example.test';

    private const string PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----\n";

    private const int SIGN_COUNT = 5;

    private SignInEditsTestUsersLibrary $users;

    private ?SignalRouter $previousRouter = null;

    /**
     * @throws HilosException When the schema reset, the passkey table or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runPasskeyStub(down: true);
        self::runPasskeyStub(down: false);

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->users = new SignInEditsTestUsersLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
        OwnershipDeclaration::claimDb(AbstractOAuthAgent::class, self::OAUTH_AGENT_ID);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        foreach ([$this->users->getId(), self::OAUTH_AGENT_ID] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        Hilos::$sr = $this->previousRouter;
        self::runPasskeyStub(down: true);

        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheUsersLibraryEditingAWayInOrAPasskeyItselfIsRefused(): void
    {
        $userId = self::seedPerson();
        $passwordId = self::seedPassword($userId, self::EMAIL);
        $credential = self::seedPasskey($userId);

        $edits = [
            'a new password hash' => static fn () => Hilos::$db->identities[$passwordId]?->setPasswordHash(
                ObjectIdentity::hashPassword(self::NEW_PASSWORD),
            ),
            'the verified mark' => static fn () => Hilos::$db->identities[$passwordId]?->markVerified(),
            'a passkey use' => static fn () => $credential->recordUse(self::SIGN_COUNT + 1),
        ];
        foreach ($edits as $edit => $write) {
            try {
                $this->inLibrary($write);
                self::fail("The users library must not write {$edit} past the person's agent");
            } catch (WriteNotAllowedException $refusal) {
                self::assertStringContainsString($this->users->getId(), $refusal->getMessage(), $edit);
            }
        }

        self::assertTrue(Hilos::$db->identities->findPasswordByUser($userId)?->verifyPassword(self::PASSWORD));
        self::assertSame(
            [[IdentityType::PASSWORD, self::EMAIL, false], [IdentityType::PASSKEY, (string)$credential->credentialId, true]],
            self::waysInOf($userId),
        );
        self::assertSame([self::SIGN_COUNT, null], self::useOf($credential));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheUsersLibraryCreatesAWayInAndAPasskeyWhole(): void
    {
        $userId = self::seedPerson();

        $this->inLibrary(static fn () => Hilos::$db->identities->createPasswordIdentity(
            $userId,
            self::EMAIL,
            self::PASSWORD,
            verified: true,
        ));
        $credential = $this->inLibrary(static fn (): PasskeyCredential => self::seedPasskey($userId));

        self::assertSame(
            [[IdentityType::PASSWORD, self::EMAIL, true], [IdentityType::PASSKEY, (string)$credential->credentialId, true]],
            self::waysInOf($userId),
        );
        self::assertTrue(Hilos::$db->identities->findPasswordByUser($userId)?->verifyPassword(self::PASSWORD));
        self::assertSame([self::SIGN_COUNT, null], self::useOf($credential));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentWritesANewPasswordHash(): void
    {
        $userId = self::seedPerson();
        $passwordId = self::seedPassword($userId, self::EMAIL);

        $answer = $this->carry(HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE, new UserPasswordChangeSignalData(
            userId: $userId,
            identityId: $passwordId,
            passwordHash: ObjectIdentity::hashPassword(self::NEW_PASSWORD),
            signOutOthers: true,
            flowOpen: false,
            keepSessionId: 0,
            replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
            successMessage: null,
        ));

        self::assertInstanceOf(UserPasswordChangeDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertTrue($answer->ask->signOutOthers);
        $password = Hilos::$db->identities->findPasswordByUser($userId);
        self::assertTrue($password?->verifyPassword(self::NEW_PASSWORD));
        self::assertFalse($password?->verifyPassword(self::PASSWORD));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentMarksThePasswordOnAProvenAddress(): void
    {
        $userId = self::seedPerson();
        $passwordId = self::seedPassword($userId, self::EMAIL);

        $answer = $this->carry(HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY, new UserAddressVerifySignalData(
            $userId,
            $passwordId,
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE,
            self::ACCEPT_KEY,
            null,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK,
            null,
        ));

        self::assertInstanceOf(UserAddressVerifyDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertSame([[IdentityType::PASSWORD, self::EMAIL, true]], self::waysInOf($userId));
    }

    /**
     * The counter the library checked is checked again where it is written, so the second of two
     * assertions carrying the same counter - what a cloned key sends - is refused (HIL-1405).
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentRecordsAPasskeyUseAndRefusesTheSameCounterTwice(): void
    {
        $userId = self::seedPerson();
        $credential = self::seedPasskey($userId);
        $use = new UserPasskeyUseSignalData(
            userId: $userId,
            passkeyId: (int)$credential->id,
            signCount: self::SIGN_COUNT + 1,
            operation: null,
            sessionTokenHash: null,
            replySignal: HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM,
            successMessage: null,
        );

        $first = $this->carry(HilosSignalConstants::HILOS_USER_PASSKEY_USE, $use);
        self::assertInstanceOf(UserPasskeyUseDoneSignalData::class, $first);
        self::assertNull($first->error);
        [$signCount, $lastUsedAt] = self::useOf($credential);
        self::assertSame(self::SIGN_COUNT + 1, $signCount);
        self::assertNotNull($lastUsedAt);

        $second = $this->carry(HilosSignalConstants::HILOS_USER_PASSKEY_USE, $use);
        self::assertInstanceOf(UserPasskeyUseDoneSignalData::class, $second);
        self::assertNotNull($second->error);
        self::assertSame('WebAuthnVerificationException', $second->errorType);
        self::assertSame([self::SIGN_COUNT + 1, $lastUsedAt], self::useOf($credential));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentMovesTheAddressAndRefusesOneAnotherAccountHolds(): void
    {
        $userId = self::seedPerson();
        $strangerId = self::seedPerson();
        self::seedPassword($userId, self::EMAIL);
        self::seedPassword($strangerId, self::TAKEN_EMAIL);

        $moved = $this->carry(HilosSignalConstants::HILOS_USER_EMAIL_CHANGE, self::emailChange($userId, self::EMAIL, self::NEW_EMAIL));
        self::assertInstanceOf(UserEmailChangeDoneSignalData::class, $moved);
        self::assertNull($moved->error);
        self::assertSame([[IdentityType::PASSWORD, self::NEW_EMAIL, true]], self::waysInOf($userId));

        $refused = $this->carry(
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE,
            self::emailChange($userId, self::NEW_EMAIL, self::TAKEN_EMAIL),
        );
        self::assertInstanceOf(UserEmailChangeDoneSignalData::class, $refused);
        self::assertSame(AuthMessages::EMAIL_IN_USE, $refused->error);
        self::assertSame([[IdentityType::PASSWORD, self::NEW_EMAIL, true]], self::waysInOf($userId));
        self::assertSame([[IdentityType::PASSWORD, self::TAKEN_EMAIL, false]], self::waysInOf($strangerId));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentUnlinksAPasskeyWithItsCredential(): void
    {
        $userId = self::seedPerson();
        self::seedPassword($userId, self::EMAIL);
        $credential = self::seedPasskey($userId);

        $answer = $this->carry(HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK, new UserIdentityUnlinkSignalData(
            $userId,
            (int)$credential->identityId,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE,
            self::ACCEPT_KEY,
            'req-1',
            HilosSignalConstants::PROFILE_UNLINK_IDENTITY,
            null,
        ));

        self::assertInstanceOf(UserIdentityUnlinkDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertSame([[IdentityType::PASSWORD, self::EMAIL, false]], self::waysInOf($userId));
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_passkey_credential`')->rows());
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheOAuthAgentLinksAProviderAccountAndEditsNone(): void
    {
        $userId = self::seedPerson();
        self::seedPassword($userId, self::EMAIL);

        $linkId = $this->inOAuth(static fn (): ?int => Hilos::$db->identities->createOauthIdentity($userId, 'github', 'subject-1405')->id);
        self::assertNotNull($linkId);

        try {
            $this->inOAuth(static fn () => Hilos::$db->identities->deleteIdentity($userId, $linkId));
            self::fail('The OAuth agent must not remove a way in it linked');
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString(self::OAUTH_AGENT_ID, $refusal->getMessage());
        }

        self::assertCount(2, self::waysInOf($userId));
    }

    /**
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAFrameForAnotherPersonIsRefusedRatherThanWritten(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        $passwordId = self::seedPassword($otherId, self::EMAIL);

        $this->expectException(AgentException::class);

        $this->asPerson($userId, static fn ($agent) => $agent->onSignalAgent(
            new AgentSignalData(data: new UserAddressVerifySignalData(
                $otherId,
                $passwordId,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE,
                self::ACCEPT_KEY,
                null,
                HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK,
                null,
            )),
            '',
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY,
        ));
    }

    /**
     * Sends one ask the way the users library does, hands it to the person's agent and takes its answer.
     *
     * @param string $name Agent-signal name the ask travels under
     * @param HandoverAskInterface $ask The ask
     * @return mixed The answer's payload
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carry(string $name, HandoverAskInterface $ask): mixed
    {
        // What the fixtures announced on the way in is nobody's business here.
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $this->users->sendToAgent($name, $ask);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        while (($answer = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($answer->signalName->getName() === $ask->replySignal) {
                self::assertInstanceOf(AgentSignalData::class, $answer->data);

                return $answer->data->data;
            }
        }

        self::fail("Nothing was sent under {$ask->replySignal}");
    }

    /**
     * Runs one step in the users library's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the library
     * @return T Whatever the step returns
     */
    private function inLibrary(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId()), $step);
    }

    /**
     * Runs one step in the OAuth agent's frame, under its class's claim.
     *
     * @template T
     * @param callable(): T $step Step to run as the agent
     * @return T Whatever the step returns
     */
    private function inOAuth(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::OAUTH_AGENT_ID), $step);
    }

    /**
     * @param int $userId Person whose rows move
     * @param string $from Address the rows carry now
     * @param string $to Address they move to
     * @return UserEmailChangeSignalData The ask the library would send
     */
    private static function emailChange(int $userId, string $from, string $to): UserEmailChangeSignalData
    {
        return new UserEmailChangeSignalData(
            $userId,
            $from,
            $to,
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE,
            self::ACCEPT_KEY,
            'req-1',
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            null,
        );
    }

    /**
     * @return int Id of a new person row
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }

    /**
     * Seeds an unverified password the way the harness may, past every agent's claim.
     *
     * @param int $userId Owner
     * @param string $email Address the password signs in with
     * @return int Id of the password row
     * @throws HilosException When the row cannot be written
     */
    private static function seedPassword(int $userId, string $email): int
    {
        $id = Hilos::$db->identities->createPasswordIdentity($userId, $email, self::PASSWORD)->id;
        self::assertNotNull($id);

        return $id;
    }

    /**
     * Seeds a passkey the way the ceremony stores one: the anchor row, then the credential with its counter.
     *
     * @param int $userId Owner
     * @return PasskeyCredential The stored credential
     * @throws HilosException When a row cannot be written
     */
    private static function seedPasskey(int $userId): PasskeyCredential
    {
        $credentialId = RandomHelper::hex(16);
        $identityId = Hilos::$db->identities->createPasskeyIdentity($userId, $credentialId)->id;
        self::assertNotNull($identityId);

        return Hilos::$db->passkeyCredentials->createFromRegistration(
            $identityId,
            $userId,
            $credentialId,
            self::PUBLIC_KEY_PEM,
            PasskeyAlgorithm::Es256,
            self::SIGN_COUNT,
            null,
            null,
            RandomHelper::hex(16),
            null,
        );
    }

    /**
     * @param int $userId Owner
     * @return list<array{0: string, 1: string, 2: bool}> Type, identifier and verified mark of each way in, by id
     * @throws DatabaseException When the query fails
     */
    private static function waysInOf(int $userId): array
    {
        Database::sql('SELECT `type`, `identifier`, `verified` FROM `hilos_identity` WHERE `user_id` = ? ORDER BY `id`', [$userId]);

        return array_map(
            static fn (array $row): array => [(string)$row['type'], (string)$row['identifier'], (bool)$row['verified']],
            Database::rows(),
        );
    }

    /**
     * @param PasskeyCredential $credential Credential to read
     * @return array{0: int, 1: ?string} Counter and last use stored now, past every loaded row
     * @throws DatabaseException When the query fails
     */
    private static function useOf(PasskeyCredential $credential): array
    {
        Database::sql('SELECT `sign_count`, `last_used_at` FROM `hilos_passkey_credential` WHERE `id` = ?', [(int)$credential->id]);
        $row = Database::row();
        self::assertNotNull($row);

        return [(int)$row['sign_count'], $row['last_used_at'] === null ? null : (string)$row['last_used_at']];
    }

    /**
     * Raises or drops the passkey table the session integration base does not otherwise need.
     *
     * @param bool $down Drop the table when true, create it when false
     * @throws DatabaseException When the stub statement fails
     */
    private static function runPasskeyStub(bool $down): void
    {
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_hilos_passkey_credential{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
    }
}

/** The framework's users library under a test name, with nothing overridden. */
final class SignInEditsTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_sign_in_edits_users_library';
}
