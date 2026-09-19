<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\DetectIdentifierActionDTO;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Constants\EnvConstants;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\HilosException;
use Hilos\Mail\MailTransportFactory;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Sms\SmsChannelConfig;

/**
 * Integration tests for the live identifier lookup (HIL-414).
 *
 * The endpoint the identifier-first surface reveals itself from: one field is
 * typed into, and the answer decides whether a password appears under it, a code
 * screen opens, or a way to register is offered. What the unit tests cannot reach
 * is exactly what is asserted here — the answer comes out of the identity layer
 * and the registration holds, so every case below is a real row.
 *
 * Two properties get their own cases because getting them wrong is invisible on a
 * happy path: an address held by an abandoned registration must read as `pending`
 * rather than free (otherwise a second code is asked for), and a method the
 * project has NOT enabled must never be named (otherwise the surface renders a
 * button whose submit the backend refuses).
 *
 * The installation's plumbing is the third property (HIL-973): an account on a
 * deployment that cannot send is not offered what would be sent, and one left with
 * nothing is told why. Those cases take the transport away through the process
 * environment, which wins on every read, and put back what the stand set.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPageDetectIdentifierTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Agent id the fixture writes the method setting under. */
    private const string SETTINGS_AGENT_ID = 'test-settings-writer';
    private const string PASSWORD = 'correct horse battery';

    /**
     * A free address is `none` and names what this project can register it with.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testFreeEmailIsNoneAndNamesWhatItCanBeRegisteredWith(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'free-email-ak');

        try {
            $detection = $this->detect($agent, 'free-email-ak', $this->uniqueEmail());

            $this->assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
            $this->assertSame(IdentifierDetection::KIND_EMAIL, $detection->kind);
            $this->assertSame([], $detection->methods);
            $this->assertSame([AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK], $detection->registerable);
            // Nothing is withheld on a stand that has a relay and a gateway, so the
            // reason slot is empty - and the slot travelling at all is what tells the
            // surface apart from a deployment that closed registration (HIL-830).
            $this->assertNull($detection->registrationBlock);
            $this->assertArrayHasKey('registrationBlock', $detection->toArray());
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The reply echoes what was asked BYTE FOR BYTE, beside its normal form.
     *
     * The surface matches an answer to the field by the echo alone, against the raw
     * input - so neither a normalized phone nor a stripped paste may come back as
     * the echo. Whitespace is the case that decides it: the machine trims before
     * judging a value complete, so a padded address does reach the lookup, and an
     * answer trimmed on the way back would match nothing and reveal nothing.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testReplyEchoesWhatWasAskedBesideItsNormalForm(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'echo-ak');
        $email = $this->uniqueEmail();
        $typed = '  ' . strtoupper($email) . '  ';

        try {
            $detection = $this->detect($agent, 'echo-ak', $typed);

            $this->assertSame($typed, $detection->identifier);
            $this->assertSame($email, $detection->normalized);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An address somebody signs in with is `active` and names their password.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testTakenEmailIsActiveAndNamesItsPassword(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'taken-email-ak');
        $email = $this->uniqueEmail();
        $this->seedUser($email, IdentityType::PASSWORD, $email);

        try {
            $detection = $this->detect($agent, 'taken-email-ak', $email);

            $this->assertSame(IdentifierDetection::STATUS_ACTIVE, $detection->status);
            $this->assertSame([AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK], $detection->methods);
            $this->assertSame([], $detection->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An account built by a provider names NO provider, offers no password, and still has a way in.
     *
     * Both halves matter and both are the surface's to survive. Revealing a
     * password field for an account that has no password enters a flow the backend
     * refuses. Naming the provider is the mirror mistake with a different victim:
     * the answer goes to whoever typed the address, not to its owner, so it hands a
     * stranger the fact that this person signs in with GitHub - and hands it for
     * nothing, since the provider buttons vanished with the first typed character
     * (HIL-419). What is left is `magic_link`, which is not a consolation: the
     * account was found by its VERIFIED address, so the mailed link always reaches
     * whoever owns it.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testOauthAccountNamesNoProviderAndOffersNoPassword(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'oauth-ak');
        $email = $this->uniqueEmail();
        $userId = $this->seedUser($email, IdentityType::MAGIC_LINK, $email);
        $this->seedIdentity(
            $userId,
            IdentityType::OAUTH,
            OAuthProviderPreset::GITHUB->value . ':' . RandomHelper::hex(6),
            OAuthProviderPreset::GITHUB->value,
        );

        try {
            $detection = $this->detect($agent, 'oauth-ak', $email);

            $this->assertSame(IdentifierDetection::STATUS_ACTIVE, $detection->status);
            $this->assertSame([AuthMethodKey::MAGIC_LINK], $detection->methods);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An address held by a registration awaiting its code is `pending`, with neither list.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testHeldEmailIsPendingAndNamesNothing(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'held-ak');
        $email = $this->uniqueEmail();
        $this->register($agent, 'held-ak', $email);

        try {
            $detection = $this->detect($agent, 'held-ak', $email);

            $this->assertSame(IdentifierDetection::STATUS_PENDING, $detection->status);
            $this->assertSame([], $detection->methods);
            $this->assertSame([], $detection->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An address this browser has already proved is `proven`, with neither list.
     *
     * The fourth state (HIL-825), and the reason it is a state and not a flag inside
     * `pending`: `pending` means "go to the code screen" everywhere in the sign-in
     * machine, and this address's code is spent. Neither list is named because the
     * account it will get does not exist yet and there is nothing to name a way into -
     * the surface has one thing to offer, the password screen.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testProvedHoldIsProvenAndNamesNothing(): void
    {
        $agent = $this->bootAgent();
        $token = $this->openSession($agent, 'proven-ak');
        $email = $this->uniqueEmail();
        $this->register($agent, 'proven-ak', $email);
        $this->proveHold($token, $email);

        try {
            $detection = $this->detect($agent, 'proven-ak', $email);

            $this->assertSame(IdentifierDetection::STATUS_PROVEN, $detection->status);
            $this->assertSame([], $detection->methods);
            $this->assertSame([], $detection->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Another browser's PROVED hold still leaves the address free to everyone else.
     *
     * The address belongs to nobody until a password is saved (HIL-825), so proving it
     * moves the race rather than ending it. Answering anything else here would tell a
     * stranger that somebody is one screen away from taking the address - the very
     * oracle the browser-owned hold was built to close.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testAnotherBrowsersProvedHoldStillLeavesTheAddressFree(): void
    {
        $agent = $this->bootAgent();
        $token = $this->openSession($agent, 'proven-holder-ak');
        $email = $this->uniqueEmail();
        $this->register($agent, 'proven-holder-ak', $email);
        $this->proveHold($token, $email);
        $this->openSession($agent, 'proven-onlooker-ak');

        try {
            $detection = $this->detect($agent, 'proven-onlooker-ak', $email);

            $this->assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
            $this->assertSame([AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK], $detection->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Another browser's hold does not take the address: the lookup still reads it free.
     *
     * The oracle this closes (HIL-608): a hold belongs to the browser that made it, so
     * reporting somebody else's would answer "is anyone registering this address right
     * now" to whoever cares to type one. The person asking is simply offered the ways to
     * register it, and races with the other browser for it.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testAnotherBrowsersHoldLeavesTheAddressFree(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'holder-ak');
        $email = $this->uniqueEmail();
        $this->register($agent, 'holder-ak', $email);
        $this->openSession($agent, 'onlooker-ak');

        try {
            $detection = $this->detect($agent, 'onlooker-ak', $email);

            $this->assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
            $this->assertSame([AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK], $detection->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A hold that has run out reads as free again, with no branch of its own.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testExpiredHoldReadsAsFree(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'stale-ak');
        $email = $this->uniqueEmail();
        $this->register($agent, 'stale-ak', $email);
        $this->ageReservationOut($email);

        try {
            $detection = $this->detect($agent, 'stale-ak', $email);

            $this->assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
            $this->assertSame([AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK], $detection->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A number somebody signs in with is `active`, offers the code only, and comes back in E.164.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testPhoneWithAnSmsIdentityIsActiveAndOffersOnlyTheCode(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'phone-ak');
        $phone = $this->uniquePhone();
        $this->seedUser($this->uniqueEmail(), IdentityType::SMS, $phone);

        try {
            $detection = $this->detect($agent, 'phone-ak', $this->typedForm($phone));

            $this->assertSame(IdentifierDetection::STATUS_ACTIVE, $detection->status);
            $this->assertSame(IdentifierDetection::KIND_PHONE, $detection->kind);
            $this->assertSame($phone, $detection->normalized);
            $this->assertSame([AuthMethodKey::SMS], $detection->methods);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A free number is registerable by code, and by nothing an address is registerable by.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testFreePhoneIsRegisterableByCodeOnly(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'free-phone-ak');

        try {
            $detection = $this->detect($agent, 'free-phone-ak', $this->uniquePhone());

            $this->assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
            $this->assertSame([AuthMethodKey::SMS], $detection->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A method the project has not enabled is named nowhere, however true it is.
     *
     * @throws HilosException When setup or the lookup fails
     */
    public function testDisabledMethodIsNamedNowhere(): void
    {
        $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUser($email, IdentityType::PASSWORD, $email);
        $detector = new IdentifierDetector([AuthMethodKey::MAGIC_LINK]);

        try {
            $token = RandomHelper::hex(16);
            $taken = $detector->detect($email, $token);
            $free = $detector->detect($this->uniqueEmail(), $token);

            $this->assertSame([AuthMethodKey::MAGIC_LINK], $taken->methods);
            $this->assertNotContains(AuthMethodKey::PASSWORD, $taken->methods);
            $this->assertSame([AuthMethodKey::MAGIC_LINK], $free->registerable);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A link-only account on an installation that mails nobody has no way in, and is told why.
     *
     * The file transport with no directory is the fallback a checkout with no relay lands
     * on: it writes nothing a guest will read, so offering the mailed link would walk the
     * person to a screen waiting for a letter that is never going to leave.
     *
     * @throws HilosException When setup or the lookup fails
     */
    public function testLinkOnlyAccountWithNoMailHasNoWayInAndSaysWhy(): void
    {
        $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUser($email, IdentityType::MAGIC_LINK, $email);
        $previous = $this->overrideEnv([
            EnvConstants::MAIL_TRANSPORT->name => MailTransportFactory::TRANSPORT_FILE,
            EnvConstants::MAIL_FILE_DIR->name => '',
        ]);

        try {
            $detection = new IdentifierDetector(EnabledAuthMethods::keys())->detect($email, RandomHelper::hex(16));

            $this->assertSame(IdentifierDetection::STATUS_ACTIVE, $detection->status);
            $this->assertSame([], $detection->methods);
            $this->assertSame(IdentifierDetection::BLOCK_NO_CHANNEL, $detection->signInBlock);
            $this->assertNull($detection->registrationBlock);
        } finally {
            $this->restoreEnv($previous);
            $this->cleanUp();
        }
    }

    /**
     * An account WITH a password on the same installation keeps its password and nothing is refused.
     *
     * @throws HilosException When setup or the lookup fails
     */
    public function testPasswordAccountWithNoMailKeepsItsPassword(): void
    {
        $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUser($email, IdentityType::PASSWORD, $email);
        $previous = $this->overrideEnv([
            EnvConstants::MAIL_TRANSPORT->name => MailTransportFactory::TRANSPORT_FILE,
            EnvConstants::MAIL_FILE_DIR->name => '',
        ]);

        try {
            $detection = new IdentifierDetector(EnabledAuthMethods::keys())->detect($email, RandomHelper::hex(16));

            $this->assertSame([AuthMethodKey::PASSWORD], $detection->methods);
            $this->assertNull($detection->signInBlock);
        } finally {
            $this->restoreEnv($previous);
            $this->cleanUp();
        }
    }

    /**
     * A number with no configured channel is offered no code, and is told why.
     *
     * Both phone channels the demo registers are taken away: the SMS provider is pinned to
     * the stub, and the messenger gateway loses its token.
     *
     * @throws HilosException When setup or the lookup fails
     */
    public function testPhoneAccountWithNoChannelHasNoWayInAndSaysWhy(): void
    {
        $this->bootAgent();
        $phone = $this->uniquePhone();
        $this->seedUser($this->uniqueEmail(), IdentityType::SMS, $phone);
        $previous = $this->overrideEnv([
            EnvConstants::SMS_PROVIDER->name => SmsChannelConfig::PROVIDER_STUB,
            EnvConstants::TELEGRAM_GATEWAY_TOKEN->name => '',
        ]);

        try {
            $detection = new IdentifierDetector(EnabledAuthMethods::keys())->detect($phone, RandomHelper::hex(16));

            $this->assertSame(IdentifierDetection::STATUS_ACTIVE, $detection->status);
            $this->assertSame([], $detection->methods);
            $this->assertSame(IdentifierDetection::BLOCK_NO_CHANNEL, $detection->signInBlock);
        } finally {
            $this->restoreEnv($previous);
            $this->cleanUp();
        }
    }

    /**
     * An installation that DECLARED it keeps its letters at home still offers the link (HIL-827).
     *
     * @throws HilosException When setup or the lookup fails
     */
    public function testDeclaredFileMailStillOffersTheLink(): void
    {
        $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUser($email, IdentityType::MAGIC_LINK, $email);
        $previous = $this->overrideEnv([
            EnvConstants::MAIL_TRANSPORT->name => MailTransportFactory::TRANSPORT_FILE,
            EnvConstants::MAIL_FILE_DIR->name => sys_get_temp_dir(),
        ]);

        try {
            $detection = new IdentifierDetector(EnabledAuthMethods::keys())->detect($email, RandomHelper::hex(16));

            $this->assertSame([AuthMethodKey::MAGIC_LINK], $detection->methods);
            $this->assertNull($detection->signInBlock);
        } finally {
            $this->restoreEnv($previous);
            $this->cleanUp();
        }
    }

    /**
     * An input that is neither an address nor a number is refused, not answered `unknown`.
     *
     * @throws HilosException When setup or lookup handling fails
     */
    public function testUnreadableIdentifierIsRefused(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'garbage-ak');

        try {
            $this->expectException(InvalidFormatException::class);

            $this->detect($agent, 'garbage-ak', 'not-an-identifier');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The lookup is anonymous-reachable and throttled — the pair that makes the trade honest.
     *
     * The epic gave up generic answers in exchange for one usable field, and the
     * only thing left standing between this endpoint and an enumerator is the
     * window it is listed in (HIL-420).
     */
    public function testLookupIsPublicAndThrottled(): void
    {
        $this->assertContains(HilosSignalConstants::HILOS_DETECT_IDENTIFIER, UsersLibraryAgent::THROTTLED_ACTIONS);
        $this->assertNotContains(HilosSignalConstants::HILOS_DETECT_IDENTIFIER, UsersLibraryAgent::AUTH_ACTIONS);
        $this->assertArrayHasKey(HilosSignalConstants::HILOS_DETECT_IDENTIFIER, UsersLibraryAgent::AGENT_ACTIONS);
    }

    /**
     * This demo wires the four built-in methods plus every provider it declared, all on by default.
     */
    public function testEnabledSetIsAssembledFromTheWiredProviders(): void
    {
        $this->assertSame([
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
            OAuthProviderPreset::GOOGLE->value,
        ], EnabledAuthMethods::keys());
    }

    /**
     * A method the administrator switched off is named nowhere, and its submit is refused (HIL-427).
     *
     * Through the library, as a surface reaches it: the lookup reads the set anew, so the
     * switch is in force on the very next action, and the password this account has is
     * neither offered nor accepted.
     *
     * @throws HilosException When setup or the lookup fails
     */
    public function testSwitchedOffMethodIsNamedNowhereAndItsSubmitRefused(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'switched-off-ak');
        $email = $this->uniqueEmail();
        $this->seedUser($email, IdentityType::PASSWORD, $email);
        $this->writeSwitchedOff(AuthMethodKey::PASSWORD);

        try {
            $this->assertNotContains(AuthMethodKey::PASSWORD, $this->detect($agent, 'switched-off-ak', $email)->methods);

            try {
                $this->usersLibrary()->onAgentAction(
                    'switched-off-ak',
                    HilosSignalConstants::HILOS_LOGIN,
                    new LoginActionDTO($email, 'whatever-it-is'),
                );
                $this->fail('A login on a switched-off password was let through');
            } catch (ValidationException $e) {
                $this->assertSame(AuthMessages::METHOD_TURNED_OFF, $e->getMessage());
            }
        } finally {
            $this->writeSwitchedOff(null);
            $this->cleanUp();
        }
    }

    /**
     * Registers the truth sources and signal router the lookup path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(StateRegistrationWaiter::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        // The send-progress line is the sessions library's too (HIL-826), and this fixture
        // stands in for it: the wait being let go takes the line with it, and a writer with
        // no claim is refused whether or not there is a row to take.
        RtTruthSourceRegistry::register(StateHilosCodeSendAttempt::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'listener-ak',
            PageConstants::MAIN,
            [],
        ));

        return new ChatAgent();
    }

    /**
     * Opens an anonymous session and connection for an accept key and marks it current.
     *
     * @param ChatAgent $agent Agent under test
     * @param string $acceptKey WebSocket accept key to open the session under
     * @return string The session cookie token registered for the connection
     * @throws HilosException When the handshake fails
     */
    private function openSession(ChatAgent $agent, string $acceptKey): string
    {
        $token = RandomHelper::hex(16);
        $this->deliverHandshake($agent, new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        ));
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
    }

    /**
     * Dispatches a lookup through the main page and returns what the surface is answered with.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $identifier Submitted identifier
     * @return IdentifierDetection The detection the surface reveals itself from
     * @throws HilosException When the lookup handler rejects the action
     */
    private function detect(ChatAgent $agent, string $acceptKey, string $identifier): IdentifierDetection
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER,
            DetectIdentifierActionDTO::fromArray(['identifier' => $identifier]),
        );
        $this->deliverLibraryFrames($agent);
        $this->assertInstanceOf(IdentifierDetection::class, $reply);

        return $reply;
    }

    /**
     * Reserves an address through the register action so a real hold exists.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Address to hold
     * @throws HilosException When the register handler rejects the action
     */
    private function register(ChatAgent $agent, string $acceptKey, string $email): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REGISTER,
            new RegisterActionDTO($email),
        );
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Marks this browser's hold on an address as proved, the way an accepted code does.
     *
     * The service rather than the confirm action, because the mailed code is unknowable
     * here and re-seeding a challenge would test the verification layer instead of the
     * detection this case is about.
     *
     * @param string $sessionToken Session cookie token of the browser leading the registration
     * @param string $email Address the hold names
     * @throws HilosException When the reservation write fails
     */
    private function proveHold(string $sessionToken, string $email): void
    {
        new RegistrationReservationService()->markProven($sessionToken, $email);
    }

    /**
     * Creates a user carrying one verified identity.
     *
     * @param string $displayNameSource Value the display name is derived from
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     * @return int New user id
     * @throws HilosException When user creation or the identity insert fails
     */
    private function seedUser(string $displayNameSource, string $type, string $identifier): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName($displayNameSource)->id;
        $this->seedIdentity($userId, $type, $identifier, null);

        return $userId;
    }

    /**
     * Inserts one verified identity row for a user.
     *
     * @param int $userId Owning user id
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     * @param ?string $provider Provider key for an oauth identity, or null for the other types
     * @throws HilosException When the insert query fails
     */
    private function seedIdentity(int $userId, string $type, string $identifier, ?string $provider): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string($type));
        $params->add(SqlParam::string($identifier));
        $params->add(SqlParam::auto($provider));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, '
            . '`' . EntityIdentity::identifier . '`, `' . EntityIdentity::provider . '`, '
            . '`' . EntityIdentity::verified . '`) VALUES (?, ?, ?, ?, 1)',
            $params,
        );
    }

    /**
     * Sets process environment variables and returns what they held before.
     *
     * @param array<string, string> $values Variable name to the value this case needs
     * @return array<string, string|false> Variable name to its previous value, false when it was unset
     */
    private function overrideEnv(array $values): array
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        return $previous;
    }

    /**
     * Puts back the process environment {@see self::overrideEnv()} changed.
     *
     * @param array<string, string|false> $previous Variable name to its previous value, false when it was unset
     */
    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /**
     * Ages a hold into the past so the lookup reads it as gone.
     *
     * @param string $email Held address
     * @throws HilosException When the update query fails
     */
    private function ageReservationOut(string $email): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(date('Y-m-d H:i:s', time() - 1)));
        $params->add(SqlParam::string($email));
        Database::sql(
            'UPDATE `' . EntityRegistrationReservation::_table . '` SET `'
            . EntityRegistrationReservation::expires_at . '` = ? WHERE `'
            . EntityRegistrationReservation::identifier . '` = ?',
            $params,
        );
        $this->reservations()->clearInMemory();
    }

    /**
     * @return ObjectRegistrationReservations Reservation persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function reservations(): ObjectRegistrationReservations
    {
        /** @var ObjectRegistrationReservations $collection */
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::registrationReservations);

        return $collection;
    }

    /**
     * Stores the switched-off method set, or takes the stored one away, under a writer of its own.
     *
     * The settings belong to their library in a running daemon; here a holder of the
     * fixture's own writes them, and the agent under test is current again afterwards.
     *
     * @param ?string $disabled Stored value, or null to delete the row
     * @throws HilosException When the settings write fails
     */
    private function writeSwitchedOff(?string $disabled): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::SETTINGS_AGENT_ID);

        try {
            Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->actions->delete();
            if ($disabled !== null) {
                Hilos::$db->settings->actions->add(AuthMethodSettings::DISABLED_KEY, $disabled, Hilos::$setting->catalog());
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
            ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        }
    }

    /**
     * Releases whatever the register action parked and clears the connections.
     *
     * @throws HilosException When releasing a waiter fails
     */
    private function cleanUp(): void
    {
        $parked = [];
        foreach (Hilos::$rt->hilosRegistrationWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }
        foreach ($parked as $acceptKey) {
            Hilos::$rt->hilosRegistrationWaiters->actions->release($acceptKey);
        }
        Hilos::$rt->connections->actions->clear();
    }

    /**
     * Builds a unique lowercase email for one test.
     *
     * @return string Unique email identifier
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }

    /**
     * Builds a unique E.164 number for one test.
     *
     * @return string Unique phone identifier
     */
    private function uniquePhone(): string
    {
        return '+1999' . str_pad((string)RandomHelper::integer(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    /**
     * Renders an E.164 number the way somebody types it, separators and all.
     *
     * @param string $normalized Canonical E.164 number
     * @return string The same number with the cosmetic separators a person adds
     */
    private function typedForm(string $normalized): string
    {
        $digits = substr($normalized, 1);

        return '+' . $digits[0] . ' (' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7);
    }
}
