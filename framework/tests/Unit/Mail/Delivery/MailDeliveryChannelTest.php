<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        putenv('MAIL_WORKER_COUNT');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        putenv('MAIL_WORKER_COUNT');
        parent::tearDown();
    }

    public function testChannelIdentityIsEmailAndPooled(): void
    {
        $channel = new MailDeliveryChannel();

        self::assertSame('email', $channel->name());
        self::assertSame(HilosSignalConstants::HILOS_MAIL_DELIVER, $channel->deliverSignalName());
        self::assertTrue($channel->isPooled());
        self::assertSame(NotificationDeliverSignalData::shardKey, $channel->indexField());
        self::assertSame('notifications.channel.email.enabled', $channel->enabledSettingKey());
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
