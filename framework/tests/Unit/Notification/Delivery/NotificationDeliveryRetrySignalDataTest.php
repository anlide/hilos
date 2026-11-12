<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the channel retry request the notifications library hands to the row's owner (HIL-1910).
 *
 * Locks the wire contract only: the saved identity snapshot with its nullable routing key and shard,
 * the handover fields copied from the page's ask, and the refusal of a frame that cannot name a
 * delivery. Nothing here resolves an owner or touches a row.
 */
final class NotificationDeliveryRetrySignalDataTest extends TestCase
{
    /** A pooled channel's request keeps every field, and names the public keys through the constants. */
    public function testPooledRequestRoundTripsEveryField(): void
    {
        $ask = new NotificationDeliveryRetrySignalData(
            deliveryId: 91,
            notificationId: 12,
            channel: MailDeliveryChannel::NAME,
            routingKey: 7001,
            shardKey: 3,
            replySignal: HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
            acceptKey: 'admin-ak',
            requestId: 'req-1',
            action: HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
            successMessage: 'Queued again',
        );

        $this->assertInstanceOf(HandoverAskInterface::class, $ask);
        $this->assertSame(
            [
                NotificationDeliveryRetrySignalData::deliveryId => 91,
                NotificationDeliveryRetrySignalData::notificationId => 12,
                NotificationDeliveryRetrySignalData::channel => 'email',
                NotificationDeliveryRetrySignalData::routingKey => 7001,
                NotificationDeliveryRetrySignalData::shardKey => 3,
                NotificationDeliveryRetrySignalData::replySignal => HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
                NotificationDeliveryRetrySignalData::acceptKey => 'admin-ak',
                NotificationDeliveryRetrySignalData::requestId => 'req-1',
                NotificationDeliveryRetrySignalData::action => HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
                NotificationDeliveryRetrySignalData::successMessage => 'Queued again',
            ],
            $ask->toArray(),
        );
        $this->assertEquals($ask, NotificationDeliveryRetrySignalData::fromArray($ask->toArray()));
    }

    /**
     * A singleton channel and a legacy row leave the routing key and shard absent; 0 is a real routing key.
     */
    public function testSingletonAndLegacyRequestsKeepAbsentFieldsNullAndZeroRoutingKey(): void
    {
        $singleton = NotificationDeliveryRetrySignalData::fromArray([
            'deliveryId' => 5,
            'notificationId' => 2,
            'channel' => SmsDeliveryChannel::NAME,
            'replySignal' => HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
            'acceptKey' => 'admin-ak',
            'action' => HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
        ]);
        $zero = NotificationDeliveryRetrySignalData::fromArray(
            array_replace($singleton->toArray(), [NotificationDeliveryRetrySignalData::routingKey => 0]),
        );

        $this->assertNull($singleton->routingKey);
        $this->assertNull($singleton->shardKey);
        $this->assertNull($singleton->requestId);
        $this->assertNull($singleton->successMessage);
        $this->assertSame(0, $zero->routingKey);
        $this->assertNull($zero->shardKey);
        $this->assertNull(NotificationDeliveryRetrySignalData::fromArray($singleton->toArray())->routingKey);
    }

    /** @param array<string, mixed> $payload Frame that cannot name one deliverable identity and one waiting submit */
    #[DataProvider('invalidRequests')]
    public function testRequestRefusesMissingMistypedAndInvalidFields(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        NotificationDeliveryRetrySignalData::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed retry frames */
    public static function invalidRequests(): iterable
    {
        $valid = [
            'deliveryId' => 91,
            'notificationId' => 12,
            'channel' => MailDeliveryChannel::NAME,
            'routingKey' => 7001,
            'shardKey' => 3,
            'replySignal' => HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
            'acceptKey' => 'admin-ak',
            'requestId' => 'req-1',
            'action' => HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
            'successMessage' => null,
        ];
        foreach (['deliveryId', 'notificationId', 'channel', 'replySignal', 'acceptKey', 'action'] as $required) {
            yield 'missing ' . $required => [array_diff_key($valid, [$required => true])];
        }
        foreach ([null, '91', true, 91.0, 0, -1] as $value) {
            yield 'delivery ' . var_export($value, true) => [array_replace($valid, ['deliveryId' => $value])];
        }
        yield 'notification string' => [array_replace($valid, ['notificationId' => '12'])];
        yield 'notification zero' => [array_replace($valid, ['notificationId' => 0])];
        yield 'empty channel' => [array_replace($valid, ['channel' => ''])];
        yield 'channel number' => [array_replace($valid, ['channel' => 7])];
        yield 'routing key string' => [array_replace($valid, ['routingKey' => '7001'])];
        yield 'routing key negative' => [array_replace($valid, ['routingKey' => -1])];
        yield 'shard string' => [array_replace($valid, ['shardKey' => '3'])];
        yield 'shard zero' => [array_replace($valid, ['shardKey' => 0])];
        yield 'request id number' => [array_replace($valid, ['requestId' => 1])];
        yield 'success message number' => [array_replace($valid, ['successMessage' => 1])];
    }

    /** The request crosses worker and daemon as an agent signal: the handover and the receipt arrive whole. */
    public function testAgentEnvelopeCarriesHandoverAndReceiptAcrossProcesses(): void
    {
        $page = new DeliveryRetrySignalData(
            deliveryId: 91,
            replySignal: HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
            acceptKey: 'admin-ak',
            requestId: 'req-1',
            action: HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
            successMessage: 'Queued again',
        );
        $ask = new NotificationDeliveryRetrySignalData(
            deliveryId: $page->deliveryId,
            notificationId: 12,
            channel: MailDeliveryChannel::NAME,
            routingKey: 7001,
            shardKey: 3,
            replySignal: $page->replySignal,
            acceptKey: $page->acceptKey,
            requestId: $page->requestId,
            action: $page->action,
            successMessage: $page->successMessage,
        );

        $wire = json_decode(json_encode(new AgentSignalData($ask, 42)->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = AgentSignalData::fromArray($wire);

        $this->assertSame(42, $restored->receiptId);
        $this->assertInstanceOf(NotificationDeliveryRetrySignalData::class, $restored->data);
        $this->assertEquals($ask, $restored->data);
        $this->assertSame($page->replySignal, $restored->data->replySignal);
        $this->assertSame($page->acceptKey, $restored->data->acceptKey);
        $this->assertSame($page->requestId, $restored->data->requestId);
        $this->assertSame($page->action, $restored->data->action);
        $this->assertSame($page->successMessage, $restored->data->successMessage);
    }
}
