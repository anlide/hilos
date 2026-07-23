<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Auth\OAuth\StubOAuthProvider;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth agent's pending-login intake (HIL-281).
 *
 * The callback hands a verified op to the monopolistic agent point-to-point over the
 * {@see HilosSignalConstants::HILOS_OAUTH_PENDING} agent signal, and the agent drains it
 * from its own runtime state on the next tick — the fix for the cross-process handoff that
 * a shared runtime collection silently dropped. The offline stub provider resolves the code
 * in-process, so a single tick carries a delivered op all the way to {@see completeOAuthLogin}
 * with no sockets.
 */
final class OAuthAgentIntakeTest extends TestCase
{
    public function testDeliveredPendingOpIsAdoptedAndCompletedOnTick(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();

        $agent->onSignalAgent(
            new AgentSignalData(
                new OAuthPendingLoginSignalData('ak-1', 'session-1', StubOAuthProvider::DEFAULT_KEY, 'stub', $this->farDeadline()),
            ),
            'test-source',
            HilosSignalConstants::HILOS_OAUTH_PENDING,
        );

        $agent->onTick();

        $this->assertCount(1, $agent->completed);
        $this->assertSame('ak-1', $agent->completed[0]['op']->acceptKey);
        $this->assertSame('session-1', $agent->completed[0]['op']->sessionToken);
        $this->assertSame('stub:stub', $agent->completed[0]['info']->subject);

        // The op is cleared once resolved, so a second tick does not complete it again.
        $agent->onTick();
        $this->assertCount(1, $agent->completed);
    }

    public function testUnknownSignalNameIsRefused(): void
    {
        $agent = $this->makeAgent();
        $agent->onStart();

        $this->expectException(AgentUnknownSignalException::class);
        $agent->onSignalAgent(
            new AgentSignalData(
                new OAuthPendingLoginSignalData('ak-1', 'session-1', StubOAuthProvider::DEFAULT_KEY, 'stub', $this->farDeadline()),
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
            new AgentSignalData(new OAuthResultSignalData('ak-1', StubOAuthProvider::DEFAULT_KEY)),
            'test-source',
            HilosSignalConstants::HILOS_OAUTH_PENDING,
        );
        $agent->onTick();

        $this->assertSame([], $agent->completed);
    }

    /**
     * @return AbstractOAuthAgent&object{completed: list<array{op: OAuthPendingLogin, info: OAuthUserInfo}>}
     */
    private function makeAgent(): AbstractOAuthAgent
    {
        return new class extends AbstractOAuthAgent {
            /** @var list<array{op: OAuthPendingLogin, info: OAuthUserInfo}> */
            public array $completed = [];

            protected function buildProviderRegistry(): OAuthProviderRegistry
            {
                return new OAuthProviderRegistry([new StubOAuthProvider()]);
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
