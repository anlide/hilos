<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for when the OAuth agent reads its providers (HIL-286).
 *
 * The credentials behind a provider are what an administrator enters in the admin, so the
 * agent may not hold a registry from its start: it builds one for every exchange it starts,
 * and a configuration it cannot read fails that one login rather than the agent's tick.
 */
final class OAuthAgentRegistryTest extends TestCase
{
    /** Provider key the test registries never hold, so no exchange opens a socket. */
    private const string PROVIDER_KEY = 'oauth:github';

    public function testStartingTheAgentReadsNoProvider(): void
    {
        $agent = $this->makeAgent(fails: false);
        $agent->onStart();

        $this->assertSame(0, $agent->builds);
    }

    public function testEveryExchangeReadsTheProvidersAgain(): void
    {
        $agent = $this->makeAgent(fails: false);
        $agent->onStart();

        $this->deliver($agent, 'ak-1');
        $agent->onTick();
        $this->deliver($agent, 'ak-2');
        $agent->onTick();

        $this->assertSame(2, $agent->builds);
    }

    public function testAnUnreadableConfigurationFailsTheLoginNotTheTick(): void
    {
        $agent = $this->makeAgent(fails: true);
        $agent->onStart();

        $this->deliver($agent, 'ak-1');
        $agent->onTick();

        $this->assertCount(1, $agent->sent);
        $result = $agent->sent[0]['data'];
        $this->assertInstanceOf(OAuthResultSignalData::class, $result);
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $result->reason);
    }

    /**
     * Hands the agent one pending login for the provider key.
     *
     * @param AbstractOAuthAgent $agent Agent under test
     * @param string $acceptKey Accept key of the login
     */
    private function deliver(AbstractOAuthAgent $agent, string $acceptKey): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(new OAuthPendingLoginSignalData(
                $acceptKey,
                'session-1',
                self::PROVIDER_KEY,
                'code-1',
                microtime(true) * 1000 + 60_000.0,
            )),
            'test-source',
            HilosSignalConstants::HILOS_OAUTH_PENDING,
        );
    }

    /**
     * @param bool $fails Whether reading the providers raises
     * @return AbstractOAuthAgent&object{builds: int, sent: list<array{name: string, acceptKey: string, data: SignalDataInterface}>}
     */
    private function makeAgent(bool $fails): AbstractOAuthAgent
    {
        return new class ($fails) extends AbstractOAuthAgent {
            /** Times the providers were read. */
            public int $builds = 0;

            /** @var list<array{name: string, acceptKey: string, data: SignalDataInterface}> Signals sent to a user, oldest first */
            public array $sent = [];

            /**
             * @param bool $fails Whether reading the providers raises
             */
            public function __construct(private readonly bool $fails)
            {
            }

            /**
             * @return OAuthProviderRegistry An empty registry
             * @throws DatabaseException When the case asks for an unreadable configuration
             */
            protected function buildProviderRegistry(): OAuthProviderRegistry
            {
                $this->builds++;
                if ($this->fails) {
                    throw new DatabaseException('provider rows unreadable');
                }

                return new OAuthProviderRegistry();
            }

            public function sendToUser(string $signalName, string $targetAcceptKey, SignalDataInterface $data): void
            {
                $this->sent[] = ['name' => $signalName, 'acceptKey' => $targetAcceptKey, 'data' => $data];
            }
        };
    }
}
