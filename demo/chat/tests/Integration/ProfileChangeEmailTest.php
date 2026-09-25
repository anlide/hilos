<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\ConfirmEmailChangeCurrentCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\ConfirmEmailChangeNewCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestEmailChangeCurrentCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestEmailChangeNewCodeActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\EmailChangedMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the profile email change (HIL-299): four submits, one proof carried
 * between them, and the account moved only on the last one.
 *
 * Step 1 mails a code to the address the account holds; step 2 checks it without spending
 * it; step 3 judges the new address and mails it a code, carrying the first code as proof;
 * step 4 spends the new address's code, then the proof, moves every password and sign-in-link
 * row of the old address inside one transaction, and notifies both addresses. What is pinned
 * here is the ORDER: which refusal spends what, so that a typo costs nothing and a lost race
 * changes nothing.
 *
 * Codes are seeded with a known value through the verifications object collection, as the
 * other profile code flows are tested; the letters are caught by swapping the mailer for one
 * that records instead of queueing. Requires the test DB reset before run
 * (composer run test:db-reset).
 */
final class ProfileChangeEmailTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string CURRENT_CODE = '424242';
    private const string NEW_CODE = '535353';
    private const string WRONG_CODE = '000000';
    private const string PASSWORD = 'a-long-enough-secret';
    private const string RESTART = StepUpMessages::EXPIRED;
    private const int MAX_ATTEMPTS = 5;
    private const int TTL_SECONDS = 3600;

    private ?HilosMailer $previousMailer = null;

    private EmailChangeRecordingMailer $mailer;

    /**
     * @throws HilosException When the parent setup fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousMailer = Hilos::$mail;
        $this->mailer = new EmailChangeRecordingMailer();
        Hilos::$mail = $this->mailer;
    }

    /**
     * @throws HilosException When the parent teardown fails
     */
    protected function tearDown(): void
    {
        Hilos::$mail = $this->previousMailer;
        Hilos::$rt->connections->actions->clear();

        parent::tearDown();
    }

    /**
     * Step 1 issues an `email_change_current` code to the account's verified address, and mails it.
     *
     * @throws HilosException When setup or the request handler fails
     */
    public function testStepOneMailsACodeToTheVerifiedAddress(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-1-ak');

        $this->submit('change-1-ak', ChatSignalConstants::CHANGE_EMAIL_CURRENT_REQUEST, new RequestEmailChangeCurrentCodeActionDTO());

        $challenge = $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, $current, self::MAX_ATTEMPTS);
        $this->assertNotNull($challenge, 'The current address must be issued a code');
        $this->assertSame($userId, $challenge->userId);
        $this->assertSame(
            [[$current, MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE_CURRENT]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE_CURRENT),
        );
    }

    /**
     * An account with no verified address has no current mailbox to prove.
     *
     * @throws HilosException When setup fails
     */
    public function testStepOneRefusesAnAccountWithoutAVerifiedAddress(): void
    {
        $agent = $this->bootAgent();
        $token = $this->openSession($agent, 'change-none-ak');
        $userId = (int)Hilos::$db->users->actions->createWithName('Phone User')->id;
        $this->authenticateSession($agent, $token, $userId, null);
        $this->confirmStepUp('change-none-ak', $userId);

        $this->assertRefused(
            'Confirm an email address first',
            'change-none-ak',
            ChatSignalConstants::CHANGE_EMAIL_CURRENT_REQUEST,
            new RequestEmailChangeCurrentCodeActionDTO(),
        );
        $this->assertSame([], $this->mailer->sent);
    }

    /**
     * The right code passes step 2 and stays alive, because steps 3 and 4 carry it.
     *
     * @throws HilosException When setup or the confirm handler fails
     */
    public function testStepTwoAcceptsTheRightCodeWithoutSpendingIt(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-2-ak');
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $userId, self::CURRENT_CODE);

        $this->submit(
            'change-2-ak',
            ChatSignalConstants::CHANGE_EMAIL_CURRENT_CONFIRM,
            new ConfirmEmailChangeCurrentCodeActionDTO(self::CURRENT_CODE),
        );

        $this->assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, $current, self::MAX_ATTEMPTS),
            'A proven code must survive step 2',
        );
    }

    /**
     * A password account must freshly confirm the email-change operation before step 1.
     *
     * @throws HilosException When setup fails
     */
    public function testStepOneRefusesAPasswordAccountWithoutStepUpConfirmation(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-password-unconfirmed-ak');
        Hilos::$db->identities->createPasswordIdentity($userId, $current, self::PASSWORD)->markVerified();

        $this->assertRefused(
            StepUpMessages::EXPIRED,
            'change-password-unconfirmed-ak',
            ChatSignalConstants::CHANGE_EMAIL_CURRENT_REQUEST,
            new RequestEmailChangeCurrentCodeActionDTO(),
        );
    }

    /**
     * A wrong code on step 2 is answered with the one generic sentence.
     *
     * @throws HilosException When setup fails
     */
    public function testStepTwoRefusesAWrongCode(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-2-bad-ak');
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $userId, self::CURRENT_CODE);

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            'change-2-bad-ak',
            ChatSignalConstants::CHANGE_EMAIL_CURRENT_CONFIRM,
            new ConfirmEmailChangeCurrentCodeActionDTO(self::WRONG_CODE),
        );
    }

    /**
     * A malformed address, the account's own, and another account's are refused with no code mailed.
     *
     * None of the three spends the proof: none of them could have been changed by a code.
     *
     * @throws HilosException When setup fails
     */
    public function testStepThreeRefusesAnAddressItCannotMoveToWithoutMailingIt(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-3-bad-ak');
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $userId, self::CURRENT_CODE);
        $taken = $this->uniqueEmail();
        $this->insertVerifiedIdentity((int)Hilos::$db->users->actions->createWithName('Owner')->id, $taken);

        $cases = [
            'not-an-address' => 'Enter a valid email address',
            strtoupper($current) => 'That is already your address',
            $taken => 'That email is already in use',
        ];
        foreach ($cases as $address => $message) {
            $this->assertRefused(
                $message,
                'change-3-bad-ak',
                ChatSignalConstants::CHANGE_EMAIL_NEW_REQUEST,
                new RequestEmailChangeNewCodeActionDTO(self::CURRENT_CODE, $address),
            );
            $this->assertNull(
                $this->verifications()->findActive(VerificationType::EMAIL_CHANGE, strtolower($address), self::MAX_ATTEMPTS),
                "{$address} must not be mailed a code",
            );
        }

        $this->assertSame([], $this->mailer->sent);
        $this->assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, $current, self::MAX_ATTEMPTS),
            'An address refusal must not spend the proof',
        );
    }

    /**
     * With the proof of the current address gone, step 3 asks the person to start over.
     *
     * @throws HilosException When setup fails
     */
    public function testStepThreeWithoutALiveProofAsksToStartAgain(): void
    {
        $this->signInWithVerifiedEmail('change-3-dead-ak');
        $email = $this->uniqueEmail();

        $this->assertRefused(
            self::RESTART,
            'change-3-dead-ak',
            ChatSignalConstants::CHANGE_EMAIL_NEW_REQUEST,
            new RequestEmailChangeNewCodeActionDTO(self::CURRENT_CODE, $email),
        );
        $this->assertNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE, $email, self::MAX_ATTEMPTS));
    }

    /**
     * A free address with a live proof gets an `email_change` code carrying the session user.
     *
     * @throws HilosException When setup or the request handler fails
     */
    public function testStepThreeMailsACodeToTheNewAddress(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-3-ak');
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $userId, self::CURRENT_CODE);
        $email = $this->uniqueEmail();

        $this->submit(
            'change-3-ak',
            ChatSignalConstants::CHANGE_EMAIL_NEW_REQUEST,
            new RequestEmailChangeNewCodeActionDTO(self::CURRENT_CODE, strtoupper($email)),
        );

        $challenge = $this->verifications()->findActive(VerificationType::EMAIL_CHANGE, $email, self::MAX_ATTEMPTS);
        $this->assertNotNull($challenge, 'The new address must be issued a code');
        $this->assertSame($userId, $challenge->userId);
        $this->assertSame(
            [[$email, MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE),
        );
    }

    /**
     * Step 4 spends both codes, moves the password and link rows, and notifies both addresses.
     *
     * @throws HilosException When setup or the confirm handler fails
     */
    public function testStepFourMovesTheAccountAndNotifiesBothAddresses(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-4-ak');
        Hilos::$db->identities->createPasswordIdentity($userId, $current, self::PASSWORD)->markVerified();
        $this->confirmStepUp('change-4-ak', $userId);
        $email = $this->seedBothCodes($userId, $current);

        $this->submit(
            'change-4-ak',
            ChatSignalConstants::CHANGE_EMAIL_NEW_CONFIRM,
            new ConfirmEmailChangeNewCodeActionDTO(self::CURRENT_CODE, $email, self::NEW_CODE),
        );

        $this->assertNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, $current, self::MAX_ATTEMPTS));
        $this->assertNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE, $email, self::MAX_ATTEMPTS));
        $this->assertSame(
            [
                [IdentityType::MAGIC_LINK, $email, true],
                [IdentityType::PASSWORD, $email, true],
            ],
            self::rowsOf($userId),
        );
        $this->assertTrue(Hilos::$db->identities->findPasswordByUser($userId)?->verifyPassword(self::PASSWORD));

        $params = [EmailChangedMailTemplate::PARAM_WAS => $current, EmailChangedMailTemplate::PARAM_NOW => $email];
        $this->assertSame(
            [
                ['to' => $current, 'templateKey' => MailTemplateCatalogConstants::ACCOUNT_EMAIL_CHANGED, 'params' => $params],
                ['to' => $email, 'templateKey' => MailTemplateCatalogConstants::ACCOUNT_EMAIL_CHANGED, 'params' => $params],
            ],
            $this->mailer->sent,
        );
    }

    /**
     * A wrong code from the new address leaves the proof alive, so the typo can be corrected.
     *
     * @throws HilosException When setup fails
     */
    public function testStepFourWithAWrongNewCodeKeepsTheProofAndTheAddress(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-4-bad-ak');
        $email = $this->seedBothCodes($userId, $current);

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            'change-4-bad-ak',
            ChatSignalConstants::CHANGE_EMAIL_NEW_CONFIRM,
            new ConfirmEmailChangeNewCodeActionDTO(self::CURRENT_CODE, $email, self::WRONG_CODE),
        );

        $this->assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, $current, self::MAX_ATTEMPTS),
            'A typo in the new code must not spend the proof',
        );
        $this->assertSame([[IdentityType::MAGIC_LINK, $current, true]], self::rowsOf($userId));
        $this->assertSame([], $this->mailer->sent);
    }

    /**
     * A proof another tab already spent asks the person to start over, and nothing moves.
     *
     * @throws HilosException When setup fails
     */
    public function testStepFourWithAProofSpentElsewhereAsksToStartAgain(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-4-spent-ak');
        $email = $this->seedBothCodes($userId, $current);
        $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, $current, self::MAX_ATTEMPTS)?->consume();

        $this->assertRefused(
            self::RESTART,
            'change-4-spent-ak',
            ChatSignalConstants::CHANGE_EMAIL_NEW_CONFIRM,
            new ConfirmEmailChangeNewCodeActionDTO(self::CURRENT_CODE, $email, self::NEW_CODE),
        );

        $this->assertSame([[IdentityType::MAGIC_LINK, $current, true]], self::rowsOf($userId));
        $this->assertSame([], $this->mailer->sent);
    }

    /**
     * An address another account took between the steps is refused before either code is spent.
     *
     * @throws HilosException When setup fails
     */
    public function testStepFourWithTheAddressTakenMeanwhileSpendsNoCode(): void
    {
        [$userId, $current] = $this->signInWithVerifiedEmail('change-4-taken-ak');
        $email = $this->seedBothCodes($userId, $current);
        $this->insertVerifiedIdentity((int)Hilos::$db->users->actions->createWithName('Faster')->id, $email);

        $this->assertRefused(
            'That email is already in use',
            'change-4-taken-ak',
            ChatSignalConstants::CHANGE_EMAIL_NEW_CONFIRM,
            new ConfirmEmailChangeNewCodeActionDTO(self::CURRENT_CODE, $email, self::NEW_CODE),
        );

        $this->assertNotNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, $current, self::MAX_ATTEMPTS));
        $this->assertNotNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE, $email, self::MAX_ATTEMPTS));
        $this->assertSame([[IdentityType::MAGIC_LINK, $current, true]], self::rowsOf($userId));
    }

    /**
     * Opens a session signed in as a fresh account whose verified address is a sign-in-link row.
     *
     * @param string $acceptKey WebSocket accept key of the session
     * @return array{0: int, 1: string} Account id and its current address
     * @throws HilosException When setup fails
     */
    private function signInWithVerifiedEmail(string $acceptKey): array
    {
        $agent = $this->bootAgent();
        $token = $this->openSession($agent, $acceptKey);
        $userId = (int)Hilos::$db->users->actions->createWithName('Email User')->id;
        $this->authenticateSession($agent, $token, $userId, null);
        $current = $this->uniqueEmail();
        $this->insertVerifiedIdentity($userId, $current);

        return [$userId, $current];
    }

    /**
     * Seeds the live proof of the current address and the code of a fresh new one.
     *
     * @param int $userId Owning user id
     * @param string $current Current address
     * @return string The new address its code was seeded for
     * @throws HilosException When a challenge insert fails
     */
    private function seedBothCodes(int $userId, string $current): string
    {
        $email = $this->uniqueEmail();
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, $current, $userId, self::CURRENT_CODE);
        $this->seedCode(VerificationType::EMAIL_CHANGE, $email, $userId, self::NEW_CODE);

        return $email;
    }

    /**
     * Seeds the operation confirmation the real step-up command would write.
     *
     * @param string $acceptKey Connection whose browser session is confirmed
     * @param int $userId Person confirming the operation
     * @throws HilosException When the session lookup or confirmation write fails
     */
    private function confirmStepUp(string $acceptKey, int $userId): void
    {
        $session = $this->sessionOf($acceptKey);
        $this->assertNotNull($session);
        Hilos::$db->stepUps->actions->confirm(
            ProtectedModeRuntime::hashSessionToken($session->token),
            $userId,
            StepUpOperationKey::CHANGE_EMAIL,
            date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        );
    }

    /**
     * Submits one profile action on behalf of an accept key.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @throws HilosException When the handler fails
     */
    private function submit(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        $this->usersLibrary()->onAgentAction($acceptKey, $action, $dto);
    }

    /**
     * Submits an action and asserts it is refused with exactly the given sentence.
     *
     * @param string $message Expected refusal
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @throws HilosException When the handler fails for another reason
     */
    private function assertRefused(string $message, string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            $this->submit($acceptKey, $action, $dto);
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail("{$action} must be refused with: {$message}");
    }

    /**
     * Registers the truth sources and signal router the profile path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();

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
        $this->deliverHandshake($agent, new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        ));
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
    }

    /**
     * Seeds an active challenge with a known code.
     *
     * @param string $type Verification type
     * @param string $email Target address
     * @param int $userId Owning user id carried on the challenge
     * @param string $code Plaintext code
     * @throws HilosException When the challenge insert fails
     */
    private function seedCode(string $type, string $email, int $userId, string $code): void
    {
        $this->verifications()->createChallenge($type, $email, $userId, $code, self::TTL_SECONDS);
    }

    /**
     * Inserts a verified sign-in-link identity carrying an address.
     *
     * @param int $userId Owning user id
     * @param string $email Verified email identifier
     * @throws HilosException When the insert query fails
     */
    private function insertVerifiedIdentity(int $userId, string $email): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string(IdentityType::MAGIC_LINK));
        $params->add(SqlParam::string($email));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, `'
            . EntityIdentity::identifier . '`, `' . EntityIdentity::verified . '`) VALUES (?, ?, ?, 1)',
            $params,
        );
    }

    /**
     * Reads an account's identity rows straight from the table.
     *
     * @param int $userId Owning user id
     * @return list<array{0: string, 1: string, 2: bool}> Type, identifier and proven flag of each row, by id
     * @throws HilosException When the query fails
     */
    private static function rowsOf(int $userId): array
    {
        Database::sql(
            'SELECT `' . EntityIdentity::type . '`, `' . EntityIdentity::identifier . '`, `' . EntityIdentity::verified . '`'
            . ' FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::user_id . '` = ? ORDER BY `' . EntityIdentity::id . '`',
            [$userId],
        );

        return array_map(
            static fn (array $row): array => [
                (string)$row[EntityIdentity::type],
                (string)$row[EntityIdentity::identifier],
                (bool)$row[EntityIdentity::verified],
            ],
            Database::rows(),
        );
    }

    /**
     * Resolves the framework verifications object collection for seeding challenges.
     *
     * @return ObjectUserVerifications Verifications persistence primitives
     * @throws HilosException When the collection is not configured
     */
    private function verifications(): ObjectUserVerifications
    {
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::verifications);
        $this->assertInstanceOf(ObjectUserVerifications::class, $collection);

        return $collection;
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

/**
 * A mailer that records what would have been queued instead of queueing it.
 *
 * Subclassed rather than faked behind an interface, as the protected-mode alert test does it:
 * the change-email notice rides the same raw-send intake, and the code letters go through it too.
 */
final class EmailChangeRecordingMailer extends HilosMailer
{
    /** @var list<array{to: string, templateKey: ?string, params: array<string, mixed>}> Captured sends */
    public array $sent = [];

    /**
     * @param EmailMessage|MailSendSignalData $message Message the caller handed over
     */
    public function send(EmailMessage|MailSendSignalData $message): void
    {
        if (!$message instanceof MailSendSignalData) {
            return;
        }

        $this->sent[] = [
            'to' => $message->to,
            'templateKey' => $message->templateKey,
            'params' => $message->params,
        ];
    }

    /**
     * @param string $templateKey Template key to keep
     * @return list<array{0: string, 1: ?string}> Recipient and template key of each matching send
     */
    public function sentTo(string $templateKey): array
    {
        return array_values(array_map(
            static fn (array $sent): array => [$sent['to'], $sent['templateKey']],
            array_filter($this->sent, static fn (array $sent): bool => $sent['templateKey'] === $templateKey),
        ));
    }
}
