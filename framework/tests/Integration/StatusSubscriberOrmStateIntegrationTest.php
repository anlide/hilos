<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCompatibilityValidator;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\StatusSubscriber as EntityStatusSubscriber;
use Hilos\Hilos;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** Anonymization through raw SQL, with a cold ORM reset afterwards. */
#[IntegrationTest]
final class StatusSubscriberOrmStateIntegrationTest extends StatusSubscriberStorageIntegrationTestCase implements BreaksOrmState
{
    public function testAnonymizationPreservesUniqueUndeliverableAddressesAndDisablesLinks(): void
    {
        $first = Hilos::$db->statusSubscribers->actions->create('one@example.org', 1000, 'UTC');
        $second = Hilos::$db->statusSubscribers->actions->create('two@example.org', 2000, 'Europe/Warsaw');
        Hilos::$db->statusSubscribers[$second->subscriberId]->actions->confirm($second->token, 3000);
        $schemas = LiveSchemaReader::read(0);
        $registry = new PiiRegistry(
            [0 => [EntityStatusSubscriber::_table => EntityStatusSubscriber::_pii]],
            [0 => [EntityStatusSubscriber::_table => EntityStatusSubscriber::_piiNotPersonal]],
        );
        AnonymizationCoverageValidator::validateLiveSchema(
            $registry, [0 => [EntityStatusSubscriber::_table => $schemas[EntityStatusSubscriber::_table]]],
        );
        AnonymizationCompatibilityValidator::validate($registry, 0, $schemas, static fn (): int => $second->subscriberId);
        Database::sql(new AnonymizationSqlBuilder('test-salt')->updateStatement(
            $schemas[EntityStatusSubscriber::_table], EntityStatusSubscriber::_pii,
        ));
        foreach ([$first->subscriberId, $second->subscriberId] as $id) {
            $row = $this->stored($id);
            self::assertSame('user' . $id . '@example.invalid', $row['email']);
            self::assertNull($row['confirmation_token_hash']);
            self::assertNull($row['unsubscribe_token']);
            self::assertNull($row['browser_time_zone']);
        }
        self::assertSame(1, (int)$this->stored($second->subscriberId)['confirmed']);
        self::assertSame(3000, (int)$this->stored($second->subscriberId)['confirmed_at']);
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($first->token));
        $delivered = false;
        Hilos::$db->statusSubscribers[$first->subscriberId]->deliverMail(
            static function () use (&$delivered): void { $delivered = true; },
        );
        self::assertFalse($delivered);
    }
}
