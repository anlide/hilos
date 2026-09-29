<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\AccountAdminSetSignalData;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Utils\Helpers\RandomHelper;

/** Account-card writes run under the real sessions library's claim and answer the waiting page. */
final class AccountLifecycleCardTest extends IntegrationTestCase
{
    private ChatAgent $holder;
    private int $adminId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        $this->holder = new ChatAgent();
        $this->adminId = (int) Hilos::$db->users->actions->createWithName('Administrator')->id;
        Hilos::$db->users[$this->adminId]->actions->setAdmin(true);
        $this->userId = (int) Hilos::$db->users->actions->createWithName('Candidate')->id;
        $this->signIn('admin-ak', $this->adminId);
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    public function testBlockEndsSessionsAndLiftingItReleasesTheirNotices(): void
    {
        $first = $this->signIn('user-a', $this->userId);
        $second = $this->signIn('user-b', $this->userId);
        $reply = $this->block($this->userId, true);
        self::assertNull($reply->error);
        self::assertSame('Account blocked. Sessions ended: 2', $reply->successMessage);
        self::assertTrue(Hilos::$db->users[$this->userId]->block);
        foreach ([$first, $second] as $token) {
            self::assertNull(Hilos::$db->sessions->findByToken($token), 'The old cookie no longer names a session');
        }
        foreach (['user-a', 'user-b'] as $acceptKey) {
            self::assertNotNull($this->sessionOf($acceptKey));
            self::assertNull($this->sessionOf($acceptKey)->userId);
            self::assertSame($this->userId, $this->sessionOf($acceptKey)->blockedUserId);
        }

        self::assertSame('Account blocked. Sessions ended: 0', $this->block($this->userId, true)->successMessage);
        $reply = $this->block($this->userId, false);
        self::assertNull($reply->error);
        self::assertSame('Block lifted. The person can sign in again', $reply->successMessage);
        self::assertFalse(Hilos::$db->users[$this->userId]->block);
        foreach (['user-a', 'user-b'] as $acceptKey) {
            self::assertNotNull($this->sessionOf($acceptKey));
            self::assertNull($this->sessionOf($acceptKey)->blockedUserId);
            self::assertNull($this->sessionOf($acceptKey)->userId, 'Unblocking does not restore a login');
        }
    }

    public function testTheAdministratorCannotBlockOrRevokeTheirOwnAccount(): void
    {
        self::assertSame('You cannot block yourself', $this->block($this->adminId, true)->error);
        self::assertSame('You cannot remove your own admin rights', $this->rights($this->adminId, false)->error);
        self::assertFalse(Hilos::$db->users[$this->adminId]->block);
        self::assertTrue(Hilos::$db->users[$this->adminId]->admin);
    }

    public function testAnOrdinaryUserCannotWriteRightsOrBlocks(): void
    {
        $this->signIn('ordinary-ak', $this->userId);
        self::assertSame('Only an active administrator can do this', $this->block($this->adminId, true, 'ordinary-ak')->error);
        self::assertSame('Only an active administrator can do this', $this->rights($this->userId, true, 'ordinary-ak')->error);
        self::assertFalse(Hilos::$db->users[$this->adminId]->block);
        self::assertFalse(Hilos::$db->users[$this->userId]->admin);
    }

    public function testAnAdministratorBlockedByAnotherCannotMakeTheirNextWrite(): void
    {
        Hilos::$db->users[$this->userId]->actions->setAdmin(true);
        $this->signIn('second-admin-ak', $this->userId);
        self::assertNull($this->block($this->userId, true)->error);
        self::assertNotNull($this->block($this->adminId, true, 'second-admin-ak')->error);
        self::assertFalse(Hilos::$db->users[$this->adminId]->block);
    }

    public function testImpersonationCannotCarryAdministratorAuthority(): void
    {
        $token = Hilos::$rt->connections['admin-ak']->sessionToken;
        self::assertNotNull($token);
        $this->rebindSession($this->holder, new SessionRebindSignalData(
            sessionToken: $token,
            userId: $this->userId,
            impersonatorUserId: $this->adminId,
        ));
        self::assertSame('Only an active administrator can do this', $this->rights($this->userId, true)->error);
        self::assertSame('Only an active administrator can do this', $this->block($this->userId, true)->error);
        self::assertFalse(Hilos::$db->users[$this->userId]->admin);
        self::assertFalse(Hilos::$db->users[$this->userId]->block);
    }

    public function testAMergedAccountCannotBeUnblockedOrGrantedRights(): void
    {
        $this->fold($this->userId, $this->adminId);
        self::assertSame('This account was merged into another one', $this->block($this->userId, false)->error);
        self::assertSame('This account was merged into another one', $this->rights($this->userId, true)->error);
        self::assertTrue(Hilos::$db->users[$this->userId]->block);
        self::assertFalse(Hilos::$db->users[$this->userId]->admin);
    }

    public function testDeletionRefusesAnAdministratorFirstAndThenAMergedAccount(): void
    {
        Hilos::$db->users[$this->userId]->actions->setAdmin(true);
        self::assertSame('Remove the admin rights first', $this->deletion($this->userId)->error);
        $mergedId = (int) Hilos::$db->users->actions->createWithName('Folded')->id;
        $this->fold($mergedId, $this->adminId);
        self::assertSame('This account was merged into another one', $this->deletion($mergedId)->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf($this->userId));
        self::assertNull(Hilos::$db->accountDeletions->liveOf($mergedId));
    }

    public function testRightsCountTabsAndKeepThePersonsSession(): void
    {
        $token = $this->signIn('user-a', $this->userId);
        Hilos::$rt->connections->actions->register('user-b', $this->userId, $token);
        self::assertSame('Admin rights granted. Open tabs updated: 2', $this->rights($this->userId, true)->successMessage);
        self::assertTrue(Hilos::$db->users[$this->userId]->admin);
        self::assertSame('Admin rights granted. Open tabs updated: 2', $this->rights($this->userId, true)->successMessage);
        self::assertSame('Admin rights removed. Open tabs updated: 2', $this->rights($this->userId, false)->successMessage);
        self::assertFalse(Hilos::$db->users[$this->userId]->admin);
        self::assertSame($this->userId, Hilos::$db->sessions->findByToken($token)?->userId);
    }

    public function testAnImpersonatedAdministratorCannotChangeAnotherAccount(): void
    {
        Hilos::$db->users[$this->userId]->actions->setAdmin(true);
        $this->signIn('impersonated-admin', $this->userId);
        $this->sessionOf('impersonated-admin')->actions->setImpersonator($this->adminId);
        self::assertSame(
            'Only an active administrator can do this',
            $this->rights($this->adminId, false, 'impersonated-admin')->error,
        );
        self::assertSame(
            'Only an active administrator can do this',
            $this->block($this->adminId, true, 'impersonated-admin')->error,
        );
        self::assertTrue(Hilos::$db->users[$this->adminId]->admin);
        self::assertFalse(Hilos::$db->users[$this->adminId]->block);
    }

    public function testRightsWithoutOpenTabsStateWhenTheyWillApply(): void
    {
        self::assertSame(
            'Admin rights granted. No open tabs: they apply at the next sign-in',
            $this->rights($this->userId, true)->successMessage,
        );
        self::assertSame(
            'Admin rights removed. No open tabs: it applies at the next sign-in',
            $this->rights($this->userId, false)->successMessage,
        );
    }

    public function testAPartialAnnouncementKeepsTheWriteAndReportsOnlyToldTabs(): void
    {
        $token = $this->signIn('user-a', $this->userId);
        Hilos::$rt->connections->actions->register('user-b', $this->userId, $token);
        $this->signIn('user-c', $this->userId);
        Hilos::initSignalRouter(new LifecycleFailingAnnouncementRouter());
        $reply = $this->rights($this->userId, true);
        self::assertNull($reply->error);
        self::assertTrue(Hilos::$db->users[$this->userId]->admin);
        self::assertSame(
            'Admin rights granted, but not every tab was told: 2 updated, the rest learn on reconnect',
            $reply->successMessage,
        );
    }

    private function signIn(string $acceptKey, int $userId): string
    {
        $token = RandomHelper::hex(16);
        $this->deliverHandshake($this->holder, new WebSocketHandshakeSignalDTO(
            headers: [], acceptKey: $acceptKey, cookies: [], clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(), sessionToken: $token,
        ));
        $this->authenticateSession($this->holder, $token, $userId, null);

        return $token;
    }

    private function block(int $userId, bool $block, string $acceptKey = 'admin-ak'): HandoverAnswerSignalData
    {
        return $this->ask(HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET, new AccountBlockSetSignalData(
            $userId, $block, HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
            $acceptKey, 'lifecycle-request', HilosSignalConstants::HILOS_USER_BLOCK_SET, null,
        ));
    }

    private function rights(int $userId, bool $admin, string $acceptKey = 'admin-ak'): HandoverAnswerSignalData
    {
        return $this->ask(HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET, new AccountAdminSetSignalData(
            $userId, $admin, HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
            $acceptKey, 'lifecycle-request', HilosSignalConstants::HILOS_USER_ADMIN_SET, null,
        ));
    }

    private function deletion(int $userId): HandoverAnswerSignalData
    {
        $this->drainSignals();
        $library = $this->usersLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(
            new AgentSignalData(new AccountDeletionSetSignalData(
                $userId, true, HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE,
                'admin-ak', 'lifecycle-request', HilosSignalConstants::HILOS_USER_DELETION_SET, null,
            )),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET,
        ));
        $reply = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $reply = $signal->data->data;
            }
        }
        self::assertNotNull($reply);

        return $reply;
    }

    private function ask(string $name, HandoverAskInterface $request): HandoverAnswerSignalData
    {
        $this->drainSignals();
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(new AgentSignalData($request), '', $name));
        $this->deliverLibraryFrames($this->holder);
        $reply = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                self::assertNull($reply, 'The waiting page is answered once');
                self::assertSame($request->replySignal, $signal->signalName->getName());
                $reply = $signal->data->data;
            }
        }
        self::assertNotNull($reply);
        self::assertSame('lifecycle-request', $reply->requestId);
        self::assertSame($request->acceptKey, $reply->acceptKey);
        self::assertSame($request->action, $reply->action);

        return $reply;
    }

    /**
     * Folds one account into another the way a merge leaves it: a merge row, then the sign-in closed.
     *
     * @param int $userId Folded account
     * @param int $survivorUserId Account it is folded into
     * @throws HilosException When either write fails
     */
    private function fold(int $userId, int $survivorUserId): void
    {
        Hilos::$db->userMerges->actions->add($userId, $survivorUserId);
        Hilos::$db->users[$userId]->actions->setBlock(true);
    }
}

/** Fails the second session announcement, leaving the first session's two tabs counted. */
final class LifecycleFailingAnnouncementRouter extends SignalRouter
{
    private int $announcements = 0;

    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        if ($signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE && ++$this->announcements === 2) {
            throw new HilosException('Announcement interrupted');
        }
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }
}
