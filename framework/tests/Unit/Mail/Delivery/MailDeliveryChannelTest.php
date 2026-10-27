<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Push\Delivery\PushDeliveryChannel;
use PHPUnit\Framework\TestCase;

/**
 * Tests the email channel descriptor: identity, address resolution, and shard co-location (HIL-197).
 *
 * The channel names the `email` channel and points at HILOS_MAIL_DELIVER; it is pooled
 * and shards its delivery intake by the recipient address through
 * {@see HilosMailer::shardKeyForAddress}, so a delivery lands on the same pool instance
 * as a raw send to the same address. Without a DB context the address is unresolved and
 * no shard is assigned.
 */
final class MailDeliveryChannelTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?HilosDbContext $previousDb = null;
    private ?SettingsAccessor $previousSetting = null;
    private string $previousApp;

    /** Saves process-local facade state for each descriptor test. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousApp = Hilos::appClass();
        putenv('MAIL_WORKER_COUNT');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    /** Restores facade state and releases the test registry's mocks. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        $this->previousApp::initBrowser();
        Hilos::resetBrowser();
        MailPreferenceTestChannelRegistry::$descriptors = [];
        putenv('MAIL_WORKER_COUNT');
        parent::tearDown();
    }

    /** Checks the mail descriptor's identity and DB-free icon metadata. */
    public function testChannelIdentityIsEmailAndPooled(): void
    {
        $channel = new MailDeliveryChannel();

        self::assertSame('email', $channel->name());
        self::assertSame('bi-envelope', $channel->icon());
        self::assertSame(HilosSignalConstants::HILOS_MAIL_DELIVER, $channel->deliverSignalName());
        self::assertTrue($channel->isPooled());
        self::assertSame(NotificationDeliverSignalData::shardKey, $channel->indexField());
        self::assertSame('notifications.channel.email.enabled', $channel->enabledSettingKey());
    }

    /** Checks literal address publication, including an unresolved recipient. */
    public function testPublicAddressUsesTheResolvedDeliveryEmailLiterally(): void
    {
        $channel = new MailDeliveryChannel();

        self::assertNull($channel->displayAddress(null));
        self::assertSame('User@Example.test', $channel->displayAddress('User@Example.test'));
    }

    /** Checks the full section boundary with one address lookup per enabled channel. */
    public function testSectionProjectsTheProjectMailAddressOnceAndKeepsOtherChannelMetadataPrivate(): void
    {
        Hilos::$db = null;
        Hilos::$setting = new SettingsAccessor(MailPreferenceTestSettingsCatalog::class);
        MailPreferenceTestHilos::initBrowser();

        $mail = $this->createPartialMock(MailDeliveryChannel::class, ['resolveAddress']);
        $mail->expects(self::once())->method('resolveAddress')->with(7)->willReturn('project@example.test');
        $push = $this->createPartialMock(PushDeliveryChannel::class, ['resolveAddress', 'frontendSubscribeConfig']);
        $push->expects(self::once())->method('resolveAddress')->with(7)->willReturn('2');
        $push->method('frontendSubscribeConfig')->willReturn(['vapid_public' => 'BPk...']);
        $sms = $this->getMockBuilder(AbstractDeliveryChannel::class)
            ->onlyMethods(['name', 'deliverSignalName', 'resolveAddress'])->getMock();
        $sms->method('name')->willReturn('sms');
        $sms->expects(self::once())->method('resolveAddress')->with(7)->willReturn(null);
        $disabled = $this->getMockBuilder(AbstractDeliveryChannel::class)
            ->onlyMethods(['name', 'deliverSignalName', 'resolveAddress'])->getMock();
        $disabled->method('name')->willReturn('disabled');
        $disabled->expects(self::never())->method('resolveAddress');
        MailPreferenceTestChannelRegistry::$descriptors = [
            'push' => $push,
            'disabled' => $disabled,
            'email' => $mail,
            'sms' => $sms,
        ];

        self::assertSame([
            'channels' => [
                [
                    'channel' => 'push',
                    'label' => 'Push',
                    'allowed' => true,
                    'hasAddress' => true,
                    'address' => null,
                    'icon' => 'bi-broadcast',
                    'config' => ['vapid_public' => 'BPk...'],
                ],
                [
                    'channel' => 'email',
                    'label' => 'Email',
                    'allowed' => true,
                    'hasAddress' => true,
                    'address' => 'project@example.test',
                    'icon' => 'bi-envelope',
                ],
                [
                    'channel' => 'sms',
                    'label' => 'Sms',
                    'allowed' => true,
                    'hasAddress' => false,
                    'address' => null,
                    'icon' => 'bi-bell',
                ],
            ],
            'mandatoryNote' => true,
        ], new NotificationChannelPreferenceProjector()->sectionData(7)->toArray());
    }

    public function testAddressAndShardAreNullWithoutDbContext(): void
    {
        Hilos::$db = null;
        $channel = new MailDeliveryChannel();

        self::assertNull($channel->resolveAddress(7));
        self::assertNull($channel->shardKeyFor(7, 42));
    }

    public function testShardKeyMatchesTheRawSendRuleForTheResolvedAddress(): void
    {
        putenv('MAIL_WORKER_COUNT=8');
        $channel = new class extends MailDeliveryChannel {
            public function resolveAddress(int $userId): ?string
            {
                return 'User@Example.com';
            }
        };

        $shard = $channel->shardKeyFor(7, 42);

        self::assertSame(HilosMailer::shardKeyForAddress('User@Example.com'), $shard);
        self::assertGreaterThanOrEqual(1, $shard);
        self::assertLessThanOrEqual(8, $shard);
    }

    public function testConfigFieldsDeclareOperationalFieldsAndEnvOnlySecrets(): void
    {
        $fields = [];
        foreach (new MailDeliveryChannel()->configFields() as $field) {
            $fields[$field->key] = $field;
        }

        self::assertSame(
            [
                MailDeliveryChannel::FIELD_FROM_ADDRESS,
                MailDeliveryChannel::FIELD_FROM_NAME,
                MailDeliveryChannel::FIELD_SMTP_HOST,
                MailDeliveryChannel::FIELD_SMTP_PORT,
                MailDeliveryChannel::FIELD_SMTP_SECURITY,
                MailDeliveryChannel::FIELD_TIMEOUT_MS,
                MailDeliveryChannel::FIELD_SMTP_USERNAME,
                MailDeliveryChannel::FIELD_SMTP_PASSWORD,
            ],
            array_keys($fields),
        );

        self::assertFalse($fields[MailDeliveryChannel::FIELD_SMTP_PORT]->secret);
        self::assertSame(587, $fields[MailDeliveryChannel::FIELD_SMTP_PORT]->default);
        self::assertSame(EnvConstants::MAIL_SMTP_PORT, $fields[MailDeliveryChannel::FIELD_SMTP_PORT]->envKey);

        self::assertTrue($fields[MailDeliveryChannel::FIELD_SMTP_PASSWORD]->secret);
        self::assertTrue($fields[MailDeliveryChannel::FIELD_SMTP_USERNAME]->secret);
    }

    public function testOperationalFieldsCarryValidatorsThatEnforceTheirRules(): void
    {
        $fields = [];
        foreach (new MailDeliveryChannel()->configFields() as $field) {
            $fields[$field->key] = $field;
        }

        $port = $fields[MailDeliveryChannel::FIELD_SMTP_PORT]->validator;
        self::assertNotNull($port);
        self::assertNull($port(587));
        self::assertNotNull($port(70000));

        $security = $fields[MailDeliveryChannel::FIELD_SMTP_SECURITY]->validator;
        self::assertNotNull($security);
        self::assertNull($security('starttls'));
        self::assertNotNull($security('ssl'));

        $from = $fields[MailDeliveryChannel::FIELD_FROM_ADDRESS]->validator;
        self::assertNotNull($from);
        self::assertNull($from('noreply@example.com'));
        self::assertNotNull($from('nope'));

        self::assertNull($fields[MailDeliveryChannel::FIELD_FROM_NAME]->validator);
    }
}

/** Test facade whose channel registry carries the projection's descriptor mocks. */
abstract class MailPreferenceTestHilos extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = MailPreferenceTestChannelRegistry::class;
}

/** Registry boundary holding mocks only for the duration of one unit test. */
final class MailPreferenceTestChannelRegistry extends DeliveryChannelRegistry
{
    /** @var array<string, AbstractDeliveryChannel> Test-local channel descriptors */
    public static array $descriptors = [];

    /** @return array<string, AbstractDeliveryChannel> Descriptors in their declared order */
    protected static function channels(): array
    {
        return self::$descriptors;
    }
}

/** DB-free global enablement for the projection's fixture channels. */
final class MailPreferenceTestSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Channel enablement defaults */
    public static function getCatalog(): array
    {
        return [
            DeliveryChannelSettings::enabledKey('email') => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
            ],
            DeliveryChannelSettings::enabledKey('push') => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
            ],
            DeliveryChannelSettings::enabledKey('sms') => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
            ],
            DeliveryChannelSettings::enabledKey('disabled') => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => false,
            ],
        ];
    }
}
