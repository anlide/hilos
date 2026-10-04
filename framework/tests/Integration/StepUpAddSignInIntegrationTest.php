<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\LinkOAuthStartActionDTO;
use Hilos\Auth\Library\DTO\OAuthCallbackActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthAccountTokenSigner;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosProfileSignInPage;
use Random\RandomException;

/**
 * The add-a-way-in confirmation on the device key and the provider link, against the real tables (HIL-1138).
 *
 * The password and phone adds are pinned beside their own cases. These are the two ways in whose
 * steps leave the process - a device prompt, a trip to the provider - and what is pinned is that
 * the refusal comes BEFORE the frame that would open them, that the second step asks again, and
 * that a return in login mode, which has no account to add to, asks nothing.
 */
final class StepUpAddSignInIntegrationTest extends ProfileIntegrationTestCase
{
    /** The confirmed address that is the proof the gate asks for; without one the adds pass. */
    private const string EMAIL = 'add-sign-in@example.test';

    /** Challenge secret the device-key options are signed with here; the stand's env leaves it empty. */
    private const string CHALLENGE_SECRET = 'add-sign-in-test-challenge-secret';

    /** Secret the provider state is signed with, on the start and on the return alike. */
    private const string OAUTH_SECRET = 'add-sign-in-test-oauth-secret';

    /** Lifetime of a provider state or link token; longer than any case. */
    private const int STATE_TTL_SECONDS = 600;

    private const string TRIP_ID = 'trip-1138';

    private OAuthService $oauth;

    /**
     * @throws DatabaseException When a stub statement or seed fails
     * @throws HilosException When the runtime context or the provider wiring cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();
        putenv(EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET->name . '=' . self::CHALLENGE_SECRET);
        $this->oauth = new OAuthService(
            new OAuthProviderRegistry([new GenericOAuthProvider(
                OAuthProviderPreset::GITHUB->config('client-1138', 'secret-1138', 'https://app.example/auth/callback'),
            )]),
            new OAuthStateSigner(self::OAUTH_SECRET),
            self::STATE_TTL_SECONDS,
            new OAuthLinkTokenSigner(self::OAUTH_SECRET),
            self::STATE_TTL_SECONDS,
            new OAuthAccountTokenSigner(self::OAUTH_SECRET),
            1800,
        );
        $this->library->oauthService = $this->oauth;
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        putenv(EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET->name);

        parent::tearDown();
    }

    /**
     * The device-key options are refused before the frame that opens the device prompt; confirmed, they go out.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testDeviceKeyOptionsWaitForTheConfirmation(): void
    {
        $this->assertRefused(
            StepUpMessages::EXPIRED,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS,
            new PasskeyRegisterOptionsActionDTO(),
        );
        self::assertNotContains(HilosSignalConstants::HILOS_PASSKEY_OPTIONS, $this->queuedNames());

        $this->confirmStepUp(StepUpOperationKey::ADD_SIGN_IN_METHOD);
        $this->submit(HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS, new PasskeyRegisterOptionsActionDTO());
        self::assertContains(HilosSignalConstants::HILOS_PASSKEY_OPTIONS, $this->queuedNames());
    }

    /**
     * The device-key confirm asks the confirmation again, before the ceremony is read.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testDeviceKeyConfirmAsksTheConfirmationBeforeTheCeremony(): void
    {
        $dto = new PasskeyRegisterConfirmActionDTO('not-a-challenge', '', '', [], null);

        $this->assertRefused(StepUpMessages::EXPIRED, HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM, $dto);

        $this->confirmStepUp(StepUpOperationKey::ADD_SIGN_IN_METHOD);
        $this->assertRefused(AuthMessages::INVALID_PASSKEY, HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM, $dto);
        self::assertSame([[IdentityType::MAGIC_LINK, self::EMAIL, true]], self::rowsOf(self::USER_ID));
    }

    /**
     * Starting a link is refused before the authorize URL is minted; confirmed, the URL goes out to the tab.
     *
     * @throws HilosException When the page fails for another reason
     */
    public function testStartingALinkWaitsForTheConfirmation(): void
    {
        $page = new StepUpAddSignInTestPage(new StepUpAddSignInTestAgent(), $this->oauth);
        $dto = new LinkOAuthStartActionDTO(OAuthProviderPreset::GITHUB->value, self::TRIP_ID);

        try {
            $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LINK_OAUTH_START, $dto);
            self::fail('A link start must be refused without a confirmation');
        } catch (ValidationException $exception) {
            self::assertSame(StepUpMessages::EXPIRED, $exception->getMessage());
        }
        self::assertNotContains(HilosSignalConstants::HILOS_OAUTH_AUTHORIZE, $this->queuedNames());

        $this->confirmStepUp(StepUpOperationKey::ADD_SIGN_IN_METHOD);
        self::assertNull($page->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LINK_OAUTH_START, $dto));
        self::assertContains(HilosSignalConstants::HILOS_OAUTH_AUTHORIZE, $this->queuedNames());
    }

    /**
     * A tab whose session lost its person cannot start a link: the page refuses as the library does.
     *
     * @throws HilosException When the page fails for another reason
     */
    public function testASignedOutTabCannotStartALink(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);
        $this->expectExceptionMessage('User session not found');

        new StepUpAddSignInTestPage(new StepUpAddSignInTestAgent(), $this->oauth)->onAction(
            self::ANONYMOUS_ACCEPT_KEY,
            HilosSignalConstants::HILOS_LINK_OAUTH_START,
            new LinkOAuthStartActionDTO(OAuthProviderPreset::GITHUB->value, self::TRIP_ID),
        );
    }

    /**
     * A link-mode return asks the confirmation again, and hands nothing to the exchange without it.
     *
     * @throws RandomException When the state nonce cannot be drawn
     * @throws HilosException When a command fails for another reason
     */
    public function testALinkModeReturnAsksTheConfirmationAgain(): void
    {
        $dto = $this->providerReturn(OAuthStateSigner::MODE_LINK);

        $this->assertRefused(StepUpMessages::EXPIRED, HilosSignalConstants::HILOS_OAUTH_CALLBACK, $dto);
        $names = $this->queuedNames();
        self::assertNotContains(HilosSignalConstants::HILOS_OAUTH_PENDING, $names);
        self::assertNotContains(HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED, $names);

        $this->confirmStepUp(StepUpOperationKey::ADD_SIGN_IN_METHOD);
        $this->submit(HilosSignalConstants::HILOS_OAUTH_CALLBACK, $dto);
        self::assertContains(HilosSignalConstants::HILOS_OAUTH_PENDING, $this->queuedNames());
    }

    /**
     * A login-mode return has no account to add to and asks no confirmation.
     *
     * @throws RandomException When the state nonce cannot be drawn
     * @throws HilosException When the command fails
     */
    public function testALoginModeReturnAsksNoConfirmation(): void
    {
        $this->submit(HilosSignalConstants::HILOS_OAUTH_CALLBACK, $this->providerReturn(OAuthStateSigner::MODE_LOGIN));

        self::assertContains(HilosSignalConstants::HILOS_OAUTH_PENDING, $this->queuedNames());
    }

    /**
     * A return from the provider on a state this browser's signer issued in the given mode.
     *
     * @param string $mode Login or link mode (see OAuthStateSigner::MODE_*)
     * @return OAuthCallbackActionDTO Callback payload the browser would submit
     * @throws RandomException When the state nonce cannot be drawn
     */
    private function providerReturn(string $mode): OAuthCallbackActionDTO
    {
        return new OAuthCallbackActionDTO(
            OAuthProviderPreset::GITHUB->value,
            'provider-code',
            new OAuthStateSigner(self::OAUTH_SECRET)->issue(self::SESSION_TOKEN, self::STATE_TTL_SECONDS, $mode),
            'trip-key-1138',
        );
    }

    /**
     * @return list<string> Names of every signal queued since the last call, which drains them
     */
    private function queuedNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }
}

/**
 * Concrete profile sign-in page over the case's provider wiring: the abstract one carries the whole behavior.
 */
final class StepUpAddSignInTestPage extends AbstractHilosProfileSignInPage
{
    /**
     * @param PageAgentInterface $agent Agent the page answers for
     * @param OAuthService $oauth Provider wiring, the same the library verifies the return with
     */
    public function __construct(PageAgentInterface $agent, private readonly OAuthService $oauth)
    {
        parent::__construct($agent);
    }

    /**
     * @return ?OAuthService The case's service
     */
    protected function oauthService(): ?OAuthService
    {
        return $this->oauth;
    }
}

/**
 * Page agent carrying only what a page may reach for: its id and its signal source.
 */
final class StepUpAddSignInTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_profile';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}
