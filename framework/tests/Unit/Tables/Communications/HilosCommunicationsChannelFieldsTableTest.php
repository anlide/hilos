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
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigSource;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the communications channel-config fields table (HIL-200).
 *
 * The channel registry is bound by a test subclass through the table's seam; with no
 * env, DB, or settings facade every field resolves to its descriptor default. The
 * assertions exercise the per-field row projection, secret masking, the row key
 * (globally-unique setting key), and the settings source-change dispatch.
 */
final class HilosCommunicationsChannelFieldsTableTest extends TestCase
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

    public function testSnapshotProjectsOneRowPerConfigField(): void
    {
        $mail = new MailDeliveryChannel();
        $rows = $this->table($mail)->getFullSnapshot()->rows;

        self::assertCount(count($mail->configFields()), $rows);
        foreach ($rows as $row) {
            self::assertInstanceOf(HilosCommunicationsChannelFieldsTableRow::class, $row);
            self::assertSame(MailDeliveryChannel::NAME, $row->channel);
        }
    }

    public function testRowKeyIsTheGloballyUniqueSettingKey(): void
    {
        $row = $this->rowFor(MailDeliveryChannel::FIELD_SMTP_HOST);

        self::assertSame(
            DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_HOST),
            $row->getRowKey(),
        );
    }

    public function testOperationalFieldExposesDescriptorDefault(): void
    {
        $row = $this->rowFor(MailDeliveryChannel::FIELD_SMTP_PORT);

        self::assertSame(SettingsCatalogConstants::TYPE_INTEGER, $row->type);
        self::assertSame(587, $row->value);
        self::assertSame(ChannelConfigSource::DEFAULT->value, $row->valueSource);
        self::assertFalse($row->secret);
        self::assertTrue($row->editable);
    }

    public function testSecretFieldIsMaskedAndNotEditable(): void
    {
        $row = $this->rowFor(MailDeliveryChannel::FIELD_SMTP_PASSWORD);

        self::assertNull($row->value);
        self::assertTrue($row->secret);
        self::assertFalse($row->editable);
    }

    public function testBrowserRowRidesSingleFieldSlot(): void
    {
        $envelope = $this->table(new MailDeliveryChannel())->browserRow($this->rowFor(MailDeliveryChannel::FIELD_SMTP_HOST));
        $expectedKey = DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_HOST);

        self::assertSame($expectedKey, $envelope[BrowserPageSignalData::rowKey]);
        self::assertArrayHasKey('field', $envelope[BrowserPageSignalData::sources]);
        self::assertSame(
            MailDeliveryChannel::FIELD_SMTP_HOST,
            $envelope[BrowserPageSignalData::sources]['field'][HilosCommunicationsChannelFieldsTableRow::field],
        );
    }

    public function testTheRowSlotCarriesNoIdField(): void
    {
        self::assertArrayNotHasKey('id', $this->rowFor(MailDeliveryChannel::FIELD_SMTP_HOST)->toArray());
    }

    public function testFieldSettingChangeUpdatesItsRow(): void
    {
        $fieldKey = DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_FROM_ADDRESS);

        $mutation = $this->table(new MailDeliveryChannel())->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::settings, '7', [ObjectSetting::key => $fieldKey]),
        );

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertSame($fieldKey, $mutation->rowKey);
        self::assertInstanceOf(HilosCommunicationsChannelFieldsTableRow::class, $mutation->row);
        self::assertSame(MailDeliveryChannel::FIELD_FROM_ADDRESS, $mutation->row->field);
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
                SourceChange::dbUpdated(HilosDbContext::settings, '8', [ObjectSetting::key => 'unrelated.key']),
            ),
        );
    }

    /**
     * Resolves the fields-table row for one mail field by its field key.
     *
     * @param string $field Mail config field key
     * @return HilosCommunicationsChannelFieldsTableRow The matching field row
     */
    private function rowFor(string $field): HilosCommunicationsChannelFieldsTableRow
    {
        foreach ($this->table(new MailDeliveryChannel())->getFullSnapshot()->rows as $row) {
            if ($row instanceof HilosCommunicationsChannelFieldsTableRow && $row->field === $field) {
                return $row;
            }
        }

        self::fail("No fields-table row for {$field}");
    }

    /**
     * Builds a fields table bound to an in-memory set of channel descriptors.
     *
     * @param AbstractDeliveryChannel ...$channels Channel descriptors to expose
     * @return HilosCommunicationsChannelFieldsTable Table over the bound channels
     */
    private function table(AbstractDeliveryChannel ...$channels): HilosCommunicationsChannelFieldsTable
    {
        return new class($channels) extends HilosCommunicationsChannelFieldsTable {
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
