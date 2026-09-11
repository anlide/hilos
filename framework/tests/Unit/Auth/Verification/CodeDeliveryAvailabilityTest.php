<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Verification\CodeDeliveryAvailability;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what an installation can deliver a one-time code to (HIL-830).
 *
 * The two halves are asked separately because they answer for different kinds and
 * fail differently: the mail half is a transport selection read out of env, and the
 * phone half is a walk over the project's registered code channels. A deployment with
 * one and not the other is ordinary, so every case here pins ONE kind and leaves the
 * other alone.
 *
 * The registry is reached through the captured project facade, which is what a real
 * installation does, so a fixture facade is mounted and unmounted around each case —
 * the suite runs in one process and that capture is global.
 */
final class CodeDeliveryAvailabilityTest extends TestCase
{
    /** A relay host that resolves nowhere: nothing here sends, only selects. */
    private const string SMTP_HOST = 'relay.example.invalid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetInstallation();
    }

    protected function tearDown(): void
    {
        $this->forgetInstallation();

        parent::tearDown();
    }

    /**
     * The file transport writes a .eml nobody signing in will ever open, so it delivers nothing.
     */
    public function testTheFileTransportIsNotAWayToDeliverToAnAddress(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=file');
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=' . self::SMTP_HOST);

        // The host is set on purpose: an explicit `file` selection wins over it, and
        // that is the deployment this leaf exists for - a relay configured and the
        // driver still pinned to the dev fallback.
        self::assertFalse(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_EMAIL));
    }

    /**
     * The DECLARED test mode is the one file-transport installation an address can reach.
     *
     * Written out by hand with somewhere to put the letters, it says "mail nobody, and I
     * meant it" (HIL-827): the code screen reports the letter as written rather than sent
     * and the person reads the digits off the artifact, so withdrawing registration here
     * would close the only flow the mode exists for.
     */
    public function testTheDeclaredTestModeStillReachesAnAddress(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=file');
        putenv(EnvConstants::MAIL_FILE_DIR->name . '=/tmp/hilos-mail');

        self::assertTrue(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_EMAIL));
    }

    /**
     * With no forced driver and no relay host the framework auto-picks the file transport.
     */
    public function testNoRelayHostMeansNothingToMailWith(): void
    {
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=');

        self::assertFalse(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_EMAIL));
    }

    /**
     * A configured relay is a way to deliver, which is what every stand pins.
     */
    public function testARelayMeansAnAddressCanBeReached(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=smtp');
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=' . self::SMTP_HOST);

        self::assertTrue(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_EMAIL));
    }

    /**
     * A project that registered no code channel has nothing to send a phone code with.
     */
    public function testAnEmptyRegistryCannotReachAPhone(): void
    {
        self::assertFalse(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE));
    }

    /**
     * A registered channel that says it is unconfigured does not count as a way to deliver.
     */
    public function testAnUnconfiguredChannelDoesNotCount(): void
    {
        DeliveryAvailabilityTestHilos::initBrowser();
        DeliveryAvailabilityTestRegistry::hold(new DeliveryAvailabilityTestChannel(configured: false));

        self::assertFalse(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE));
    }

    /**
     * One configured channel serving the registration type is the whole of what is needed.
     */
    public function testOneConfiguredChannelReachesAPhone(): void
    {
        DeliveryAvailabilityTestHilos::initBrowser();
        DeliveryAvailabilityTestRegistry::hold(new DeliveryAvailabilityTestChannel(configured: true));

        self::assertTrue(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE));
    }

    /**
     * A configured channel that does not serve a phone login is not this flow's channel.
     */
    public function testAChannelThatServesAnotherFlowDoesNotCount(): void
    {
        DeliveryAvailabilityTestHilos::initBrowser();
        DeliveryAvailabilityTestRegistry::hold(
            new DeliveryAvailabilityTestChannel(configured: true, servesRegistration: false),
        );

        self::assertFalse(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE));
    }

    /**
     * A configured channel addressing something other than a number is not a phone channel.
     */
    public function testAChannelThatDoesNotAddressNumbersDoesNotCount(): void
    {
        DeliveryAvailabilityTestHilos::initBrowser();
        DeliveryAvailabilityTestRegistry::hold(
            new DeliveryAvailabilityTestChannel(configured: true, addressesPhones: false),
        );

        self::assertFalse(new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE));
    }

    /**
     * The wire form names both kinds, and the two answers are independent of each other.
     */
    public function testTheWireFormAnswersBothKindsSeparately(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=smtp');
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=' . self::SMTP_HOST);

        self::assertSame(['email' => true, 'phone' => false], new CodeDeliveryAvailability()->toArray());
    }

    /**
     * Returns the process to an installation that declared nothing about either kind.
     *
     * Both halves are global state the suite shares - the captured project facade and
     * the mail knobs in the process environment - so a case that read a neighbor's
     * leftovers would pass or fail for a reason nobody wrote down.
     */
    private function forgetInstallation(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name);
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        putenv(EnvConstants::MAIL_FILE_DIR->name);
        DeliveryAvailabilityTestRegistry::hold(null);
        Hilos::initBrowser();
        Hilos::resetBrowser();
    }
}

/**
 * Project facade fixture pointing the code-channel registry at the one below.
 */
final class DeliveryAvailabilityTestHilos extends Hilos
{
    protected const string CODE_CHANNEL_REGISTRY = DeliveryAvailabilityTestRegistry::class;

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new DeliveryAvailabilityTestDbContext();
    }
}

/**
 * No-op DB context so the facade fixture is instantiable.
 */
final class DeliveryAvailabilityTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the availability fixture.
     */
    public function configure(): void
    {
    }
}

/**
 * Registry whose single channel each case sets, since a registry is chosen by class.
 */
final class DeliveryAvailabilityTestRegistry extends CodeChannelRegistry
{
    /** The channel this registry answers with, or null for an empty registry. */
    private static ?CodeChannel $channel = null;

    /**
     * Sets the channel every later lookup answers with.
     *
     * @param ?CodeChannel $channel Channel to register, or null to empty the registry
     */
    public static function hold(?CodeChannel $channel): void
    {
        self::$channel = $channel;
    }

    /**
     * @return array<string, CodeChannel> The held channel keyed by its name, or nothing
     */
    protected static function channels(): array
    {
        return self::$channel === null ? [] : [self::$channel->name() => self::$channel];
    }
}

/**
 * A channel whose three answers are set per case, so one case moves one of them.
 */
final class DeliveryAvailabilityTestChannel extends CodeChannel
{
    /**
     * @param bool $configured What {@see isConfigured()} answers
     * @param bool $servesRegistration Whether the channel serves the phone-login verification type
     * @param bool $addressesPhones Whether the channel addresses phone numbers at all
     */
    public function __construct(
        private readonly bool $configured,
        private readonly bool $servesRegistration = true,
        private readonly bool $addressesPhones = true,
    ) {
    }

    /**
     * @return string A fixed name: the registry keys by it and no case has two channels
     */
    public function name(): string
    {
        return 'availability-fixture';
    }

    /**
     * @return bool Whatever this fixture was built to answer
     */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * @param string $type Verification type (see VerificationType)
     * @return bool True for the phone verification types, unless this fixture serves another flow
     */
    public function supportsType(string $type): bool
    {
        return $this->servesRegistration && VerificationType::isSms($type);
    }

    /**
     * @return list<string> The phone kind, or an email-only channel that a phone lookup must skip
     */
    public function identifierKinds(): array
    {
        return $this->addressesPhones ? [IdentifierDetection::KIND_PHONE] : [IdentifierDetection::KIND_EMAIL];
    }
}
