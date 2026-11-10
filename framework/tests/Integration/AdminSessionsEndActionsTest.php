<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\UserSessionsEndRequestSignalData;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUserSessionsPage;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\UserBrowserTrustRevokeDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An administrator ending one or every session of another person, at the sessions library (HIL-1662).
 *
 * The page of the person's Sessions tab keeps the ADMIN level and forwards the write; the sessions
 * are the library's, so the library re-checks the administrator and their confirmation, ends the
 * sessions and answers the page under the reply name the ask carries. These cases hand the ask to
 * the library directly, the way {@see SessionsEndActionsTest} hands the profile its actions, and
 * what is pinned is what the writer does - the rows it signs out, the takeovers it gives back, the
 * frames it asks the person's agent with, and the one answer.
 *
 * The cases named "through the page" press the buttons on a page of the tab instead, through the
 * ordinary action dispatcher, and carry the page's ask to the library and its answer back: the
 * administrator's submit is answered only once the library has, a refusal of the library reaches it
 * as the refusal, and a viewer of the admin view mode is refused both endings before anything leaves.
 *
 * The browser trust is the person's: the library only asks the person's agent to take it away, and
 * a case that cares carries that ask to the agent and reads the rows after (HIL-1407).
 */
final class AdminSessionsEndActionsTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int PERSON_ID = 41;
    private const int STRANGER_ID = 42;
    private const int ADMIN_ID = 7;
    private const int TAKEOVER_ADMIN_ID = 8;

    private const string ADMIN_TOKEN = '10000000000000000000000000000007';
    private const string ADMIN_OTHER_TOKEN = '20000000000000000000000000000007';
    private const string PERSON_TOKEN = '10000000000000000000000000000041';
    private const string PERSON_OTHER_TOKEN = '20000000000000000000000000000041';
    private const string TAKEOVER_TOKEN = '10000000000000000000000000000008';
    private const string STRANGER_TOKEN = '10000000000000000000000000000042';
    private const string GUEST_TOKEN = '10000000000000000000000000000000';

    private const string ADMIN_ACCEPT_KEY = 'accept-admin';
    private const string ADMIN_OTHER_ACCEPT_KEY = 'accept-admin-other';
    private const string PERSON_ACCEPT_KEY = 'accept-person';
    private const string PERSON_OTHER_ACCEPT_KEY = 'accept-person-other';
    private const string TAKEOVER_ACCEPT_KEY = 'accept-takeover';
    private const string STRANGER_ACCEPT_KEY = 'accept-stranger';

    private const string REQUEST_ID = 'request-1';
    private const string ACTION = 'test_user_sessions_end';

    private ?BrowserContext $previousBrowser = null;

    /**
     * @throws HilosException When the people or the runtime fixture cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousBrowser = Hilos::$browser;
        Hilos::$browser = new AdminSessionsEndBrowser();
        Database::sqlRun(
            'INSERT INTO `hilos_user` (`id`, `name`, `admin`) VALUES '
            . "(?, 'Person', 0), (?, 'Stranger', 0), (?, 'Administrator', 1), (?, 'Other administrator', 1)",
            [self::PERSON_ID, self::STRANGER_ID, self::ADMIN_ID, self::TAKEOVER_ADMIN_ID],
        );

        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new AdminSessionsEndRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$browser = $this->previousBrowser;

        parent::tearDown();
    }

    /**
     * One ordinary session of the person ends: its row goes anonymous, its tabs are told why, the
     * person's other session stays, the page is answered with the session's number, and the
     * browser loses its trust once the person's agent is asked.
     *
     * @throws HilosException When a fixture, the library or the person's agent fails
     */
    public function testOneOrdinarySessionEndsAndOnlyItsBrowserLosesTrust(): void
    {
        $this->signInAdministrator();
        $ended = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        $kept = $this->session(self::PERSON_OTHER_TOKEN, self::PERSON_OTHER_ACCEPT_KEY, self::PERSON_ID);
        $this->trust($ended, self::PERSON_ID);
        $this->trust($kept, self::PERSON_ID);

        $signals = $this->ask(self::PERSON_ID, (int)$ended->id);

        self::assertSame([null, null], self::sessionPeople((int)$ended->id));
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$kept->id));
        $states = self::sessionStates($signals);
        self::assertNotSame([], $states);
        foreach ($states as $state) {
            self::assertSame($ended->id, $state->sessionId);
            self::assertNull($state->userId);
            self::assertSame('ended_by_administrator', $state->accessLossReason);
        }
        $this->assertAnswered($signals, "Session #{$ended->id} ended");
        $revokes = self::trustRevokes($signals);
        self::assertCount(1, $revokes);
        self::assertSame(self::PERSON_ID, $revokes[0]->userId);
        self::assertSame((int)$ended->id, $revokes[0]->sessionId);
        self::assertFalse($revokes[0]->others);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($ended->id, self::PERSON_ID, 30), 'The library takes no trust itself');

        $this->carryTrustRevoke($signals);

        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($ended->id, self::PERSON_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($kept->id, self::PERSON_ID, 30));
    }

    /** An outgoing takeover ends completely and loses only the person's trust in that browser. */
    public function testOneOutgoingTakeoverEndsCompletelyAndLosesThePersonsTrust(): void
    {
        $this->signInAdministrator();
        $outgoing = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::STRANGER_ID, self::PERSON_ID);
        $kept = $this->session(self::PERSON_OTHER_TOKEN, self::PERSON_OTHER_ACCEPT_KEY, self::PERSON_ID);
        $this->trust($outgoing, self::PERSON_ID);
        $this->trust($outgoing, self::STRANGER_ID);
        $this->trust($kept, self::PERSON_ID);

        $signals = $this->ask(self::PERSON_ID, (int)$outgoing->id);

        self::assertSame([null, null], self::sessionPeople((int)$outgoing->id));
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$kept->id));
        $states = self::sessionStates($signals);
        self::assertNotSame([], $states);
        foreach ($states as $state) {
            self::assertSame($outgoing->id, $state->sessionId);
            self::assertNull($state->userId);
            self::assertSame('ended_by_administrator', $state->accessLossReason);
        }
        $this->assertAnswered($signals, "Session #{$outgoing->id} ended");
        $revokes = self::trustRevokes($signals);
        self::assertCount(1, $revokes);
        self::assertSame(self::PERSON_ID, $revokes[0]->userId);
        self::assertSame((int)$outgoing->id, $revokes[0]->sessionId);
        self::assertFalse($revokes[0]->others);

        $this->carryTrustRevoke($signals);

        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($outgoing->id, self::PERSON_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($outgoing->id, self::STRANGER_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($kept->id, self::PERSON_ID, 30));
    }

    /** Both directions count: outgoing takeovers end, incoming ones return to their administrators. */
    public function testEverySessionIncludesOutgoingTakeoversAndReturnsIncomingOnes(): void
    {
        $this->signInAdministrator();
        $ordinary = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        $outgoing = $this->session(self::PERSON_OTHER_TOKEN, self::PERSON_OTHER_ACCEPT_KEY, self::STRANGER_ID, self::PERSON_ID);
        $incoming = $this->session(self::TAKEOVER_TOKEN, self::TAKEOVER_ACCEPT_KEY, self::PERSON_ID, self::TAKEOVER_ADMIN_ID);
        $stranger = $this->session(self::STRANGER_TOKEN, self::STRANGER_ACCEPT_KEY, self::STRANGER_ID);
        $this->trust($ordinary, self::PERSON_ID);
        $this->trust($outgoing, self::PERSON_ID);
        $this->trust($incoming, self::TAKEOVER_ADMIN_ID);
        $this->trust($stranger, self::STRANGER_ID);

        $signals = $this->ask(self::PERSON_ID, null);

        self::assertSame([null, null], self::sessionPeople((int)$ordinary->id));
        self::assertSame([null, null], self::sessionPeople((int)$outgoing->id));
        self::assertSame([self::TAKEOVER_ADMIN_ID, null], self::sessionPeople((int)$incoming->id));
        self::assertSame([self::STRANGER_ID, null], self::sessionPeople((int)$stranger->id));
        $states = self::sessionStates($signals);
        self::assertNotSame([], $states);
        $seen = [];
        foreach ($states as $state) {
            $seen[] = $state->sessionId;
            if ($state->sessionId === $incoming->id) {
                self::assertSame(self::TAKEOVER_ADMIN_ID, $state->userId);
                self::assertNull($state->accessLossReason);
            } else {
                self::assertContains($state->sessionId, [$ordinary->id, $outgoing->id]);
                self::assertNull($state->userId);
                self::assertSame('ended_by_administrator', $state->accessLossReason);
            }
        }
        self::assertEqualsCanonicalizing([$ordinary->id, $outgoing->id, $incoming->id], array_values(array_unique($seen)));
        $this->assertAnswered($signals, 'Ended 3 sessions');
        $revokes = self::trustRevokes($signals);
        self::assertCount(1, $revokes);
        self::assertSame(self::PERSON_ID, $revokes[0]->userId);
        self::assertSame(0, $revokes[0]->sessionId);
        self::assertTrue($revokes[0]->others);

        $this->carryTrustRevoke($signals);

        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($ordinary->id, self::PERSON_ID, 30));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($outgoing->id, self::PERSON_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($incoming->id, self::TAKEOVER_ADMIN_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($stranger->id, self::STRANGER_ID, 30));
    }

    /** A captured outgoing row that now belongs to another person is refused without effects. */
    public function testAnOutgoingSessionThatMovedToAnotherPersonIsRefused(): void
    {
        $this->signInAdministrator();
        $outgoing = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::STRANGER_ID, self::PERSON_ID);
        $outgoing->actions->setImpersonator(self::TAKEOVER_ADMIN_ID);

        $signals = $this->ask(self::PERSON_ID, (int)$outgoing->id);

        $this->assertRefused($signals, 'This session has already ended');
        self::assertSame([self::STRANGER_ID, self::TAKEOVER_ADMIN_ID], self::sessionPeople((int)$outgoing->id));
        self::assertSame([], self::sessionStates($signals));
        self::assertSame([], self::trustRevokes($signals));
    }

    /** Even impersonating an active administrator refuses both endings before any session or trust changes. */
    public function testAnImpersonatingAdministratorIsRefusedBothEndingsBeforeAnyEffects(): void
    {
        $acting = $this->session(self::ADMIN_TOKEN, self::ADMIN_ACCEPT_KEY, self::TAKEOVER_ADMIN_ID, self::ADMIN_ID);
        $other = $this->session(self::ADMIN_OTHER_TOKEN, self::ADMIN_OTHER_ACCEPT_KEY, self::ADMIN_ID);

        foreach ([(int)$other->id, null] as $sessionId) {
            $signals = $this->ask(self::ADMIN_ID, $sessionId);

            $this->assertRefused($signals, 'Only an active administrator can do this');
            self::assertSame([self::TAKEOVER_ADMIN_ID, self::ADMIN_ID], self::sessionPeople((int)$acting->id));
            self::assertSame([self::ADMIN_ID, null], self::sessionPeople((int)$other->id));
            self::assertSame([], self::sessionStates($signals));
            self::assertSame([], self::trustRevokes($signals));
        }
    }

    /**
     * Every session of the person ends in one pass: the ordinary ones go anonymous, the takeover is
     * given back to the administrator behind it, a stranger's session is left alone, and the page is
     * told how many actually ended. Every browser of the person loses its trust; the administrator's
     * trust in the browser of the takeover stays.
     *
     * @throws HilosException When a fixture, the library or the person's agent fails
     */
    public function testEverySessionEndsAndTheTakeoverGoesBackToItsAdministrator(): void
    {
        $this->signInAdministrator();
        $first = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        $second = $this->session(self::PERSON_OTHER_TOKEN, self::PERSON_OTHER_ACCEPT_KEY, self::PERSON_ID);
        $takeover = $this->session(self::TAKEOVER_TOKEN, self::TAKEOVER_ACCEPT_KEY, self::PERSON_ID, self::TAKEOVER_ADMIN_ID);
        $stranger = $this->session(self::STRANGER_TOKEN, self::STRANGER_ACCEPT_KEY, self::STRANGER_ID);
        $this->trust($first, self::PERSON_ID);
        $this->trust($second, self::PERSON_ID);
        $this->trust($takeover, self::TAKEOVER_ADMIN_ID);
        $this->trust($stranger, self::STRANGER_ID);

        $signals = $this->ask(self::PERSON_ID, null);

        self::assertSame([null, null], self::sessionPeople((int)$first->id));
        self::assertSame([null, null], self::sessionPeople((int)$second->id));
        self::assertSame([self::TAKEOVER_ADMIN_ID, null], self::sessionPeople((int)$takeover->id));
        self::assertSame([self::STRANGER_ID, null], self::sessionPeople((int)$stranger->id));
        $this->assertAnswered($signals, 'Ended 3 sessions');
        $revokes = self::trustRevokes($signals);
        self::assertCount(1, $revokes);
        self::assertSame(self::PERSON_ID, $revokes[0]->userId);
        self::assertSame(0, $revokes[0]->sessionId);
        self::assertTrue($revokes[0]->others);

        $this->carryTrustRevoke($signals);

        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($first->id, self::PERSON_ID, 30));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($second->id, self::PERSON_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($takeover->id, self::TAKEOVER_ADMIN_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($stranger->id, self::STRANGER_ID, 30));
    }

    /**
     * A session where an administrator acts as the person is given back to that administrator, the
     * person's own session survives, and nobody's trust is asked away.
     *
     * @throws HilosException When a fixture or the library fails
     */
    public function testOneTakeoverIsGivenBackAndThePersonsOwnSessionSurvives(): void
    {
        $this->signInAdministrator();
        $own = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        $takeover = $this->session(self::TAKEOVER_TOKEN, self::TAKEOVER_ACCEPT_KEY, self::PERSON_ID, self::TAKEOVER_ADMIN_ID);

        $signals = $this->ask(self::PERSON_ID, (int)$takeover->id);

        self::assertSame([self::TAKEOVER_ADMIN_ID, null], self::sessionPeople((int)$takeover->id));
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$own->id));
        $restored = array_values(array_filter(
            self::sessionStates($signals),
            static fn (SessionStateSignalData $state): bool => $state->sessionId === $takeover->id,
        ));
        self::assertNotSame([], $restored);
        self::assertSame(self::TAKEOVER_ADMIN_ID, $restored[0]->userId);
        self::assertSame([], self::trustRevokes($signals));
        $this->assertAnswered($signals, "Session #{$takeover->id} ended");
    }

    /**
     * The administrator's own sessions are no exception: ending them all ends the tab that pressed
     * too, last, and that tab is still answered.
     *
     * @throws HilosException When a fixture or the library fails
     */
    public function testTheAdministratorsOwnCurrentSessionEndsLastAmongAll(): void
    {
        $acting = $this->signInAdministrator();
        $other = $this->session(self::ADMIN_OTHER_TOKEN, self::ADMIN_OTHER_ACCEPT_KEY, self::ADMIN_ID);
        $outgoing = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID, self::ADMIN_ID);

        $signals = $this->ask(self::ADMIN_ID, null);

        self::assertSame([null, null], self::sessionPeople((int)$acting->id));
        self::assertSame([null, null], self::sessionPeople((int)$other->id));
        self::assertSame([null, null], self::sessionPeople((int)$outgoing->id));
        $endedInOrder = array_values(array_unique(array_map(
            static fn (SessionStateSignalData $state): ?int => $state->sessionId,
            self::sessionStates($signals),
        )));
        self::assertSame($acting->id, array_pop($endedInOrder));
        self::assertEqualsCanonicalizing([$other->id, $outgoing->id], $endedInOrder);
        $this->assertAnswered($signals, 'Ended 3 sessions');
    }

    /**
     * A person with nothing left is answered with what actually ended, again and again: the first
     * pass ends the one session there is, a second ends none and still succeeds.
     *
     * @throws HilosException When a fixture or the library fails
     */
    public function testAPersonWithNoSessionsLeftIsAnsweredWithZero(): void
    {
        $this->signInAdministrator();
        $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);

        $this->assertAnswered($this->ask(self::PERSON_ID, null), 'Ended 1 session');
        $signals = $this->ask(self::PERSON_ID, null);

        $this->assertAnswered($signals, 'Ended 0 sessions');
        self::assertSame([], self::sessionStates($signals));
    }

    /** @return array<string, array{bool}> A session of somebody else, and one nobody is signed in to */
    public static function sessionsNoLongerThePersons(): array
    {
        return ['another person' => [true], 'a guest' => [false]];
    }

    /**
     * A session id the card listed reaches nobody but the person: signed into by somebody else, or
     * signed out since, it is refused as ended, and the row is left as it is.
     *
     * @param bool $stranger Whether the row is signed into by another person rather than by nobody
     * @throws HilosException When a fixture or the library fails
     */
    #[DataProvider('sessionsNoLongerThePersons')]
    public function testASessionNoLongerThePersonsIsRefusedAsEnded(bool $stranger): void
    {
        $this->signInAdministrator();
        $target = $stranger
            ? $this->session(self::STRANGER_TOKEN, self::STRANGER_ACCEPT_KEY, self::STRANGER_ID)
            : Hilos::$db->sessions->actions->createAnonymous(self::GUEST_TOKEN);
        $before = self::sessionPeople((int)$target->id);

        $signals = $this->ask(self::PERSON_ID, (int)$target->id);

        $this->assertRefused($signals, 'This session has already ended');
        self::assertSame($before, self::sessionPeople((int)$target->id));
        self::assertSame([], self::trustRevokes($signals));
    }

    /**
     * @throws HilosException When a fixture or the library fails
     */
    public function testASessionThatIsGoneIsRefusedAsEnded(): void
    {
        $this->signInAdministrator();
        $session = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);

        $signals = $this->ask(self::PERSON_ID, (int)$session->id + 100);

        $this->assertRefused($signals, 'This session has already ended');
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$session->id));
    }

    /** @return array<string, array{bool, string}> What the administrator lost on the way, and the refusal */
    public static function lostOnTheWay(): array
    {
        return [
            'the rights' => [true, 'Only an active administrator can do this'],
            'the confirmation' => [false, StepUpMessages::EXPIRED],
        ];
    }

    /**
     * The library judges the administrator again when the ask arrives: rights taken away, or a
     * confirmation that is not there, refuse it, and nothing is ended - one session or all.
     *
     * @param bool $rights Whether the rights are lost rather than the confirmation
     * @param string $refusal Refusal the page is answered with
     * @throws HilosException When a fixture or the library fails
     */
    #[DataProvider('lostOnTheWay')]
    public function testAnAdministratorWhoLostTheRightOnTheWayEndsNothing(bool $rights, string $refusal): void
    {
        if ($rights) {
            Database::sqlRun('UPDATE `hilos_user` SET `admin` = 0 WHERE `id` = ?', [self::ADMIN_ID]);
        }
        $this->signInAdministrator(confirmed: $rights);
        if (!$rights) {
            // A way to confirm with, so the gate asks for it rather than looking any further.
            Hilos::$db->identities->createPasswordIdentity(self::ADMIN_ID, 'admin@example.test', 'admin-password-1662');
        }
        $session = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);

        $this->assertRefused($this->ask(self::PERSON_ID, (int)$session->id), $refusal);
        $signals = $this->ask(self::PERSON_ID, null);

        $this->assertRefused($signals, $refusal);
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$session->id));
        self::assertSame([], self::sessionStates($signals));
        self::assertSame([], self::trustRevokes($signals));
    }

    /**
     * A person folded into another account is refused before a session is touched.
     *
     * @throws HilosException When a fixture or the library fails
     */
    public function testAMergedPersonIsRefusedAndKeepsTheSessions(): void
    {
        $this->signInAdministrator();
        $session = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [self::PERSON_ID, self::STRANGER_ID, TimeHelper::nowMs()],
        );

        $signals = $this->ask(self::PERSON_ID, null);

        $this->assertRefused($signals, AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$session->id));
        self::assertSame([], self::trustRevokes($signals));
    }

    /**
     * Through the page, one session ends only once the library has ended it: pressing queues the ask
     * and nothing else, the row is still signed in, and the submit is answered with the library's
     * sentence after its answer is carried back. A field naming somebody else as the administrator
     * does not travel: the library judges the connection that pressed.
     *
     * @throws HilosException When a fixture, the page or the library fails
     */
    public function testThroughThePageOneSessionIsAnsweredOnlyAfterTheLibraryEndedIt(): void
    {
        $this->signInAdministrator();
        $ended = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        $kept = $this->session(self::PERSON_OTHER_TOKEN, self::PERSON_OTHER_ACCEPT_KEY, self::PERSON_ID);
        $router = self::pageRouter();

        $pressed = $this->press($router, self::ADMIN_ACCEPT_KEY, HilosSignalConstants::HILOS_USER_SESSION_END, [
            'userId' => self::PERSON_ID,
            'sessionId' => (int)$ended->id,
            'by' => self::STRANGER_ID,
            'adminUserId' => self::STRANGER_ID,
        ]);

        $ask = self::onlyAsk($pressed);
        self::assertSame([
            UserSessionsEndRequestSignalData::userId => self::PERSON_ID,
            UserSessionsEndRequestSignalData::sessionId => (int)$ended->id,
            UserSessionsEndRequestSignalData::replySignal => HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
            UserSessionsEndRequestSignalData::acceptKey => self::ADMIN_ACCEPT_KEY,
            UserSessionsEndRequestSignalData::requestId => self::REQUEST_ID,
            UserSessionsEndRequestSignalData::action => HilosSignalConstants::HILOS_USER_SESSION_END,
            UserSessionsEndRequestSignalData::successMessage => null,
        ], $ask->toArray());
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$ended->id), 'Nothing is ended by pressing');

        $acks = $this->carryThroughLibrary($router, $pressed);

        self::assertSame([null, null], self::sessionPeople((int)$ended->id));
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$kept->id));
        $success = self::onlySuccess($acks, HilosSignalConstants::HILOS_USER_SESSION_END);
        self::assertSame("Session #{$ended->id} ended", $success->message);
    }

    /**
     * Through the page, every session ends - the takeover given back - and the submit is told how
     * many the library actually ended.
     *
     * @throws HilosException When a fixture, the page or the library fails
     */
    public function testThroughThePageEverySessionEndsAndTheCountComesFromTheLibrary(): void
    {
        $this->signInAdministrator();
        $own = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        $takeover = $this->session(self::TAKEOVER_TOKEN, self::TAKEOVER_ACCEPT_KEY, self::PERSON_ID, self::TAKEOVER_ADMIN_ID);
        $router = self::pageRouter();

        $pressed = $this->press($router, self::ADMIN_ACCEPT_KEY, HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL, [
            'userId' => self::PERSON_ID,
        ]);

        self::assertNull(self::onlyAsk($pressed)->sessionId);
        self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$own->id));
        $acks = $this->carryThroughLibrary($router, $pressed);

        self::assertSame([null, null], self::sessionPeople((int)$own->id));
        self::assertSame([self::TAKEOVER_ADMIN_ID, null], self::sessionPeople((int)$takeover->id));
        self::assertSame('Ended 2 sessions', self::onlySuccess($acks, HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL)->message);
    }

    /**
     * Through the page, a person folded into another account keeps every session, and the library's
     * refusal reaches the submit whole - no success is spoken.
     *
     * @throws HilosException When a fixture, the page or the library fails
     */
    public function testThroughThePageTheLibrarysRefusalOfAMergedPersonReachesTheSubmit(): void
    {
        $this->signInAdministrator();
        $session = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [self::PERSON_ID, self::STRANGER_ID, TimeHelper::nowMs()],
        );
        $router = self::pageRouter();

        foreach ([
            HilosSignalConstants::HILOS_USER_SESSION_END => ['userId' => self::PERSON_ID, 'sessionId' => (int)$session->id],
            HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL => ['userId' => self::PERSON_ID],
        ] as $action => $payload) {
            $acks = $this->carryThroughLibrary($router, $this->press($router, self::ADMIN_ACCEPT_KEY, $action, $payload));

            self::assertSame([], self::framesNamed($acks, SignalConstants::ACTION_SUCCESS), $action);
            $refusal = self::onlyError($acks);
            self::assertSame($action, $refusal->action);
            self::assertSame(self::REQUEST_ID, $refusal->requestId);
            self::assertSame(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE, $refusal->reason);
            self::assertNull($refusal->errorDetail);
            self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$session->id), $action);
        }
    }

    /**
     * A viewer of the admin view mode is refused both endings by the page itself: nothing is handed
     * to the library, and every session stays signed in.
     *
     * @throws HilosException When a fixture or the page fails
     */
    public function testThroughThePageAViewerIsRefusedBothEndingsAndNothingLeaves(): void
    {
        $viewer = $this->session(self::STRANGER_TOKEN, self::STRANGER_ACCEPT_KEY, self::STRANGER_ID);
        $session = $this->session(self::PERSON_TOKEN, self::PERSON_ACCEPT_KEY, self::PERSON_ID);
        self::switchViewMode(true);
        $router = self::pageRouter();

        foreach ([
            HilosSignalConstants::HILOS_USER_SESSION_END => ['userId' => self::PERSON_ID, 'sessionId' => (int)$session->id],
            HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL => ['userId' => self::PERSON_ID],
        ] as $action => $payload) {
            $pressed = $this->press($router, self::STRANGER_ACCEPT_KEY, $action, $payload);

            self::assertSame([], self::framesNamed($pressed, HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST), $action);
            $refusal = self::onlyError($pressed);
            self::assertSame($action, $refusal->action);
            self::assertSame(ActionViewModeException::ERROR_CODE, $refusal->errorCode);
            self::assertSame([self::PERSON_ID, null], self::sessionPeople((int)$session->id), $action);
            self::assertSame([self::STRANGER_ID, null], self::sessionPeople((int)$viewer->id), $action);
        }
    }

    /**
     * Signs the asking administrator in on the tab that presses, confirmed for the operation unless told otherwise.
     *
     * @param bool $confirmed Whether this browser confirmed ending people's sessions
     * @return Session The administrator's acting session
     * @throws HilosException When the session or the confirmation cannot be written
     */
    private function signInAdministrator(bool $confirmed = true): Session
    {
        $session = $this->session(self::ADMIN_TOKEN, self::ADMIN_ACCEPT_KEY, self::ADMIN_ID);
        if ($confirmed) {
            Hilos::$db->stepUps->actions->confirm(
                ProtectedModeRuntime::hashSessionToken(self::ADMIN_TOKEN),
                self::ADMIN_ID,
                StepUpOperationKey::END_USER_SESSIONS,
                date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_HOUR),
            );
        }

        return $session;
    }

    /**
     * @param string $token Session token
     * @param string $acceptKey Live connection key
     * @param int $userId Person the session acts as
     * @param ?int $impersonatorUserId Administrator behind a takeover, or null for an ordinary session
     * @return Session Persisted signed-in session with one live tab
     * @throws HilosException When the session cannot be written
     */
    private function session(string $token, string $acceptKey, int $userId, ?int $impersonatorUserId = null): Session
    {
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $session->actions->bindUser($userId);
        if ($impersonatorUserId !== null) {
            $session->actions->setImpersonator($impersonatorUserId);
        }
        Hilos::$rt?->addConnection(AdminSessionsEndConnection::create($acceptKey, $userId, $token, $session->id));

        return $session;
    }

    /**
     * @param Session $session Browser trusted to skip the second factor
     * @param int $userId Person whose trust it is
     * @throws HilosException When the trust cannot be written
     */
    private function trust(Session $session, int $userId): void
    {
        Hilos::$db->secondFactorTrusts->actions->trust(
            $session->id,
            $userId,
            date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY),
        );
    }

    /**
     * Hands the library the ask the person's Sessions tab sends, and takes every signal it queued.
     *
     * @param int $userId Person whose sessions end
     * @param ?int $sessionId Session to end, or null for every session
     * @return list<SignalDTO> Signals the library queued, in order
     * @throws HilosException When the library fails on the frame
     */
    private function ask(int $userId, ?int $sessionId): array
    {
        new AdminSessionsEndAgent()->onSignalAgent(
            new AgentSignalData(new UserSessionsEndRequestSignalData(
                userId: $userId,
                sessionId: $sessionId,
                replySignal: HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
                acceptKey: self::ADMIN_ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: self::ACTION,
                successMessage: null,
            )),
            'page',
            HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST,
        );

        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param list<SignalDTO> $signals Signals the library queued
     * @param string $message Success sentence the page is to speak
     */
    private function assertAnswered(array $signals, string $message): void
    {
        $answer = self::answer($signals);
        self::assertNull($answer->error, (string)$answer->errorDetail);
        self::assertSame($message, $answer->successMessage);
    }

    /**
     * @param list<SignalDTO> $signals Signals the library queued
     * @param string $reason Refusal the page is to show
     */
    private function assertRefused(array $signals, string $reason): void
    {
        $answer = self::answer($signals);
        self::assertSame($reason, $answer->error);
        self::assertNull($answer->errorDetail, 'A refusal written for the person holds nothing back');
    }

    /**
     * Takes the one answer the library addressed to the page that asked.
     *
     * @param list<SignalDTO> $signals Signals the library queued
     * @return HandoverAnswerSignalData The answer under the reply name the ask carried
     */
    private static function answer(array $signals): HandoverAnswerSignalData
    {
        $answers = [];
        foreach ($signals as $signal) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof HandoverAnswerSignalData
            ) {
                $answers[] = $signal->data->data;
            }
        }
        self::assertCount(1, $answers, 'The page is answered exactly once');
        self::assertSame(self::ADMIN_ACCEPT_KEY, $answers[0]->acceptKey);
        self::assertSame(self::REQUEST_ID, $answers[0]->requestId);
        self::assertSame(self::ACTION, $answers[0]->action);

        return $answers[0];
    }

    /**
     * @param list<SignalDTO> $signals Signals the library queued
     * @return list<SessionStateSignalData> Session-state frames, in order
     */
    private static function sessionStates(array $signals): array
    {
        $states = [];
        foreach ($signals as $signal) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof SessionStateSignalData
            ) {
                $states[] = $signal->data->data;
            }
        }

        return $states;
    }

    /**
     * @param list<SignalDTO> $signals Signals the library queued
     * @return list<UserBrowserTrustRevokeSignalData> Asks to the person's agent to take browser trust away
     */
    private static function trustRevokes(array $signals): array
    {
        $revokes = [];
        foreach ($signals as $signal) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof UserBrowserTrustRevokeSignalData
            ) {
                $revokes[] = $signal->data->data;
            }
        }

        return $revokes;
    }

    /**
     * Hands the library's ask to take browser trust away to the person's agent, and checks its answer (HIL-1407).
     *
     * @param list<SignalDTO> $signals Signals the library queued
     * @throws HilosException When the agent fails on the frame
     */
    private function carryTrustRevoke(array $signals): void
    {
        foreach ($signals as $signal) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE) {
                $this->deliverToPerson($signal);
            }
        }

        $answered = false;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof UserBrowserTrustRevokeDoneSignalData
            ) {
                self::assertNull($signal->data->data->error);
                $answered = true;
            }
        }
        self::assertTrue($answered, "The person's agent answers the library");
    }

    /** @return PageSignalRouter The ordinary action dispatcher over one page of the Sessions tab */
    private static function pageRouter(): PageSignalRouter
    {
        return new PageSignalRouter(
            new AdminSessionsEndPageFactory(new AdminSessionsEndPageAgent()),
            new ActionRouteConfig([
                HilosSignalConstants::HILOS_USER_SESSION_END => AdminSessionsEndPage::PAGE,
                HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL => AdminSessionsEndPage::PAGE,
            ]),
            new SignalRouteConfig([
                SignalTypeConstants::AGENT_SIGNAL => [HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE => AdminSessionsEndPage::PAGE],
            ]),
        );
    }

    /**
     * Presses one ending on the page as a tracked submit, and takes every signal the press queued.
     *
     * @param PageSignalRouter $router Dispatcher of the Sessions tab
     * @param string $acceptKey Connection that presses
     * @param string $action Browser action
     * @param array<string, mixed> $payload Browser payload, as the client sends it
     * @return list<SignalDTO> Signals the press queued, in order
     */
    private function press(PageSignalRouter $router, string $acceptKey, string $action, array $payload): array
    {
        $router->dispatchAction(new WebSocketActionSignalDTO($acceptKey, $action, $payload, self::REQUEST_ID), 'websocket');

        return self::drain();
    }

    /**
     * Carries the page's ask to the library across the process boundary, and the library's answer back to the page.
     *
     * @param PageSignalRouter $router Dispatcher of the Sessions tab
     * @param list<SignalDTO> $pressed Signals the press queued, holding the one ask
     * @return list<SignalDTO> Signals the page queued on the library's answer
     * @throws HilosException When the library or the page fails on the frame
     */
    private function carryThroughLibrary(PageSignalRouter $router, array $pressed): array
    {
        $asks = self::framesNamed($pressed, HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST);
        self::assertCount(1, $asks);
        self::assertSame([], self::framesNamed($pressed, SignalConstants::ACTION_SUCCESS), 'No ack before the library answers');
        self::assertSame([], self::framesNamed($pressed, SignalConstants::ACTION_ERROR), 'No refusal before the library answers');
        self::assertInstanceOf(AgentSignalData::class, $asks[0]->data);
        new AdminSessionsEndAgent()->onSignalAgent(
            AgentSignalData::fromJson($asks[0]->data->toJson()),
            'page',
            HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST,
        );

        $answers = self::framesNamed(self::drain(), HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE);
        self::assertCount(1, $answers);
        self::assertInstanceOf(AgentSignalData::class, $answers[0]->data);
        $router->dispatchAgentSignal(
            AgentSignalData::fromJson($answers[0]->data->toJson()),
            'library',
            HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
        );

        return self::drain();
    }

    /**
     * Switches the node's admin view mode the way the master does, its sync frames kept off the queue under test.
     *
     * @param bool $enabled Whether the mode is on
     */
    private static function switchViewMode(bool $enabled): void
    {
        $router = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt?->hilosAdminViewModeRuntime?->actions->set($enabled);
        Hilos::$sr = $router;
    }

    /** @return list<SignalDTO> Every signal queued so far, in order */
    private static function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param list<SignalDTO> $signals Queued signals
     * @param string $name Signal name
     * @return list<SignalDTO> The signals under that name
     */
    private static function framesNamed(array $signals, string $name): array
    {
        return array_values(array_filter(
            $signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === $name,
        ));
    }

    /**
     * @param list<SignalDTO> $pressed Signals the press queued
     * @return UserSessionsEndRequestSignalData The one ask handed to the library
     */
    private static function onlyAsk(array $pressed): UserSessionsEndRequestSignalData
    {
        $asks = self::framesNamed($pressed, HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST);
        self::assertCount(1, $asks);
        self::assertInstanceOf(AgentSignalData::class, $asks[0]->data);
        $ask = AgentSignalData::fromJson($asks[0]->data->toJson())->data;
        self::assertInstanceOf(UserSessionsEndRequestSignalData::class, $ask);

        return $ask;
    }

    /**
     * @param list<SignalDTO> $acks Signals the page queued
     * @param string $action Browser action the success answers
     * @return PageActionSuccessSignalData The one success, addressed to the administrator's tracked submit
     */
    private static function onlySuccess(array $acks, string $action): PageActionSuccessSignalData
    {
        self::assertSame([], self::framesNamed($acks, SignalConstants::ACTION_ERROR));
        $successes = self::framesNamed($acks, SignalConstants::ACTION_SUCCESS);
        self::assertCount(1, $successes);
        self::assertInstanceOf(WebSocketSignalData::class, $successes[0]->data);
        self::assertSame(self::ADMIN_ACCEPT_KEY, $successes[0]->data->targetAcceptKey);
        $success = $successes[0]->data->data;
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame(self::REQUEST_ID, $success->requestId);
        self::assertSame($action, $success->action);

        return $success;
    }

    /**
     * @param list<SignalDTO> $signals Signals the page queued
     * @return PageActionErrorSignalData The one refusal of the submit
     */
    private static function onlyError(array $signals): PageActionErrorSignalData
    {
        $errors = self::framesNamed($signals, SignalConstants::ACTION_ERROR);
        self::assertCount(1, $errors);
        self::assertInstanceOf(WebSocketSignalData::class, $errors[0]->data);
        $error = $errors[0]->data->data;
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);

        return $error;
    }

    /**
     * Reads a session's person and the administrator behind it straight from the database.
     *
     * @param int $sessionId Session row id
     * @return array{?int, ?int} Bound user id and impersonator user id
     * @throws HilosException When the query fails
     */
    private static function sessionPeople(int $sessionId): array
    {
        Database::sql('SELECT `user_id`, `impersonator_user_id` FROM `hilos_session` WHERE `id` = ?', [$sessionId]);
        $row = Database::row();
        self::assertNotNull($row, "Session #{$sessionId} keeps its row");

        return [
            $row['user_id'] === null ? null : (int)$row['user_id'],
            $row['impersonator_user_id'] === null ? null : (int)$row['impersonator_user_id'],
        ];
    }
}

final class AdminSessionsEndAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

final class AdminSessionsEndRtContext extends RtContext
{
    /** @var AdminSessionsEndConnections Fixture connection rows */
    private AdminSessionsEndConnections $connections;

    public function configure(): void
    {
        $this->connections = AdminSessionsEndConnections::init();
        $this->_stateCollections[AdminSessionsEndConnections::RT_COLLECTION] = $this->connections;
    }

    /**
     * @param AdminSessionsEndConnection $connection Connection row to mount
     */
    public function addConnection(AdminSessionsEndConnection $connection): void
    {
        $this->connections->add($connection);
    }
}

final class AdminSessionsEndConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'adminSessionsEndConnections';
    public const string STATE_CLASS = AdminSessionsEndConnection::class;
}

final class AdminSessionsEndConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized runtime row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project-owned fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Incoming field changes */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

final class AdminSessionsEndPage extends AbstractHilosUserSessionsPage
{
}

final class AdminSessionsEndPageAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'admin-sessions-end-page-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'admin-sessions-end-page');
    }
}

final class AdminSessionsEndPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage The Sessions tab
     * @throws PageNotFoundException When another page is asked for
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            AdminSessionsEndPage::PAGE => new AdminSessionsEndPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether it is the Sessions tab
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === AdminSessionsEndPage::PAGE;
    }

    /**
     * Builds the payload the page declares for the action, as the project's factory does from its topology.
     *
     * @param string $action Action name
     * @param array<string, mixed> $data Browser payload
     * @return ActionPayloadDTO Typed payload of a declared action, or the unknown one
     */
    public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
    {
        $class = AdminSessionsEndPage::ACTIONS[$action] ?? null;

        return $class === null ? parent::createActionPayloadDTO($action, $data) : $class::fromArray($data);
    }
}

/** Reads the person behind a connection off its runtime row, and their rights off the people table, as a project does. */
final class AdminSessionsEndBrowser extends BrowserContext
{
    /**
     * @param string $acceptKey Connection asked about
     * @return ConnectionIdentity The person signed in on that connection, or nobody
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->userId);
    }
}
