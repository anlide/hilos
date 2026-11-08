<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsRawSendAgent;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

#[IntegrationTest]
final class NotificationDeliveryOwnershipIntegrationTest extends NotificationDeliveryOwnershipIntegrationTestCase
{
    public function testOwnerCanSyncAndUseMountedItemActionsWithoutReadingAnOrphansParent(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $this->asClaimant(self::mailOwner(), static function () use ($id): void {
            $object = Hilos::$db->notificationDeliveries->getObjectCollection()[$id];
            $object->attempts = 1;
            $object->sync();
            Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt();
            Hilos::$db->notificationDeliveries[$id]->actions->markSent();
        });
        self::assertSame([
            ['status' => DeliveryStatus::SENT, 'attempts' => '2', 'notification_id' => '11', 'routing_key' => '0'],
        ], Database::sql(
            'SELECT status, attempts, notification_id, routing_key FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    /** @return iterable<string, array{string, string}> Every real item door under a foreign channel or shard */
    public static function foreignWrites(): iterable
    {
        foreach (['sync', 'item update', 'delete', 'item delete'] as $door) {
            yield "{$door}, foreign channel" => [$door, SmsDeliveryChannelAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '1'];
            yield "{$door}, foreign shard" => [$door, MailDeliveryChannelAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '2'];
        }
        foreach (['0', '01', 'invalid'] as $index) {
            yield "sync, invalid shard {$index}" => ['sync', MailDeliveryChannelAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . $index];
        }
        yield 'sync, missing shard' => ['sync', MailDeliveryChannelAgent::AGENT_TYPE];
    }

    /**
     * The harness's independent whole library grant is present throughout this matrix.
     *
     * @param string $door Actual write entrance
     * @param string $actor Foreign claimant with the operation granted
     */
    #[DataProvider('foreignWrites')]
    public function testForeignWritesRefuseEvenBesideALibraryGrant(string $door, string $actor): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $this->asClaimant($actor, function () use ($door, $id): void {
            $object = Hilos::$db->notificationDeliveries->getObjectCollection()[$id];
            $this->assertRefusedWithoutChanges(static function () use ($door, $id, $object): void {
                switch ($door) {
                    case 'sync':
                        $object->status = DeliveryStatus::SENT;
                        $object->sync();
                        break;
                    case 'item update':
                        Hilos::$db->notificationDeliveries[$id]->actions->markSent();
                        break;
                    case 'delete':
                        $object->delete();
                        break;
                    case 'item delete':
                        Hilos::$db->notificationDeliveries[$id]->actions->delete();
                        break;
                }
            }, self::mailOwner());
            self::assertSame(DeliveryStatus::PENDING, $object->status);
            self::assertFalse($object->hasChanges());
        });
    }

    /** @return iterable<string, array{bool}> The raw worker receives neither operation */
    public static function rawWriteDoors(): iterable
    {
        yield 'Update' => [false];
        yield 'Remove' => [true];
    }

    /** @param bool $remove Whether to attempt Remove instead of Update */
    #[DataProvider('rawWriteDoors')]
    public function testRawSmsCannotBorrowTheLibraryGrant(bool $remove): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, SmsDeliveryChannel::NAME, 0)->id;
        DeliveryRawOwnershipHilos::initBrowser();
        $raw = new SmsRawSendAgent('1');
        OwnershipDeclaration::claimAll($raw);
        ExecutionContext::run(new ExecutionFrame(agentId: $raw->getId()), function () use ($id, $remove): void {
            $this->assertRefusedWithoutChanges(static function () use ($id, $remove): void {
                if ($remove) {
                    Hilos::$db->notificationDeliveries[$id]->actions->delete();
                } else {
                    Hilos::$db->notificationDeliveries[$id]->actions->markSent();
                }
            });
        });
    }

    /** @return iterable<string, array{bool}> Direct delete and mounted item delete use the same row proof */
    public static function deleteDoors(): iterable
    {
        yield 'Object' => [false];
        yield 'item action' => [true];
    }

    /** @param bool $throughAction Whether to use the mounted action */
    #[DataProvider('deleteDoors')]
    public function testOwnRemovePassesTheSamePartitionProofWhenExplicitlyGranted(bool $throughAction): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $this->asClaimant(self::mailOwner(), static function () use ($id, $throughAction): void {
            if ($throughAction) {
                Hilos::$db->notificationDeliveries[$id]->actions->delete();
            } else {
                Hilos::$db->notificationDeliveries->getObjectCollection()[$id]->delete();
            }
        });
        self::assertSame([], Database::sql('SELECT id FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
    }

    /** @return iterable<string, array{TruthSourceOperation}> Bulk doors may never use a partition grant */
    public static function operations(): iterable
    {
        foreach (TruthSourceOperation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }

    /** @param TruthSourceOperation $operation Operation requested without a concrete row */
    #[DataProvider('operations')]
    public function testWholeAndSetDoorsRejectPartitionClaimantsWithoutClimbing(TruthSourceOperation $operation): void
    {
        Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0);
        $unreadableParent = static function (): array {
            self::fail('The partition guard must never read the parent set');
        };
        $this->asClaimant(self::mailOwner(), function () use ($operation, $unreadableParent): void {
            $this->assertRefusedWithoutChanges(static fn () => DbWriteGuard::guardCollectionWrite(
                HilosDbContext::notificationDeliveries, $operation,
            ));
            $this->assertRefusedWithoutChanges(static fn () => DbWriteGuard::guardSetWrite(
                HilosDbContext::notificationDeliveries, '11', $unreadableParent, $operation,
            ));
            if ($operation === TruthSourceOperation::Remove) {
                $this->assertRefusedWithoutChanges(Hilos::$db->notificationDeliveries->getObjectCollection()->deleteAll(...));
            }
        });
    }

    public function testPartitionedUpdateCannotOmitTheProposedObject(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $this->asClaimant(self::mailOwner(), function () use ($id): void {
            $this->assertRefusedWithoutChanges(static fn () => DbWriteGuard::guardItemWrite(
                HilosDbContext::notificationDeliveries, (string)$id,
                static function (): array {
                    self::fail('A whole grant must not read the parent');
                },
                TruthSourceOperation::Update,
            ), self::mailOwner());
        });
    }

    /** @return iterable<string, array{string, bool}> All immutable columns under the channel and the transition library */
    public static function immutableCoordinates(): iterable
    {
        foreach ([EntityNotificationDelivery::id, EntityNotificationDelivery::notification_id,
            EntityNotificationDelivery::channel, EntityNotificationDelivery::routing_key] as $column) {
            yield "{$column}, channel" => [$column, true];
            yield "{$column}, transition library" => [$column, false];
        }
    }

    /**
     * @param string $column Immutable coordinate dirtied before sync
     * @param bool $channelWriter Whether to use the partitioned channel or the existing library writer
     */
    #[DataProvider('immutableCoordinates')]
    public function testImmutableCoordinatesCannotMoveADirtyObjectAndSyncRevertsIt(string $column, bool $channelWriter): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $other = Hilos::$db->notificationDeliveries->actions->createPending(12, MailDeliveryChannel::NAME, 0)->id;
        $write = function () use ($id, $other, $column): void {
            $object = Hilos::$db->notificationDeliveries->getObjectCollection()[$id];
            $before = $object->toArray();
            $entity = new ReflectionProperty(Object_::class, 'entity')->getValue($object);
            $entity->$column = match ($column) {
                EntityNotificationDelivery::id => $other,
                EntityNotificationDelivery::notification_id => 12,
                EntityNotificationDelivery::channel => SmsDeliveryChannel::NAME,
                EntityNotificationDelivery::routing_key => 1,
            };
            $this->assertRefusedWithoutChanges($object->sync(...));
            self::assertSame($before, $object->toArray());
            self::assertFalse($object->hasChanges());
        };
        if ($channelWriter) {
            $this->asClaimant(self::mailOwner(), $write);
        } else {
            $write();
        }
    }

    public function testDirtyPrimaryKeyCannotUseAnotherExistingRowsMatchingCoordinatesAsProof(): void
    {
        $foreign = Hilos::$db->notificationDeliveries->actions->createPending(11, SmsDeliveryChannel::NAME, 1)->id;
        $own = Hilos::$db->notificationDeliveries->actions->createPending(12, MailDeliveryChannel::NAME, 0)->id;
        $object = Hilos::$db->notificationDeliveries->getObjectCollection()[$foreign];
        $before = $object->toArray();
        $entity = new ReflectionProperty(Object_::class, 'entity')->getValue($object);
        $entity->id = $own;
        $entity->notification_id = 12;
        $entity->channel = MailDeliveryChannel::NAME;
        $entity->routing_key = 0;
        $object->status = DeliveryStatus::SENT;
        $this->asClaimant(self::mailOwner(), function () use ($object): void {
            $this->assertRefusedWithoutChanges($object->sync(...), self::mailOwner());
        });
        self::assertSame($before, $object->toArray());
    }

    public function testMissingDescriptorAndRoutedNonDeliveryWorkerRefuseWithAPermissionException(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        DeliveryOwnershipWithoutDescriptorsHilos::initBrowser();
        $this->asClaimant(self::mailOwner(), function () use ($id): void {
            $this->assertRefusedWithoutChanges(static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent());
        });
        DeliveryOwnershipWrongClassHilos::initBrowser();
        $this->asClaimant(self::mailOwner(), function () use ($id): void {
            $this->assertRefusedWithoutChanges(
                static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent(), self::mailOwner(),
            );
        });
    }

    public function testPoolWidthIsReadAgainAndLegacyNullUsesTheNotificationId(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(3, MailDeliveryChannel::NAME, null)->id;
        $oldOwner = MailDeliveryChannelAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '2';
        $this->asClaimant($oldOwner, static fn () => Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt());
        DeliveryOwnershipChannels::$width = 3;
        $this->asClaimant($oldOwner, function () use ($id): void {
            $this->assertRefusedWithoutChanges(
                static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent(), self::mailOwner(),
            );
        });
        $this->asClaimant(self::mailOwner(), static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent());
        self::assertSame([['status' => DeliveryStatus::SENT, 'routing_key' => null]], Database::sql(
            'SELECT status, routing_key FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    public function testChannelsGainNoCreatePermissionFromTheLibraryBesideThem(): void
    {
        $this->asClaimant(self::mailOwner(), static function (): void {
            try {
                Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0);
                self::fail('A delivery channel has no Add operation');
            } catch (CreateNotAllowedException) {
                self::assertSame([], Database::sql('SELECT id FROM hilos_notification_delivery')->rows());
            }
        });
    }

    public function testUnregisteredExecutionCannotBorrowTheLibraryAndANonDeliveryEnumClaimantIsRefused(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $token = ExecutionContext::push(new ExecutionFrame(agentId: 'unregistered-delivery'));
        try {
            $this->assertRefusedWithoutChanges(static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent());
        } finally {
            ExecutionContext::pop($token);
        }
        $this->asClaimant(OwnershipImpostorAgent::AGENT_TYPE, function () use ($id): void {
            $this->assertRefusedWithoutChanges(
                static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent(), self::mailOwner(),
            );
        });
    }

    public function testNoAgentCannotBorrowPartitionedRowWholeOrSetGrantsButAnExplicitOfflineWriterStillCan(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $this->claim(self::mailOwner());
        TruthSourceRegistry::unregisterAgent(ExecutionContext::currentAgentId());
        $token = ExecutionContext::push(new ExecutionFrame());
        $unreadableParent = static function (): array {
            self::fail('Ambient channel grants must be excluded before reading the parent');
        };
        try {
            $this->assertRefusedWithoutChanges(static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent());
            $this->assertRefusedWithoutChanges(static fn () => TruthSourceRegistry::checkCanWrite(
                HilosDbContext::notificationDeliveries, TruthSourceOperation::Update,
            ));
            $this->assertRefusedWithoutChanges(static fn () => TruthSourceRegistry::checkCanWriteSet(
                HilosDbContext::notificationDeliveries, '11', $unreadableParent, TruthSourceOperation::Update,
            ));
            $this->claim('explicit-offline-writer');
            Hilos::$db->notificationDeliveries[$id]->actions->markSent();
            self::assertSame([['status' => DeliveryStatus::SENT]], Database::sql(
                'SELECT status FROM hilos_notification_delivery WHERE id = ?', [$id],
            )->rows());
        } finally {
            ExecutionContext::pop($token);
        }
    }

    public function testAmbientCoverageCannotBorrowAPartitionedOperationFromBesideAnOfflineGrant(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $this->claim(self::mailOwner());
        $library = ExecutionContext::currentAgentId();
        TruthSourceRegistry::register(
            HilosDbContext::notificationDeliveries, TruthSourceKeys::all(), $library,
            TruthSourceOperations::of(TruthSourceOperation::Remove),
        );
        $token = ExecutionContext::push(new ExecutionFrame());
        try {
            $this->assertRefusedWithoutChanges(static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent());
            $this->assertRefusedWithoutChanges(static fn () => DbWriteGuard::guardCollectionWrite(
                HilosDbContext::notificationDeliveries, TruthSourceOperation::Update,
            ));
            $this->assertRefusedWithoutChanges(static fn () => DbWriteGuard::guardSetWrite(
                HilosDbContext::notificationDeliveries, '11', static fn (): array => [], TruthSourceOperation::Update,
            ));
        } finally {
            ExecutionContext::pop($token);
        }
    }
}
