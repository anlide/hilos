<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Communications;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingValue;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Item\Setting;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the communications channels hub table (HIL-200).
 *
 * The channel registry is bound by a test subclass through the table's seam; with no
 * env, DB, or settings facade every config field falls to its descriptor default, so
 * the mail channel reads disabled and unconfigured. The assertions exercise the row
 * projection, the browser-row envelope, and the settings source-change dispatch.
 */
final class HilosCommunicationsChannelsTableTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?HilosDbContext $previousDb = null;
    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        // No env / DB / settings: every field resolves to its descriptor default.
        Hilos::$env = null;
        Hilos::$db = null;
        Hilos::$setting = null;
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        parent::tearDown();
    }

    public function testSnapshotProjectsOneRowPerChannel(): void
    {
        $rows = $this->table(new MailDeliveryChannel())->getFullSnapshot()->rows;

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertInstanceOf(HilosCommunicationsChannelsTableRow::class, $row);
        self::assertSame(MailDeliveryChannel::NAME, $row->getRowKey());
        self::assertSame(MailDeliveryChannel::NAME, $row->channel);
        self::assertSame('Email', $row->label);
        self::assertSame('smtp', $row->driver);
    }

    public function testUnconfiguredChannelCountsEveryDefaultedField(): void
    {
        $mail = new MailDeliveryChannel();
        $row = $this->table($mail)->getFullSnapshot()->rows[0];

        self::assertInstanceOf(HilosCommunicationsChannelsTableRow::class, $row);
        self::assertFalse($row->enabled);
        self::assertFalse($row->configured);
        self::assertSame(count($mail->configFields()), $row->missingFields);
    }

    /**
     * @param bool $exists Whether a persisted enablement row exists
     * @param ?string $value Persisted override, or null when cleared
     * @param bool $enabled Expected channel state
     */
    #[DataProvider('enablementOverrides')]
    public function testOnlyAPersistedOverrideReadsTheCatalog(bool $exists, ?string $value, bool $enabled): void
    {
        $mail = $this->createPartialMock(MailDeliveryChannel::class, ['configFields']);
        $mail->method('configFields')->willReturn([]);
        $key = $mail->enabledSettingKey();
        Hilos::$db = $this->createMock(HilosDbContext::class);
        Hilos::$setting = $this->createMock(SettingsAccessor::class);
        Hilos::$setting->expects(self::once())->method('offsetExists')->with($key)->willReturn(true);
        Hilos::$setting->expects(self::once())->method('offsetGet')->with($key)->willReturn(new SettingValue($key));
        Hilos::$setting->expects(self::once())->method('typeFor')->with($key)->willReturn(SettingsCatalogConstants::TYPE_BOOLEAN);
        Hilos::$setting->expects(self::once())->method('effectiveValueFor')->with($key)->willReturn($value ?? ($enabled ? '1' : '0'));

        $row = $this->table($mail)->getFullSnapshot()->rows[0];

        self::assertInstanceOf(HilosCommunicationsChannelsTableRow::class, $row);
        self::assertSame($enabled, $row->enabled);
    }

    /** @return iterable<string, array{bool, ?string, bool}> Missing, cleared, disabled and enabled overrides */
    public static function enablementOverrides(): iterable
    {
        yield 'missing row' => [false, null, false];
        yield 'cleared override' => [true, null, false];
        yield 'disabled override' => [true, '0', false];
        yield 'enabled override' => [true, '1', true];
    }

    public function testBrowserRowRidesSingleChannelSlot(): void
    {
        $table = $this->table(new MailDeliveryChannel());
        $row = new HilosCommunicationsChannelsTableRow(
            channel: 'email',
            label: 'Email',
            enabled: true,
            configured: true,
            driver: 'smtp',
            missingFields: 0,
        );

        self::assertSame(
            [
                BrowserPageSignalData::rowKey => 'email',
                BrowserPageSignalData::sources => [
                    'channel' => [
                        HilosCommunicationsChannelsTableRow::channel => 'email',
                        HilosCommunicationsChannelsTableRow::label => 'Email',
                        HilosCommunicationsChannelsTableRow::enabled => true,
                        HilosCommunicationsChannelsTableRow::configured => true,
                        HilosCommunicationsChannelsTableRow::driver => 'smtp',
                        HilosCommunicationsChannelsTableRow::missingFields => 0,
                    ],
                ],
            ],
            $table->browserRow($row),
        );
    }

    public function testTheRowSlotCarriesNoIdField(): void
    {
        $row = new HilosCommunicationsChannelsTableRow('email', 'Email', false, false, 'smtp', 8);

        self::assertArrayNotHasKey('id', $row->toArray());
        self::assertSame('email', $row->toArray()[HilosCommunicationsChannelsTableRow::channel]);
    }

    public function testEnablementChangeUpdatesChannelRow(): void
    {
        $enabledKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);

        $mutation = $this->table(new MailDeliveryChannel())->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::settings, '3', [ObjectSetting::key => $enabledKey]),
        );

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertSame(MailDeliveryChannel::NAME, $mutation->rowKey);
        self::assertInstanceOf(HilosCommunicationsChannelsTableRow::class, $mutation->row);
    }

    public function testEnablementChangeResolvesKeyByRowIdWhenDiffCarriesNoKey(): void
    {
        $enabledKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);

        $table = $this->tableWithSettingKeys([5 => $enabledKey], new MailDeliveryChannel());
        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::settings, '5', [ObjectSetting::value => '1']),
        );

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertSame(MailDeliveryChannel::NAME, $mutation->rowKey);
        self::assertInstanceOf(HilosCommunicationsChannelsTableRow::class, $mutation->row);
    }

    public function testFieldChangeUpdatesOwningChannelRow(): void
    {
        $fieldKey = DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_HOST);

        $mutation = $this->table(new MailDeliveryChannel())->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::settings, '4', [ObjectSetting::key => $fieldKey]),
        );

        self::assertNotNull($mutation);
        self::assertSame(MailDeliveryChannel::NAME, $mutation->rowKey);
    }

    public function testUnrelatedSourceIsIgnored(): void
    {
        self::assertNull(
            $this->table(new MailDeliveryChannel())->buildMutationForSourceEvent(
                SourceChange::rtUpdated('other', 'x', []),
            ),
        );
    }

    public function testUnknownSettingKeyIsIgnored(): void
    {
        self::assertNull(
            $this->table(new MailDeliveryChannel())->buildMutationForSourceEvent(
                SourceChange::dbUpdated(HilosDbContext::settings, '9', [ObjectSetting::key => 'unrelated.key']),
            ),
        );
    }

    /**
     * Builds a channels table bound to an in-memory set of channel descriptors.
     *
     * @param AbstractDeliveryChannel ...$channels Channel descriptors to expose
     * @return HilosCommunicationsChannelsTable Table over the bound channels
     */
    private function table(AbstractDeliveryChannel ...$channels): HilosCommunicationsChannelsTable
    {
        return $this->tableWithSettingKeys([], ...$channels);
    }

    /**
     * Builds a channels table with pre-seeded setting keys by row id.
     *
     * @param array<int, string> $settingKeys Setting keys by numeric row id
     * @param AbstractDeliveryChannel ...$channels Channel descriptors to expose
     * @return HilosCommunicationsChannelsTable Table over the bound channels
     */
    private function tableWithSettingKeys(array $settingKeys, AbstractDeliveryChannel ...$channels): HilosCommunicationsChannelsTable
    {
        return new class($channels, $settingKeys) extends HilosCommunicationsChannelsTable {
            /**
             * @param list<AbstractDeliveryChannel> $channelsFixture
             * @param array<int, string> $settingKeysFixture
             */
            public function __construct(
                private readonly array $channelsFixture,
                private readonly array $settingKeysFixture,
            ) {
                parent::__construct();
            }

            protected function channels(): iterable
            {
                $keyed = [];
                foreach ($this->channelsFixture as $channel) {
                    $keyed[$channel->name()] = $channel;
                }

                return $keyed;
            }

            protected function settingKeyById(int $id): ?string
            {
                return $this->settingKeysFixture[$id] ?? null;
            }
        };
    }
}
