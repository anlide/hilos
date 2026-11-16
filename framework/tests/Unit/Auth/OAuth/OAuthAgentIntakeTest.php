<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripEndedSignalData;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Users\DTO\OAuthIdentityAddData;
use Hilos\Users\DTO\UserIdentityAddSignalData;
use Hilos\Users\DTO\UserIdentityAddDoneSignalData;
use Hilos\Runtime\State\Collection\OAuthPendingLogins;
use ReflectionProperty;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth agent's pending-login intake (HIL-281).
 *
 * The callback hands a verified op to the monopolistic agent point-to-point over the
 * {@see HilosSignalConstants::HILOS_OAUTH_PENDING} agent signal, and the agent drains it
 * from its own runtime state on the next tick — the fix for the cross-process handoff that
 * a shared runtime collection silently dropped.
 *
 * The intake is proved without sockets: a delivered op whose provider the registry does not
 * hold is ended on the next tick with the failed-login ending reported to the session holder
 * (HIL-1044), which is a step no op reaches unless it was adopted. The exchange over HTTP is proved where it is real — the
 * sign-in e2e through the stand's provider emulator (HIL-924).
 */
final class OAuthAgentIntakeTest extends TestCase
{
    /** Provider key the agent's registry does not hold. */
    private const string UNKNOWN_KEY = 'oauth:gitlab';

    /** Provider key the agent's registry holds; public because the agent class below reads it. */
    public const string KNOWN_KEY = 'oauth:github';

    public function testDeliveredPendingOpIsAdoptedAndProcessedOnTick(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();

        $agent->onSignalAgent(
            new AgentSignalData(
                new OAuthPendingLoginSignalData(
                    'ak-1',
                    'session-1',
                    self::UNKNOWN_KEY,
                    'code-1',
                    'trip-hash-1',
                ),
            ),
            'test-source',
            HilosSignalConstants::HILOS_OAUTH_PENDING,
        );

        $agent->onTick();

        $this->assertCount(1, $agent->sent);
        $this->assertSame(HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED, $agent->sent[0]['name']);
        $ended = $agent->sent[0]['data'];
        $this->assertInstanceOf(OAuthTripEndedSignalData::class, $ended);
        $this->assertSame('ak-1', $ended->acceptKey);
        $this->assertSame('trip-hash-1', $ended->tripKeyHash);
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $ended->reason);
        $this->assertSame([], $agent->completed);

        // The op is cleared once processed, so a second tick does not answer it again.
        $agent->onTick();
        $this->assertCount(1, $agent->sent);
    }

    public function testStoppingTheAgentAnswersEveryLoginItStillHolds(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();
        $agent->onSignalAgent(
            new AgentSignalData(
                new OAuthPendingLoginSignalData(
                    'ak-1',
                    'session-1',
                    self::KNOWN_KEY,
                    'code-1',
                    'trip-hash-1',
                ),
            ),
            'test-source',
            HilosSignalConstants::HILOS_OAUTH_PENDING,
        );

        $agent->onStop();

        // The tab waiting on this exchange has no clock left to end its wait (HIL-1044).
        $this->assertCount(1, $agent->sent);
        $ended = $agent->sent[0]['data'];
        $this->assertInstanceOf(OAuthTripEndedSignalData::class, $ended);
        $this->assertSame('trip-hash-1', $ended->tripKeyHash);
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $ended->reason);
    }

    public function testUnknownSignalNameIsRefused(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();

        $this->expectException(AgentUnknownSignalException::class);
        $agent->onSignalAgent(
            new AgentSignalData(
                new OAuthPendingLoginSignalData(
                    'ak-1',
                    'session-1',
                    self::KNOWN_KEY,
                    'code-1',
                    'trip-hash-1',
                ),
            ),
            'test-source',
            'not_the_pending_signal',
        );
    }

    public function testAwaitingIdentityAdditionDoesNotRestartHttpAndStopsAsALinkFailure(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();
        self::waiting($agent, 'accept', 'trip', 7);
        $agent->onTick();
        $agent->onTick();
        self::assertSame([], $agent->sent);
        $agent->onStop();
        self::assertCount(1, $agent->sent);
        self::assertSame(OAuthResultSignalData::REASON_LINK_FAILED, $agent->sent[0]['data']->reason);
    }

    public function testStaleAndForeignDoneNeverClearTheCurrentAttempt(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();
        $pending = self::waiting($agent, 'accept', 'current', 7, self::KNOWN_KEY);
        foreach ([['old', 7, self::KNOWN_KEY], ['current', 8, self::KNOWN_KEY], ['current', 7, self::UNKNOWN_KEY]] as [$trip, $user, $provider]) {
            $done = UserIdentityAddDoneSignalData::ok(self::linkAsk('accept', $trip, $user, $provider), 9);
            $agent->onSignalAgent(new AgentSignalData($done), 'personal', HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE);
            self::assertNotNull($pending->get('accept'));
            self::assertSame([], $agent->sent);
        }
        $done = UserIdentityAddDoneSignalData::ok(self::linkAsk('accept', 'current', 7, self::KNOWN_KEY), 9);
        $agent->onSignalAgent(new AgentSignalData($done), 'personal', HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE);
        self::assertNull($pending->get('accept'));
        self::assertSame(OAuthResultSignalData::REASON_LINK_OK, $agent->sent[0]['data']->reason);
        $agent->onSignalAgent(new AgentSignalData($done), 'personal', HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE);
        $agent->onSignalAgent(
            new AgentSignalData(new AgentsGoneSignalData([HilosAgentType::HILOS_USER . ':7'], AgentsGoneSignalData::REASON_WORKER_DIED)),
            'daemon',
            HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE,
        );
        self::assertCount(1, $agent->sent);
    }

    public function testGoneEndsOnlyTheExactPersonalOwnersAwaitingLinksAndLateDoneDoesNotRetry(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();
        $pending = self::waiting($agent, 'mine', 'trip-mine', 7);
        self::waiting($agent, 'other', 'trip-other', 8);
        $pending->add(OAuthPendingLogin::create('login', 'session', self::UNKNOWN_KEY, 'code', 'login-trip'));
        $pending->add(OAuthPendingLogin::create('http', 'session', self::UNKNOWN_KEY, 'code', 'http-trip', OAuthPendingLogin::MODE_LINK, 7));
        $agent->onSignalAgent(
            new AgentSignalData(new AgentsGoneSignalData([HilosAgentType::HILOS_USER . ':70'], AgentsGoneSignalData::REASON_WORKER_DIED)),
            'daemon',
            HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE,
        );
        self::assertSame([], $agent->sent);
        $agent->onSignalAgent(
            new AgentSignalData(new AgentsGoneSignalData([HilosAgentType::HILOS_USER . ':7'], AgentsGoneSignalData::REASON_WORKER_DIED)),
            'daemon',
            HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE,
        );
        self::assertNull($pending->get('mine'));
        foreach (['other', 'login', 'http'] as $key) {
            self::assertNotNull($pending->get($key));
        }
        self::assertCount(1, $agent->sent);
        self::assertSame('mine', $agent->sent[0]['data']->acceptKey);
        self::assertSame(OAuthResultSignalData::REASON_LINK_FAILED, $agent->sent[0]['data']->reason);
        $done = UserIdentityAddDoneSignalData::ok(self::linkAsk('mine', 'trip-mine', 7, self::UNKNOWN_KEY), 9);
        $agent->onSignalAgent(new AgentSignalData($done), 'personal', HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE);
        self::assertCount(1, $agent->sent);
    }

    public function testAddRefusalsKeepOnlyTheStableLinkOutcome(): void
    {
        foreach ([
            [ActionRefusal::said(AccountMergeAccess::REFUSED_MESSAGE), OAuthResultSignalData::REASON_ACCOUNT_MERGING],
            [ActionRefusal::said(AuthMessages::INVALID_LINK), OAuthResultSignalData::REASON_LINK_DUPLICATE],
            [ActionRefusal::said('private failure detail'), OAuthResultSignalData::REASON_LINK_FAILED],
        ] as [$refusal, $reason]) {
            $agent = $this->makeAgent();
            $agent->onStart();
            $pending = self::waiting($agent, 'accept', 'trip', 7);
            $done = UserIdentityAddDoneSignalData::refused(self::linkAsk('accept', 'trip', 7, self::UNKNOWN_KEY), $refusal);
            $agent->onSignalAgent(new AgentSignalData($done), 'personal', HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE);
            self::assertNull($pending->get('accept'));
            self::assertCount(1, $agent->sent);
            self::assertSame($reason, $agent->sent[0]['data']->reason);
        }
    }

    private static function waiting(
        AbstractOAuthAgent $agent,
        string $acceptKey,
        string $trip,
        int $userId,
        string $provider = self::UNKNOWN_KEY,
    ): OAuthPendingLogins {
        $pending = new ReflectionProperty(AbstractOAuthAgent::class, 'pending')->getValue($agent);
        self::assertInstanceOf(OAuthPendingLogins::class, $pending);
        $pending->add(OAuthPendingLogin::create($acceptKey, 'session', $provider, 'code', $trip, OAuthPendingLogin::MODE_LINK, $userId));
        $pending->markAwaitingIdentityAdd($acceptKey, $trip);

        return $pending;
    }

    private static function linkAsk(string $acceptKey, string $trip, int $userId, string $provider): UserIdentityAddSignalData
    {
        return new UserIdentityAddSignalData($userId, 1, new OAuthIdentityAddData($provider, 'subject', null), $trip,
            HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE, $acceptKey, null, HilosSignalConstants::HILOS_OAUTH_CALLBACK, null);
    }

    /**
     * @return AbstractOAuthAgent&object{
     *     completed: list<array{op: OAuthPendingLogin, info: OAuthUserInfo}>,
     *     sent: list<array{name: string, data: SignalDataInterface}>
     * }
     */
    private function makeAgent(): AbstractOAuthAgent
    {
        return new class extends AbstractOAuthAgent {
            /** @var list<array{op: OAuthPendingLogin, info: OAuthUserInfo}> */
            public array $completed = [];

            /** @var list<array{name: string, data: SignalDataInterface}> Frames sent to another agent, oldest first */
            public array $sent = [];

            protected function buildProviderRegistry(): OAuthProviderRegistry
            {
                return new OAuthProviderRegistry([
                    new GenericOAuthProvider(new OAuthProviderConfig(
                        key: OAuthAgentIntakeTest::KNOWN_KEY,
                        clientId: 'client-123',
                        clientSecret: 'secret-xyz',
                        authorizeUrl: 'https://provider.example/authorize',
                        tokenUrl: 'https://provider.example/token',
                        userInfoUrl: 'https://provider.example/userinfo',
                        scope: 'read:user',
                        redirectUri: 'https://app.example/auth/callback',
                        subjectKey: 'id',
                        emailKey: 'email',
                        nameKey: 'login',
                    )),
                ]);
            }

            public function sendToAgent(string $signalName, SignalDataInterface $data): void
            {
                $this->sent[] = ['name' => $signalName, 'data' => $data];
            }

            protected function completeOAuthLogin(OAuthPendingLogin $op, OAuthUserInfo $info): void
            {
                $this->completed[] = ['op' => $op, 'info' => $info];
            }
        };
    }
}
