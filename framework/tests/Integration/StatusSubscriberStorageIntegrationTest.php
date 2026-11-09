<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\StatusSubscriber as EntityStatusSubscriber;
use Hilos\Database\Object\Item\StatusSubscriber as ObjectStatusSubscriber;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Hilos;
use Hilos\Testing\IntegrationTest;
use ReflectionProperty;

/** Real storage, conditional spends, detached audiences and restore verdicts; no guest or mail transport. */
#[IntegrationTest]
final class StatusSubscriberStorageIntegrationTest extends StatusSubscriberStorageIntegrationTestCase
{
    public function testCreationNormalizesAndKeepsSecretsOutsideEveryProjectionAndSyncFact(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('  Person@Example.org ', 1000, 'Europe/Warsaw');
        $row = $this->stored($issued->subscriberId);
        self::assertSame('person@example.org', $row['email']);
        self::assertSame(1000, (int)$row['subscribed_at']);
        self::assertSame(86401000, (int)$row['confirmation_expires_at']);
        self::assertSame(hash('sha256', $issued->token), $row['confirmation_token_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $issued->token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row['unsubscribe_token']);
        self::assertNotSame($issued->token, $row['unsubscribe_token']);
        self::assertSame('Europe/Warsaw', $row['browser_time_zone']);
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers->findByEmail(' PERSON@example.org ')->id);
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers->findByConfirmationToken($issued->token)->id);
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers->findByUnsubscribeToken($row['unsubscribe_token'])->id);
        foreach ([
            Hilos::$db->statusSubscribers[$issued->subscriberId]->toArray(),
            Hilos::$db->statusSubscribers[$issued->subscriberId]->getObject()->toArray(),
            EntityStatusSubscriber::getById($issued->subscriberId)->toArray(),
            ...$this->syncMirror->rows,
        ] as $projection) {
            self::assertArrayNotHasKey('confirmation_token_hash', $projection);
            self::assertArrayNotHasKey('unsubscribe_token', $projection);
            self::assertStringNotContainsString($issued->token, json_encode($projection, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($row['unsubscribe_token'], json_encode($projection, JSON_THROW_ON_ERROR));
        }
        self::assertNotEmpty($this->syncMirror->rows);
        $registry = PiiRegistry::collect();
        $hidden = ViewerFields::hide(
            ['email' => $row['email'], 'confirmed' => false],
            [
                'email' => WireField::column(HilosDbContext::statusSubscribers, EntityStatusSubscriber::email),
                'confirmed' => WireField::column(HilosDbContext::statusSubscribers, EntityStatusSubscriber::confirmed),
            ],
            static fn (string $collection, string $field): bool => $collection === HilosDbContext::statusSubscribers
                && in_array($field, $registry->notPersonalColumns(0, EntityStatusSubscriber::_table) ?? [], true),
        );
        self::assertSame(['email' => HiddenValue::mark(), 'confirmed' => false], $hidden);
        $delivered = null;
        Hilos::$db->statusSubscribers[$issued->subscriberId]->deliverMail(
            static function (string $email, string $unsubscribeToken, ?string $zone) use (&$delivered): void {
                $delivered = [$email, $unsubscribeToken, $zone];
            },
        );
        self::assertSame(['person@example.org', $row['unsubscribe_token'], 'Europe/Warsaw'], $delivered);
    }

    public function testConditionalConfirmHasOneWinnerAcrossStaleObjectsAndClearsTheHash(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('race@example.org', 1000);
        $stale = ObjectStatusSubscriber::fromEntity(EntityStatusSubscriber::getById($issued->subscriberId));
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 2000));
        self::assertNull($stale->confirm($issued->token, 3000));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 3000));
        $row = $this->stored($issued->subscriberId);
        self::assertSame(1, (int)$row['confirmed']);
        self::assertSame(2000, (int)$row['confirmed_at']);
        self::assertNull($row['confirmation_token_hash']);
        self::assertNull($row['confirmation_expires_at']);
        self::assertTrue(Hilos::$db->statusSubscribers[$issued->subscriberId]->confirmed);
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($issued->token));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->issueConfirmation(4000));
        self::assertSame($row, $this->stored($issued->subscriberId));
    }

    public function testRollbackRestoresScalarStateAndTheSpendableHashTogether(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('rollback@example.org', 1000);
        $before = $this->stored($issued->subscriberId);
        Database::transactionStart();
        try {
            self::assertSame($issued->subscriberId,
                Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 2000));
            self::assertNull($this->stored($issued->subscriberId)['confirmation_token_hash']);
        } finally {
            Database::transactionRollback();
        }
        self::assertSame($before, $this->stored($issued->subscriberId));
        self::assertFalse(Hilos::$db->statusSubscribers[$issued->subscriberId]->confirmed);
        self::assertSame($issued->subscriberId,
            Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 3000));
    }

    public function testMailSecretReadRequiresTheSubscriptionOwner(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('owner@example.org', 1000);
        $token = ExecutionContext::push(new ExecutionFrame(agentId: 'unrelated-reader'));
        try {
            $this->expectException(WriteNotAllowedException::class);
            Hilos::$db->statusSubscribers[$issued->subscriberId]->deliverMail(static function (): void {
                self::fail('An unrelated reader must not receive the unsubscribe secret');
            });
        } finally {
            ExecutionContext::pop($token);
        }
    }

    public function testReplacementExpiryAndDeleteRecreateCannotReviveOldLinks(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('links@example.org', 1000, 'bad-zone');
        $row = $this->stored($issued->subscriberId);
        self::assertNull($row['browser_time_zone']);
        $renewed = Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->issueConfirmation(2000);
        self::assertNotNull($renewed);
        self::assertSame(1000, (int)$this->stored($issued->subscriberId)['subscribed_at']);
        self::assertSame($row['unsubscribe_token'], $this->stored($issued->subscriberId)['unsubscribe_token']);
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($issued->token));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 3000));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($renewed->token, 86402000));
        self::assertSame(0, (int)$this->stored($issued->subscriberId)['confirmed']);
        $stale = Hilos::$db->statusSubscribers->findByConfirmationToken($renewed->token);
        Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->delete();
        self::assertArrayNotHasKey($issued->subscriberId, Hilos::$db->statusSubscribers);
        $replacement = Hilos::$db->statusSubscribers->actions->create('links@example.org', 4000);
        self::assertGreaterThan($issued->subscriberId, $replacement->subscriberId);
        self::assertNull(Hilos::$db->statusSubscribers->findByUnsubscribeToken($row['unsubscribe_token']));
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($renewed->token));
        self::assertNull($stale->actions->confirm($renewed->token, 5000));
        self::assertSame($replacement->subscriberId,
            Hilos::$db->statusSubscribers[$replacement->subscriberId]->actions->confirm($replacement->token, 5000));
    }

    public function testTypedCountsAndRecipientCutoffsRemainBoundedAndDetached(): void
    {
        for ($index = 0; $index < 40; $index++) {
            $issued = Hilos::$db->statusSubscribers->actions->create(sprintf('recipient%02d@example.org', $index), 1000 + $index);
            if ($index < 37) {
                Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, $index === 36 ? 6000 : 2000);
            }
        }
        $counts = Hilos::$db->statusSubscribers->counts();
        self::assertSame([40, 37, 3], [$counts->total, $counts->confirmed, $counts->pending]);
        $later = Hilos::$db->statusSubscribers->actions->create('later@example.org', 1000);
        Hilos::$db->statusSubscribers[$later->subscriberId]->actions->confirm($later->token, 2000);
        $cold = new StatusSubscriberStorageDbContext();
        $cold->configure();
        self::assertFalse($cold->statusSubscribers->getObjectCollection()->isAllLoaded());
        self::assertSame(41, $cold->statusSubscribers->counts()->total);
        $first = $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, null, 1000);
        self::assertCount(32, $first);
        $second = $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, $first->last()->id, 32);
        self::assertCount(4, $second);
        self::assertCount(0, $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, $second->last()->id, 32));
        self::assertCount(0, $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, null, 0));
        self::assertFalse($cold->statusSubscribers->getObjectCollection()->isAllLoaded());
        self::assertSame([], (new ReflectionProperty(Objects::class, 'objects'))->getValue($cold->statusSubscribers->getObjectCollection()));
        self::assertSame([], EntitySchemaAudit::audit([EntityStatusSubscriber::class]));
        foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
            foreach (['email', 'subscribed_at', 'confirmed'] as $field) {
                $page = $cold->statusSubscribers->queryPage(new TableQueryDTO(
                    sort: TableSortOrderDTO::of(new TableSortDTO($field, $direction), new TableSortDTO('id', $direction)), limit: 7,
                ));
                self::assertCount(7, $page[TableConstants::RESULT_KEY_ROWS]);
                self::assertSame(41, $page[TableConstants::RESULT_KEY_TOTAL_COUNT]);
                $rows = $page[TableConstants::RESULT_KEY_ROWS];
                $viewField = $field === 'subscribed_at' ? 'subscribedAt' : $field;
                for ($index = 1; $index < count($rows); $index++) {
                    $left = [$rows[$index - 1][$viewField], $rows[$index - 1]['id']];
                    $right = [$rows[$index][$viewField], $rows[$index]['id']];
                    self::assertTrue($direction === TableConstants::ORDER_ASC ? $left < $right : $left > $right);
                }
            }
        }
    }

    /** Subscription writes refuse negative moments and leave table rows and tokens untouched. */
    public function testNegativeMomentsAreRefusedAcrossSubscriberWritePaths(): void
    {
        try {
            Hilos::$db->statusSubscribers->actions->create('neg@example.org', -1);
            self::fail('Negative subscribedAt must be refused on create');
        } catch (ValidationException $exception) {
            self::assertSame('The subscription time is outside the supported range.', $exception->getMessage());
            self::assertSame(0, (int)Database::sql('SELECT COUNT(*) AS c FROM `hilos_status_subscriber`')->row()['c']);
        }

        $issued = Hilos::$db->statusSubscribers->actions->create('valid@example.org', 1000);
        $before = $this->stored($issued->subscriberId);

        try {
            Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->issueConfirmation(-1);
            self::fail('Negative at must be refused on issueConfirmation');
        } catch (ValidationException $exception) {
            self::assertSame('The confirmation time is outside the supported range.', $exception->getMessage());
            self::assertSame($before, $this->stored($issued->subscriberId));
        }

        try {
            Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, -1);
            self::fail('Negative at must be refused on confirm');
        } catch (ValidationException $exception) {
            self::assertSame('The confirmation time must be nonnegative.', $exception->getMessage());
            self::assertSame($before, $this->stored($issued->subscriberId));
        }
    }
}
