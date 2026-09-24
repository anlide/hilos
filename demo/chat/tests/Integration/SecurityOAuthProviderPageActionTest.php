<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\OAuthProvider as EntityOAuthProvider;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Pages\Security\DTO\HilosOAuthProviderResetActionDTO;
use Hilos\Pages\Security\DTO\HilosOAuthProviderSetActionDTO;

/**
 * Integration coverage for the method set a provider's field write sends (HIL-1080).
 *
 * A write that completes or breaks a provider's client pair moves its readiness, and with it
 * the entry every sign-in surface reads; the provider page sends the new set to every
 * connection itself. GitHub's pair is emptied in the process env for the case, so the pair is
 * whatever the page writes.
 */
final class SecurityOAuthProviderPageActionTest extends IntegrationTestCase
{
    private const string PROVIDERS_AGENT_ID = 'test-oauth-provider-writer';

    protected function setUp(): void
    {
        parent::setUp();

        // The harness runs no worker, so nothing has queued the router the page hands its frames to.
        Hilos::$sr = new SignalRouter();
    }

    /**
     * A write that leaves readiness alone sends nothing; completing and breaking the pair each send the set.
     */
    public function testAWriteThatMovedReadinessSendsTheMethodSet(): void
    {
        $this->withoutGitHubPair(function (): void {
            $this->page()->onAction('provider-ak', HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET, new HilosOAuthProviderSetActionDTO(
                OAuthProviderPreset::GITHUB->value,
                OAuthConfigField::CLIENT_ID->value,
                'admin-client',
            ));
            $this->assertSame([], $this->methodSetFrames());

            $this->page()->onAction('provider-ak', HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET, new HilosOAuthProviderSetActionDTO(
                OAuthProviderPreset::GITHUB->value,
                OAuthConfigField::CLIENT_SECRET->value,
                'admin-secret',
            ));
            $this->assertTrue($this->gitHubReadyIn($this->methodSetFrames()));

            $this->page()->onAction('provider-ak', HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET, new HilosOAuthProviderResetActionDTO(
                OAuthProviderPreset::GITHUB->value,
                OAuthConfigField::CLIENT_SECRET->value,
            ));
            $this->assertFalse($this->gitHubReadyIn($this->methodSetFrames()));
        });
    }

    /**
     * Reads GitHub's readiness out of the one method-set frame sent to every connection.
     *
     * @param list<SignalDTO> $frames Method-set frames taken off the router
     * @return bool Whether the frame says GitHub is ready
     */
    private function gitHubReadyIn(array $frames): bool
    {
        $this->assertCount(1, $frames);
        $this->assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $frames[0]->signalType->getType());
        $this->assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
        $this->assertInstanceOf(AuthMethodsSignalData::class, $frames[0]->data->data);

        $ready = array_column($frames[0]->data->data->authMethods, AuthMethodsSignalData::ready, AuthMethodsSignalData::key);
        $this->assertArrayHasKey(OAuthProviderPreset::GITHUB->value, $ready);

        return $ready[OAuthProviderPreset::GITHUB->value];
    }

    /**
     * Takes every queued sign-in method set off the router, leaving nothing behind.
     *
     * @return list<SignalDTO> Queued method-set frames in the order they were sent
     */
    private function methodSetFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_AUTH_METHODS) {
                $frames[] = $signal;
            }
        }

        return $frames;
    }

    /**
     * @return SecurityOAuthProviderPage Provider page bound to the Hilos index agent that owns it
     */
    private function page(): SecurityOAuthProviderPage
    {
        return new SecurityOAuthProviderPage(new DemoHilosAgent());
    }

    /**
     * Runs a body with GitHub's env pair emptied and the provider writer held, and takes GitHub's row away afterwards.
     *
     * @param callable():void $body Test body run while GitHub's pair is whatever the page writes
     */
    private function withoutGitHubPair(callable $body): void
    {
        $previous = [];
        foreach ([ChatEnvConstants::OAUTH_GITHUB_CLIENT_ID, ChatEnvConstants::OAUTH_GITHUB_CLIENT_SECRET] as $key) {
            $previous[$key] = getenv($key);
            putenv($key . '=');
        }
        TruthSourceRegistry::register(HilosDbContext::oauthProviders, TruthSourceKeys::all(), self::PROVIDERS_AGENT_ID);

        try {
            $body();
        } finally {
            $params = SqlParamCollection::empty();
            $params->add(SqlParam::string(OAuthProviderPreset::GITHUB->value));
            Database::sql(
                'DELETE FROM `' . EntityOAuthProvider::_table . '` WHERE `' . EntityOAuthProvider::provider_key . '` = ?',
                $params,
            );
            Hilos::$db->mountedObjectCollection(HilosDbContext::oauthProviders)?->reHydrate();
            TruthSourceRegistry::unregisterAgent(self::PROVIDERS_AGENT_ID);
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
