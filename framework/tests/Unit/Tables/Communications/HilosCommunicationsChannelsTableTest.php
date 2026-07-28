<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Communications;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTableRow;
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
        return new class($channels) extends HilosCommunicationsChannelsTable {
            /** @param list<AbstractDeliveryChannel> $channelsFixture */
            public function __construct(private readonly array $channelsFixture)
            {
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
        };
    }
}
