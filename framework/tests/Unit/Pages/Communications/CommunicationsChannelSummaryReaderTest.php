<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingValue;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Item\Setting;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Pages\Communications\CommunicationsChannelSummaryReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real resolver and typed value readers over in-memory settings rows; no database connection. */
final class CommunicationsChannelSummaryReaderTest extends TestCase
{
    private ?HilosDbContext $previousDb = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?EnvAccessor $previousEnv = null;

    /** @var array<string, string|false> Original process environment values */
    private array $previousEnvValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousEnv = Hilos::$env;
        foreach ([EnvConstants::MAIL_FROM_ADDRESS, EnvConstants::MAIL_SMTP_PASSWORD] as $key) {
            $this->previousEnvValues[$key->name] = getenv($key->name);
            putenv($key->name);
        }
        Hilos::$db = null;
        Hilos::$env = null;
        $this->settings([]);
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        Hilos::$env = $this->previousEnv;
        foreach ($this->previousEnvValues as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        parent::tearDown();
    }

    public function testEveryDefaultCountsAsMissingEvenWhenItsValueIsNonempty(): void
    {
        $summary = new CommunicationsChannelSummaryReader()->read($this->descriptor([
            new ChannelConfigField('host', 'Host', SettingsCatalogConstants::TYPE_STRING, false, null, 'localhost'),
            new ChannelConfigField('secret', 'Secret', SettingsCatalogConstants::TYPE_STRING, true, null, 'never-serialize-this'),
        ]));

        self::assertSame([
            'channel' => 'custom', 'label' => 'Custom', 'enabled' => false, 'configured' => false,
            'driver' => 'transport', 'missingFields' => 2, 'fieldCount' => 2, 'secretFieldCount' => 1, 'overriddenFieldCount' => 0,
        ], $summary->toArray());
        self::assertStringNotContainsString('never-serialize-this', $summary->toJson());
        self::assertStringNotContainsString('localhost', $summary->toJson());
    }

    public function testMixedDefaultEnvAndSettingsSourcesKeepTheirExistingMeaning(): void
    {
        putenv(EnvConstants::MAIL_FROM_ADDRESS->name . '=');
        putenv(EnvConstants::MAIL_SMTP_PASSWORD->name . '=secret-from-env');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        $this->settings([
            DeliveryChannelSettings::enabledKey('custom') => '1',
            DeliveryChannelSettings::fieldKey('custom', 'overridden') => '',
            DeliveryChannelSettings::fieldKey('custom', 'cleared') => null,
        ]);

        $summary = new CommunicationsChannelSummaryReader()->read($this->descriptor([
            new ChannelConfigField('env', 'Env', SettingsCatalogConstants::TYPE_STRING, false, EnvConstants::MAIL_FROM_ADDRESS),
            new ChannelConfigField('overridden', 'Override', SettingsCatalogConstants::TYPE_STRING, false, EnvConstants::MAIL_FROM_ADDRESS),
            new ChannelConfigField('cleared', 'Cleared', SettingsCatalogConstants::TYPE_STRING, false, null, 'fallback'),
            new ChannelConfigField('secret', 'Secret', SettingsCatalogConstants::TYPE_STRING, true, EnvConstants::MAIL_SMTP_PASSWORD),
        ]));

        self::assertTrue($summary->enabled);
        self::assertFalse($summary->configured);
        self::assertSame(1, $summary->missingFields);
        self::assertSame(4, $summary->fieldCount);
        self::assertSame(1, $summary->secretFieldCount);
        self::assertSame(1, $summary->overriddenFieldCount);
        self::assertStringNotContainsString('secret-from-env', $summary->toJson());
        self::assertStringNotContainsString('fallback', $summary->toJson());
    }

    public function testChannelWithNoFieldsIsConfiguredAndEnablementIsNotAField(): void
    {
        $this->settings([DeliveryChannelSettings::enabledKey('custom') => '1']);

        $summary = new CommunicationsChannelSummaryReader()->read($this->descriptor([]));

        self::assertTrue($summary->enabled);
        self::assertTrue($summary->configured);
        self::assertSame(0, $summary->missingFields);
        self::assertSame(0, $summary->fieldCount);
        self::assertSame(0, $summary->secretFieldCount);
        self::assertSame(0, $summary->overriddenFieldCount);
    }

    /**
     * @param string $secret Env secret value
     * @param int $missing Expected missing count
     */
    #[DataProvider('secretValues')]
    public function testSecretsContributeOnlyTheirSourceAndCount(string $secret, int $missing): void
    {
        putenv(EnvConstants::MAIL_SMTP_PASSWORD->name . '=' . $secret);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);

        $summary = new CommunicationsChannelSummaryReader()->read($this->descriptor([
            new ChannelConfigField('secret', 'Secret', SettingsCatalogConstants::TYPE_STRING, true, EnvConstants::MAIL_SMTP_PASSWORD),
        ]));

        self::assertSame($missing, $summary->missingFields);
        self::assertSame($missing === 0, $summary->configured);
        self::assertSame(1, $summary->fieldCount);
        self::assertSame(1, $summary->secretFieldCount);
        self::assertSame(0, $summary->overriddenFieldCount);
        self::assertArrayNotHasKey('value', $summary->toArray());
        if ($secret !== '') {
            self::assertStringNotContainsString($secret, $summary->toJson());
        }
    }

    /** @return iterable<string, array{string, int}> Unset and set secrets */
    public static function secretValues(): iterable
    {
        yield 'unset' => ['', 1];
        yield 'set' => ['sensitive-test-secret', 0];
    }

    public function testEmptyDescriptorLabelAndDriverArePreserved(): void
    {
        $summary = new CommunicationsChannelSummaryReader()->read($this->descriptor([], '', ''));

        self::assertSame('', $summary->label);
        self::assertSame('', $summary->driver);
    }

    public function testReusedReaderReadsCurrentSettingsWithoutASummaryCache(): void
    {
        $reader = new CommunicationsChannelSummaryReader();
        $descriptor = $this->descriptor([new ChannelConfigField('host', 'Host', SettingsCatalogConstants::TYPE_STRING)]);
        $before = $reader->read($descriptor);
        $this->settings([
            DeliveryChannelSettings::enabledKey('custom') => '1',
            DeliveryChannelSettings::fieldKey('custom', 'host') => 'new-host',
        ]);

        $after = $reader->read($descriptor);

        self::assertFalse($before->enabled);
        self::assertSame(1, $before->missingFields);
        self::assertTrue($after->enabled);
        self::assertTrue($after->configured);
        self::assertSame(0, $after->missingFields);
        self::assertSame(1, $after->overriddenFieldCount);
    }

    /**
     * @param list<ChannelConfigField> $fields Descriptor fields
     * @param string $label Descriptor label
     * @param string $driver Descriptor driver
     * @return AbstractDeliveryChannel Typed descriptor fixture
     */
    private function descriptor(array $fields, string $label = 'Custom', string $driver = 'transport'): AbstractDeliveryChannel
    {
        $descriptor = $this->createStub(AbstractDeliveryChannel::class);
        $descriptor->method('name')->willReturn('custom');
        $descriptor->method('label')->willReturn($label);
        $descriptor->method('driver')->willReturn($driver);
        $descriptor->method('configFields')->willReturn($fields);
        $descriptor->method('enabledSettingKey')->willReturn(DeliveryChannelSettings::enabledKey('custom'));

        return $descriptor;
    }

    /** @param array<string, ?string> $values Persisted settings rows by key; null is a cleared override */
    private function settings(array $values): void
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('offsetExists')->willReturnCallback(static fn (string $key): bool => array_key_exists($key, $values));
        $settings->method('offsetGet')->willReturnCallback(function (string $key) use ($values): Setting {
            $setting = $this->createStub(Setting::class);
            $setting->method('__get')->willReturn($values[$key]);
            return $setting;
        });
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturn($settings);
        Hilos::$setting = $this->createStub(SettingsAccessor::class);
        Hilos::$setting->method('offsetGet')->willReturnCallback(static fn (string $key): SettingValue => new SettingValue($key));
        Hilos::$setting->method('typeFor')->willReturnCallback(static fn (string $key): string =>
            $key === DeliveryChannelSettings::enabledKey('custom') ? SettingsCatalogConstants::TYPE_BOOLEAN : SettingsCatalogConstants::TYPE_STRING);
        Hilos::$setting->method('effectiveValueFor')->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? false);
    }
}
