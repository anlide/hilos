<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Pages\Status\DTO\HilosStatusRemoveSubscriberActionDTO;
use Hilos\Status\DTO\StatusRemoveSubscriberSignalData;
use Hilos\Status\DTO\StatusSubscriberCountsData;
use Hilos\Status\StatusAgent;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tables\Status\HilosStatusSubscribersTableRow;
use PHPUnit\Framework\TestCase;

/** Transport contracts and read-only table declarations; real DB windows are covered by integration. */
final class StatusSubscriberTableTest extends TestCase
{
    public function testInternalAskHasStrictCompleteRoundtripsIncludingNullableKeys(): void
    {
        $payload = ['subscriberId' => 31, 'replySignal' => 'done', 'acceptKey' => 'browser',
            'requestId' => null, 'action' => HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, 'successMessage' => null];
        $ask = StatusRemoveSubscriberSignalData::fromArray($payload);
        self::assertInstanceOf(HandoverAskInterface::class, $ask);
        self::assertSame($payload, $ask->toArray());
        self::assertSame($payload, StatusRemoveSubscriberSignalData::fromJson($ask->toJson())->toArray());
        foreach (array_keys($payload) as $key) {
            $missing = $payload;
            unset($missing[$key]);
            $this->refuses($missing);
        }
        foreach (['31', 0, -1, true, null, 1.5] as $id) {
            $this->refuses([...$payload, 'subscriberId' => $id]);
        }
        foreach (['replySignal', 'acceptKey', 'action'] as $key) {
            foreach (['', ' ', null, false] as $invalid) {
                $this->refuses([...$payload, $key => $invalid]);
            }
        }
        $this->refuses([...$payload, 'requestId' => str_repeat('я', 129)]);
        $this->refuses([...$payload, 'requestId' => 1]);
        $this->refuses([...$payload, 'successMessage' => false]);
        self::assertSame(str_repeat('я', 128), StatusRemoveSubscriberSignalData::fromArray(
            [...$payload, 'requestId' => str_repeat('я', 128)])->requestId);
    }

    public function testPageOwnsTheAdminActionAndReplyWhileTheOwnerOwnsOnlyTheInternalAsk(): void
    {
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosStatusSubscribersPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosStatusSubscribersPage::REACH);
        self::assertSame(HilosPageConstants::HILOS_STATUS_SUBSCRIBERS, AbstractHilosStatusSubscribersPage::PAGE);
        self::assertSame([HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE => HilosStatusRemoveSubscriberActionDTO::class],
            AbstractHilosStatusSubscribersPage::ACTIONS);
        self::assertSame([SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE => HandoverAnswerSignalData::class,
        ]], AbstractHilosStatusSubscribersPage::SIGNALS);
        self::assertContains(HilosDbContext::statusSubscribers, AbstractHilosStatusSubscribersPage::READS_DB);
        self::assertSame([], HilosStatusRemoveSubscriberActionDTO::SECRET_FIELDS);
        self::assertSame(['subscriberId' => 31], HilosStatusRemoveSubscriberActionDTO::fromArray(
            ['data' => ['subscriberId' => 31]])->toArray());
        self::assertSame(StatusRemoveSubscriberSignalData::class, StatusAgent::AGENT_SIGNALS[HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER]);
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, StatusAgent::AGENT_ACTIONS);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER, StatusAgent::AGENT_ACTIONS);
        self::assertTrue(AbstractHilosStatusSubscribersPage::observesOverviewChange(
            SourceChange::dbUpdated(HilosDbContext::statusSubscribers, '31', [])));
        self::assertFalse(AbstractHilosStatusSubscribersPage::observesOverviewChange(
            SourceChange::dbUpdated(HilosDbContext::statusIncidents, '31', [])));
    }

    public function testActionRequiresAPositiveIntegerTarget(): void
    {
        foreach (['31', 0, -1, null, true] as $invalid) {
            try {
                HilosStatusRemoveSubscriberActionDTO::fromArray(['subscriberId' => $invalid]);
                self::fail('Malformed primary id was accepted');
            } catch (InvalidFormatException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRowAndBrowserEnvelopeExposeExactlyFourFieldsAndReadTheSharedHiddenMark(): void
    {
        $table = new HilosStatusSubscribersTable();
        $payload = ['id' => 31, 'email' => 'one@example.net', 'subscribedAt' => 1700000000000, 'confirmed' => false];
        $row = HilosStatusSubscribersTableRow::fromArray([...$payload, 'browserTimeZone' => 'Europe/Warsaw',
            'confirmationExpiresAt' => 1700001000000, 'unsubscribeToken' => 'secret']);
        self::assertSame($payload, $row->toArray());
        self::assertSame(['rowKey' => 31, 'sources' => ['subscriber' => $payload]], $table->browserRow($row));
        self::assertSame([...$payload, 'email' => HiddenValue::mark()],
            HilosStatusSubscribersTableRow::fromArray([...$payload, 'email' => HiddenValue::mark()])->toArray());
        self::assertSame(array_keys($payload), array_keys($table->wireFields()));
        self::assertNull($table->getItemActionsClass());
        self::assertSame([], $table->bulkActions());
        self::assertSame([['field' => 'subscribedAt', 'direction' => 'desc']], $table->defaultSort()->toArray());
        self::assertSame(['confirmedCount' => 0, 'pendingCount' => 2], new StatusSubscriberCountsData(0, 2)->toArray());
        $this->expectException(InvalidFormatException::class);
        new StatusSubscriberCountsData(-1, 0);
    }

    /** @param array<string, mixed> $payload Malformed internal ask */
    private function refuses(array $payload): void
    {
        try {
            StatusRemoveSubscriberSignalData::fromArray($payload);
            self::fail('Incomplete or malformed ask was accepted');
        } catch (InvalidFormatException) {
            self::assertTrue(true);
        }
    }
}
