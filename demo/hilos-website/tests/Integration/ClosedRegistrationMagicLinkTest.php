<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Tests\Integration;

use Demo\HilosWebsite\Agents\HilosWebsiteAgent;
use Demo\HilosWebsite\Hilos;
use Demo\HilosWebsite\Runtime\View\Context\HilosWebsiteRtContext;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Legal\LegalConsentProjector;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves that the site, which registers nobody, opens no account through the magic link.
 *
 * The link signs a member in and registers a free address with one letter, so its action is
 * not on the lock's list of registration actions, and the site never wired the method. A free
 * address asked for by hand - with the current documents accepted, so consent is not what
 * stops it - is refused where it would be held: the ordinary action error, no hold, no link,
 * no letter and no account. A member's address is still sent its link, which is also what
 * shows that a letter here would have been seen.
 *
 * Requires the test DB reset (composer run test:db-reset).
 */
final class ClosedRegistrationMagicLinkTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string ACCEPT_KEY = 'website-closed-link-ak';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(HilosWebsiteRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    /**
     * A link asked for a free address is refused before anything is held, issued, mailed or made.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testALinkForAFreeAddressIsRefusedWithNothingHeldMailedOrMade(): void
    {
        $email = $this->uniqueEmail();
        $token = $this->openSession();
        $usersBefore = count(Hilos::$db->users->listAll());

        try {
            $this->requestLink($email);
            self::fail('A link for a free address must be refused in a project that registers nobody');
        } catch (ValidationException $refusal) {
            self::assertSame(AuthMessages::REGISTRATION_CLOSED, $refusal->getMessage());
        }

        self::assertNull(new RegistrationReservationService()->findActiveForSession($token), 'No address is held');
        self::assertNull(new VerificationService()->activeExpiresAt(VerificationType::MAGIC_LINK, $email), 'No link is issued');
        self::assertSame([], $this->drainLettersTo($email), 'No letter is queued');
        self::assertNull(Hilos::$db->identities->findAccountIdByEmail($email), 'No account owns the address');
        self::assertCount($usersBefore, Hilos::$db->users->listAll(), 'No user is minted');
    }

    /**
     * A member's address is still sent its link: closing registration leaves signing in open.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testALinkForAMembersAddressIsStillSent(): void
    {
        $email = $this->uniqueEmail();
        $userId = (int)Hilos::$db->users->actions->createWithName('member')->id;
        Hilos::$db->identities->createMagicLinkIdentity($userId, $email);
        $token = $this->openSession();

        $reply = $this->requestLink($email);

        self::assertInstanceOf(AuthFlowOutcome::class, $reply);
        self::assertTrue($reply->ok);
        self::assertNull(new RegistrationReservationService()->findActiveForSession($token), 'A member\'s address is not held');
        self::assertNotNull(new VerificationService()->activeExpiresAt(VerificationType::MAGIC_LINK, $email), 'The link is issued');
        self::assertCount(1, $this->drainLettersTo($email), 'One letter is queued');
    }

    /**
     * Opens one visitor's socket and session the way the daemon does, leaving the queue empty.
     *
     * @return string Session token of the visitor
     * @throws HilosException On database or runtime failure
     */
    private function openSession(): string
    {
        $token = RandomHelper::hex(16);
        $this->deliverHandshake(new HilosWebsiteAgent(), new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: self::ACCEPT_KEY,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        ));
        $this->drainSignals();

        return $token;
    }

    /**
     * Sends the link request a sign-in surface would, with the current documents accepted.
     *
     * @param string $email Address the link is asked for
     * @return ?ActionReplyDTO What the users library answered
     * @throws HilosException When the library refuses the action
     */
    private function requestLink(string $email): ?ActionReplyDTO
    {
        ExecutionContext::setCurrentAcceptKey(self::ACCEPT_KEY);

        return $this->usersLibrary()->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK,
            new RequestMagicLinkActionDTO($email, LegalConsentProjector::acceptance()),
        );
    }

    /**
     * Drains the queue and returns the letters it held for one address.
     *
     * @param string $email Recipient address
     * @return list<MailSendSignalData> Letters queued to the address, in order
     */
    private function drainLettersTo(string $email): array
    {
        $letters = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_MAIL_SEND
                && $data instanceof AgentSignalData
                && $data->data instanceof MailSendSignalData
                && $data->data->to === $email) {
                $letters[] = $data->data;
            }
        }

        return $letters;
    }

    /**
     * @return string Unique lowercase address for one case
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }
}
