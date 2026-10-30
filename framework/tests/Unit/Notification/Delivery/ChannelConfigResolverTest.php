<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\ChannelConfigResolver;
use Hilos\Notification\Delivery\ChannelConfigSource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the channel config resolver source precedence and secret masking (HIL-200).
 *
 * Covers the DB-free layers: an env value (or its env-catalog default) resolves as
 * {@see ChannelConfigSource::ENV}, a field with no env backing falls to
 * {@see ChannelConfigSource::DEFAULT}, and a secret is never resolved to a value -
 * only its set/not-set state is reported. The settings-override layer needs a DB
 * context and is exercised by the admin e2e scenario (HIL-202).
 */
final class ChannelConfigResolverTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?HilosDbContext $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        // No DB context: the resolver sees no persisted settings override.
        Hilos::$db = null;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        foreach ([EnvConstants::MAIL_FROM_ADDRESS, EnvConstants::MAIL_SMTP_PORT, EnvConstants::MAIL_SMTP_PASSWORD] as $key) {
            putenv($key->name);
        }
        parent::tearDown();
    }

    public function testEnvSourceForStringField(): void
    {
        putenv(EnvConstants::MAIL_FROM_ADDRESS->name . '=noreply@hilos.test');

        $field = new ChannelConfigField(
            MailDeliveryChannel::FIELD_FROM_ADDRESS,
            'From address',
            SettingsCatalogConstants::TYPE_STRING,
            false,
            EnvConstants::MAIL_FROM_ADDRESS,
        );
        $resolved = new ChannelConfigResolver()->resolve(MailDeliveryChannel::NAME, $field);

        self::assertSame(MailDeliveryChannel::FIELD_FROM_ADDRESS, $resolved->field);
        self::assertSame(ChannelConfigSource::ENV, $resolved->source);
        self::assertSame('noreply@hilos.test', $resolved->value);
    }

    public function testEnvSourceFallsBackToEnvCatalogDefaultForInt(): void
    {
        $field = new ChannelConfigField(
            MailDeliveryChannel::FIELD_SMTP_PORT,
            'SMTP port',
            SettingsCatalogConstants::TYPE_INTEGER,
            false,
            EnvConstants::MAIL_SMTP_PORT,
            587,
        );
        $resolved = new ChannelConfigResolver()->resolve(MailDeliveryChannel::NAME, $field);

        self::assertSame(ChannelConfigSource::ENV, $resolved->source);
        self::assertSame(587, $resolved->value);
    }

    public function testDefaultSourceWhenFieldHasNoEnvBacking(): void
    {
        $field = new ChannelConfigField('note', 'Note', SettingsCatalogConstants::TYPE_STRING, false, null, 'n/a');
        $resolved = new ChannelConfigResolver()->resolve(MailDeliveryChannel::NAME, $field);

        self::assertSame(ChannelConfigSource::DEFAULT, $resolved->source);
        self::assertSame('n/a', $resolved->value);
    }

    public function testSecretSetReportsEnvSourceAndNeverExposesValue(): void
    {
        putenv(EnvConstants::MAIL_SMTP_PASSWORD->name . '=s3cret');

        $field = new ChannelConfigField(
            MailDeliveryChannel::FIELD_SMTP_PASSWORD,
            'SMTP password',
            SettingsCatalogConstants::TYPE_STRING,
            true,
            EnvConstants::MAIL_SMTP_PASSWORD,
        );
        $resolved = new ChannelConfigResolver()->resolve(MailDeliveryChannel::NAME, $field);

        self::assertSame(ChannelConfigSource::ENV, $resolved->source);
        self::assertNull($resolved->value);
    }

    public function testSecretUnsetReportsDefaultSourceAndNullValue(): void
    {
        putenv(EnvConstants::MAIL_SMTP_PASSWORD->name);

        $field = new ChannelConfigField(
            MailDeliveryChannel::FIELD_SMTP_PASSWORD,
            'SMTP password',
            SettingsCatalogConstants::TYPE_STRING,
            true,
            EnvConstants::MAIL_SMTP_PASSWORD,
        );
        $resolved = new ChannelConfigResolver()->resolve(MailDeliveryChannel::NAME, $field);

        self::assertSame(ChannelConfigSource::DEFAULT, $resolved->source);
        self::assertNull($resolved->value);
    }

    /** The catalog's port wins even when the descriptor declares another port. */
    public function testDefaultReaderUsesEnvCatalogDefaultInsteadOfDescriptorDefault(): void
    {
        putenv(EnvConstants::MAIL_SMTP_PORT->name);
        $field = new ChannelConfigField('port', 'Port', SettingsCatalogConstants::TYPE_INTEGER, false, EnvConstants::MAIL_SMTP_PORT, 25);

        $resolved = new ChannelConfigResolver()->resolveDefault($field);

        self::assertSame('port', $resolved->field);
        self::assertSame(ChannelConfigSource::ENV, $resolved->source);
        self::assertSame(587, $resolved->value);
    }

    /** Empty strings, zero and false are values rather than absent env layers. */
    public function testDefaultReaderKeepsFalsyEnvValuesTyped(): void
    {
        $cases = [
            [SettingsCatalogConstants::TYPE_STRING, EnvConstants::MAIL_FROM_ADDRESS, '', '', 'descriptor'],
            [SettingsCatalogConstants::TYPE_INTEGER, EnvConstants::MAIL_SMTP_PORT, '0', 0, 25],
            [SettingsCatalogConstants::TYPE_BOOLEAN, EnvConstants::BACKUP_ENABLED, 'false', false, true],
            [SettingsCatalogConstants::TYPE_FLOAT, EnvConstants::HILOS_MODERATION_TEXT_TIMEOUT_SEC, '0', 0.0, 1.0],
        ];
        try {
            foreach ($cases as [$type, $envKey, $envValue, $expected, $default]) {
                putenv($envKey->name . '=' . $envValue);
                Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
                $field = new ChannelConfigField('typed', 'Typed', $type, false, $envKey, $default);

                $resolved = new ChannelConfigResolver()->resolveDefault($field);

                self::assertSame(ChannelConfigSource::ENV, $resolved->source);
                self::assertSame($expected, $resolved->value);
            }
        } finally {
            foreach ($cases as [, $envKey]) {
                putenv($envKey->name);
            }
        }
    }

    /** Either missing env binding makes the descriptor the winning layer. */
    public function testDefaultReaderUsesDescriptorWithoutEnvBackingOrFacade(): void
    {
        $resolver = new ChannelConfigResolver();
        foreach ([null, EnvConstants::MAIL_SMTP_PORT] as $envKey) {
            if ($envKey !== null) {
                Hilos::$env = null;
            }
            $field = new ChannelConfigField('port', 'Port', SettingsCatalogConstants::TYPE_INTEGER, false, $envKey, 25);

            $resolved = $resolver->resolveDefault($field);

            self::assertSame(ChannelConfigSource::DEFAULT, $resolved->source);
            self::assertSame(25, $resolved->value);
        }
    }

    /** A reused resolver reports current secret state without exposing its value. */
    public function testDefaultReaderMasksSecretAndRechecksEnvOnEveryRead(): void
    {
        $resolver = new ChannelConfigResolver();
        $field = new ChannelConfigField(
            'password',
            'Password',
            SettingsCatalogConstants::TYPE_STRING,
            true,
            EnvConstants::MAIL_SMTP_PASSWORD,
        );
        foreach (['secret' => ChannelConfigSource::ENV, '' => ChannelConfigSource::DEFAULT] as $value => $source) {
            putenv(EnvConstants::MAIL_SMTP_PASSWORD->name . '=' . $value);
            Hilos::$env = new EnvAccessor(EnvCatalogStub::class);

            $resolved = $resolver->resolveDefault($field);

            self::assertSame($source, $resolved->source);
            self::assertNull($resolved->value);
        }

        Hilos::$env = null;
        self::assertSame(ChannelConfigSource::DEFAULT, $resolver->resolveDefault($field)->source);
        self::assertNull($resolver->resolveDefault($field)->value);
    }
}
