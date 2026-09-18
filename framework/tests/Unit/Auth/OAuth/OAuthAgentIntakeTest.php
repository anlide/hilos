<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Constants\HilosSignalConstants;
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
 * hold is answered on the next tick with the failed-login result, which is a step no op
 * reaches unless it was adopted. The exchange over HTTP is proved where it is real — the
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
                new OAuthPendingLoginSignalData('ak-1', 'session-1', self::UNKNOWN_KEY, 'code-1', $this->farDeadline()),
            ),
            'test-source',
            HilosSignalConstants::HILOS_OAUTH_PENDING,
        );

        $agent->onTick();

        $this->assertCount(1, $agent->sent);
        $this->assertSame(HilosSignalConstants::HILOS_OAUTH_RESULT, $agent->sent[0]['name']);
        $this->assertSame('ak-1', $agent->sent[0]['acceptKey']);
        $result = $agent->sent[0]['data'];
        $this->assertInstanceOf(OAuthResultSignalData::class, $result);
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $result->reason);
        $this->assertSame([], $agent->completed);

        // The op is cleared once processed, so a second tick does not answer it again.
        $agent->onTick();
        $this->assertCount(1, $agent->sent);
    }

    public function testUnknownSignalNameIsRefused(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();

        $this->expectException(AgentUnknownSignalException::class);
        $agent->onSignalAgent(
            new AgentSignalData(
                new OAuthPendingLoginSignalData('ak-1', 'session-1', self::KNOWN_KEY, 'code-1', $this->farDeadline()),
            ),
            'test-source',
            'not_the_pending_signal',
        );
    }

    public function testWrongPayloadTypeIsDroppedNotAdopted(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();

        $agent->onSignalAgent(
            new AgentSignalData(new OAuthResultSignalData('ak-1', self::KNOWN_KEY)),
            'test-source',
            HilosSignalConstants::HILOS_OAUTH_PENDING,
        );
        $agent->onTick();

        $this->assertSame([], $agent->completed);
        $this->assertSame([], $agent->sent);
    }

    /**
     * @return AbstractOAuthAgent&object{
     *     completed: list<array{op: OAuthPendingLogin, info: OAuthUserInfo}>,
     *     sent: list<array{name: string, acceptKey: string, data: SignalDataInterface}>
     * }
     */
    private function makeAgent(): AbstractOAuthAgent
    {
        return new class extends AbstractOAuthAgent {
            /** @var list<array{op: OAuthPendingLogin, info: OAuthUserInfo}> */
            public array $completed = [];

            /** @var list<array{name: string, acceptKey: string, data: SignalDataInterface}> Signals sent to a user, oldest first */
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

            public function sendToUser(string $signalName, string $targetAcceptKey, SignalDataInterface $data): void
            {
                $this->sent[] = ['name' => $signalName, 'acceptKey' => $targetAcceptKey, 'data' => $data];
            }

            protected function completeOAuthLogin(OAuthPendingLogin $op, OAuthUserInfo $info): void
            {
                $this->completed[] = ['op' => $op, 'info' => $info];
            }
        };
    }

    /**
     * @return float A deadline far enough ahead that the exchange never expires mid-test
     */
    private function farDeadline(): float
    {
        return microtime(true) * 1000 + 60_000.0;
    }
}
