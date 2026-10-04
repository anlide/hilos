<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\DTO\OAuthCreateAccountActionDTO;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripEndedSignalData;
use Hilos\Auth\OAuth\OAuthAccountTokenSigner;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Identity\IdentityType;
use Hilos\HilosException;
use Hilos\Legal\LegalConsentProjector;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/** The provider's first sign-in waits for consent before an account exists (HIL-1235). */
final class OAuthFirstSignInConsentTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string PROVIDER = 'oauth:github';
    private const string SETTINGS_AGENT_ID = 'oauth-consent-settings-test';

    /** Binds the signal router and the test's connection writer for provider sign-ins. */
    protected function setUp(): void
    {
        parent::setUp();
        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
    }

    public function testUnknownPairPausesWithoutAccountAndNeedsCurrentConsent(): void
    {
        [$acceptKey, $subject, $token] = $this->pause('new-' . RandomHelper::hex(6), 'new@example.test');
        $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject));

        $reply = $this->submit($acceptKey, $token, null);
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);
        $this->assertSame(AuthFlowOutcome::CODE_CONSENT_REQUIRED, $reply->code);
        $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject));
    }

    public function testOldRevisionsAreRefusedBeforeAccountCreation(): void
    {
        [$acceptKey, $subject, $token] = $this->pause('old-' . RandomHelper::hex(6), null);

        $reply = $this->submit($acceptKey, $token, ['terms' => 'outdated']);

        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);
        $this->assertSame(AuthFlowOutcome::CODE_CONSENT_REVISED, $reply->code);
        $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject));
    }

    public function testForeignAndExpiredTokensReturnToIdentifier(): void
    {
        [$acceptKey, $subject, $token] = $this->pause('expired-' . RandomHelper::hex(6), null);
        $foreign = $this->openSession('foreign-' . $subject);

        $reply = $this->submit($foreign, $token, LegalConsentProjector::acceptance());
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);
        $this->assertSame(AuthFlowOutcome::CODE_OAUTH_SIGN_IN_EXPIRED, $reply->code);

        $secret = Hilos::$env[ChatEnvConstants::OAUTH_STATE_SECRET]->string();
        $expired = new OAuthAccountTokenSigner($secret)->issue(
            self::PROVIDER,
            $subject,
            null,
            'Expired Person',
            Hilos::$rt->connections[$acceptKey]->sessionToken,
            -1,
        );
        $reply = $this->submit($acceptKey, $expired, LegalConsentProjector::acceptance());
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);
        $this->assertSame(AuthFlowOutcome::CODE_OAUTH_SIGN_IN_EXPIRED, $reply->code);
        $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject));
    }

    public function testConsentCreatesBothIdentitiesAndAcceptanceOnlyOnce(): void
    {
        [$acceptKey, $subject, $token] = $this->pause('accepted-' . RandomHelper::hex(6), 'accepted@example.test');
        $revisions = LegalConsentProjector::acceptance();

        $this->assertNull($this->submit($acceptKey, $token, $revisions));
        $identity = Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject);
        $this->assertNotNull($identity);
        $userId = $identity->userId;
        $this->assertNotNull($userId);
        $this->assertSame(
            $userId,
            Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, 'accepted@example.test')?->userId,
        );
        $this->assertCount(count($revisions), Hilos::$db->legalAcceptances->ofUser($userId));

        // A repeat is a sign-in of the existing pair even if the shown revision is now stale.
        $this->assertNull($this->submit($acceptKey, $token, ['terms' => 'outdated']));
        $this->assertCount(count($revisions), Hilos::$db->legalAcceptances->ofUser($userId));
    }

    public function testEmailClaimedAfterPauseReturnsToIdentifier(): void
    {
        [$acceptKey, $subject, $token] = $this->pause('claimed-' . RandomHelper::hex(6), 'claimed@example.test');
        $ownerId = (int)Hilos::$db->users->actions->createWithName('Existing Owner')->id;
        Hilos::$db->identities->createMagicLinkIdentity($ownerId, 'claimed@example.test');

        $reply = $this->submit($acceptKey, $token, LegalConsentProjector::acceptance());

        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);
        $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $reply->code);
        $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject));
    }

    public function testDisabledProviderRefusesTheConsentSubmit(): void
    {
        [$acceptKey, , $token] = $this->pause('disabled-' . RandomHelper::hex(6), null);
        $this->writeDisabledProvider(self::PROVIDER);
        try {
            $this->expectException(ValidationException::class);
            $this->submit($acceptKey, $token, LegalConsentProjector::acceptance());
        } finally {
            $this->writeDisabledProvider(null);
        }
    }

    public function testFailureInsideLandingRollsBackAccountAndAcceptance(): void
    {
        $subject = str_repeat('s', 300);
        $acceptKey = $this->openSession('rollback-' . RandomHelper::hex(6));
        $sessionToken = Hilos::$rt->connections[$acceptKey]->sessionToken;
        $token = ChatOAuthConfig::buildService()->issueAccountToken(
            self::PROVIDER,
            $subject,
            null,
            'Rollback Candidate',
            $sessionToken,
        );
        $before = count(Hilos::$db->users->listAll());
        $termsBefore = Hilos::$db->legalAcceptances->acceptedCounts('terms');
        $privacyBefore = Hilos::$db->legalAcceptances->acceptedCounts('privacy');

        try {
            $this->submit($acceptKey, $token, LegalConsentProjector::acceptance());
            $this->fail('Oversized OAuth identity must fail');
        } catch (HilosException) {
            $this->assertCount($before, Hilos::$db->users->listAll());
            $this->assertSame($termsBefore, Hilos::$db->legalAcceptances->acceptedCounts('terms'));
            $this->assertSame($privacyBefore, Hilos::$db->legalAcceptances->acceptedCounts('privacy'));
            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject));
        }
    }

    /**
     * @param string $subject Provider subject
     * @param ?string $email Provider email
     * @return array{string, string, string} Connection, subject and consent token
     * @throws HilosException When the login resolution fails
     */
    private function pause(string $subject, ?string $email): array
    {
        $acceptKey = $this->openSession($subject);
        $sessionToken = Hilos::$rt->connections[$acceptKey]->sessionToken;
        $this->usersLibrary()->onSignalAgent(
            new AgentSignalData(data: new OAuthLoginReadySignalData(
                self::PROVIDER,
                $subject,
                $email,
                'Provider Person',
                $acceptKey,
                $sessionToken,
                hash('sha256', $subject),
            )),
            '',
            HilosSignalConstants::HILOS_OAUTH_LOGIN_READY,
        );

        $token = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData
                && $signal->data->data instanceof OAuthTripEndedSignalData
                && $signal->data->data->reason === OAuthResultSignalData::REASON_CONSENT_REQUIRED
            ) {
                $token = $signal->data->data->accountToken;
            }
        }
        $this->assertNotNull($token);

        return [$acceptKey, $subject, $token];
    }

    /**
     * @param string $key Unique browser id
     * @return string Accept key
     */
    private function openSession(string $key): string
    {
        $acceptKey = 'ak-' . $key;
        $sessionToken = RandomHelper::hex(16);
        $session = Hilos::$db->sessions->actions->createAnonymous($sessionToken);
        Hilos::$rt->connections->actions->register($acceptKey, null, $sessionToken, (int)$session->id);

        return $acceptKey;
    }

    /**
     * @param string $acceptKey Browser connection
     * @param string $token Signed first sign-in capability
     * @param ?array<string, string> $revisions Accepted revisions
     * @return ?AuthFlowOutcome Refusal, or null when a grant was sent
     * @throws HilosException When account creation fails
     */
    private function submit(string $acceptKey, string $token, ?array $revisions): ?AuthFlowOutcome
    {
        return $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT,
            new OAuthCreateAccountActionDTO($token, $revisions),
        );
    }

    /** @param ?string $provider Disabled provider, or null to clear the switch */
    private function writeDisabledProvider(?string $provider): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::SETTINGS_AGENT_ID);
        try {
            Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->actions->delete();
            if ($provider !== null) {
                Hilos::$db->settings->actions->add(
                    AuthMethodSettings::DISABLED_KEY,
                    $provider,
                    Hilos::$setting->catalog(),
                );
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
            ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        }
    }
}
