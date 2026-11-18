<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Hilos;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Users\AccountStanding;
use Hilos\Users\AccountStandingKind;
use Hilos\Users\DTO\AccountStandingStateSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact allowlist and viewer column provenance of merge observation. */
final class AccountMergeProgressTest extends TestCase
{
    public function testLiteralAllowlistRoundtripsWithoutSecretOrStorageFields(): void
    {
        $data = self::data();
        self::assertSame($data, AccountMergeProgress::fromArray($data)->toArray());
        self::assertSame([
            'operationId', 'survivorUserId', 'loserUserId', 'state', 'stage', 'outcome',
            'given', 'received', 'choice', 'refusal', 'parts', 'waits',
        ], array_keys($data));
        self::assertSame([
            'passwordFate', 'secondFactorFate', 'expectedSurvivorHasSecondFactor', 'expectedLoserHasSecondFactor',
        ], array_keys($data['choice']));
        self::assertSame(['participant', 'owner', 'userId', 'done'], array_keys($data['parts'][0]));
        self::assertSame(['participant', 'userId'], array_keys($data['waits'][0]));
        self::assertStringNotContainsString('passwordIdentityId', json_encode($data));
        self::assertStringNotContainsString('ownerAgentId', json_encode($data));
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid identities, state/outcome and injected fields */
    public static function invalid(): iterable
    {
        foreach ([
            'zero operation' => ['operationId' => 0],
            'string id' => ['operationId' => '3'],
            'same pair' => ['loserUserId' => 7],
            'negative pair' => ['survivorUserId' => -7],
            'unknown state' => ['state' => 'success'],
            'unknown stage' => ['stage' => 'end'],
            'end without outcome' => ['state' => 'end', 'stage' => 'go'],
            'pending outcome' => ['outcome' => 'completed'],
            'completed before go' => ['state' => 'end', 'outcome' => 'completed'],
            'not started outside cancellation' => ['state' => 'end', 'stage' => 'go', 'outcome' => 'not_started'],
            'unknown outcome' => ['state' => 'end', 'outcome' => 'canceled'],
            'refusal without reason' => ['state' => 'refusal'],
            'wrong refusal code' => ['state' => 'refusal', 'refusal' => ['code' => 'engine', 'reason' => 'Private']],
            'numeric flag' => ['given' => 1],
            'raw result' => ['result' => ['secret' => 'never']],
            'nested raw owner' => ['parts' => [['participant' => 'give', 'owner' => HilosAgentType::HILOS_USER,
                'userId' => 9, 'done' => false, 'ownerIndex' => '9']]],
            'invalid wait id' => ['waits' => [['participant' => 'project', 'userId' => 0]]],
            'non-list parts' => ['parts' => ['key' => ['participant' => 'give', 'owner' => HilosAgentType::HILOS_USER,
                'userId' => 9, 'done' => false]]],
            'secret choice' => ['choice' => ['passwordIdentityId' => 88]],
        ] as $name => $changes) {
            yield $name => [array_replace(self::data(), $changes)];
        }
        $missing = self::data();
        unset($missing['received']);
        yield 'missing boolean' => [$missing];
    }

    /** @param array<string, mixed> $data Invalid wire object */
    #[DataProvider('invalid')]
    public function testInvalidShapesAndStatesRefuse(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeProgress::fromArray($data);
    }

    public function testDurableRefusalAndEndRemainDifferentOutcomes(): void
    {
        foreach ([
            ['state' => 'refusal', 'stage' => 'cancelling', 'outcome' => null,
                'refusal' => ['code' => AccountMergeRefusal::PasswordFateRequired->value, 'reason' => 'Choose a password.']],
            ['state' => 'refusal', 'stage' => 'cancelling', 'outcome' => 'not_started',
                'refusal' => ['code' => AccountMergeRefusal::PasswordFateRequired->value, 'reason' => 'Choose a password.']],
            ['state' => 'end', 'stage' => 'cancelling', 'outcome' => 'not_started'],
            ['state' => 'end', 'stage' => 'go', 'outcome' => 'completed'],
            ['state' => 'wait', 'stage' => 'go'],
        ] as $changes) {
            $data = array_replace(self::data(), $changes);
            self::assertSame($data, AccountMergeProgress::fromArray($data)->toArray());
        }
    }

    public function testViewerOriginsHideSavedCorrelationChoicesAndRefusalButKeepDerivedProgress(): void
    {
        $previous = Hilos::$db;
        Hilos::$db = new class extends HilosDbContext {};
        Hilos::$db->configure();
        try {
            $browser = new class extends BrowserContext {
                /** @return ?PiiRegistry Actual people and operation column verdicts */
                protected function viewerPiiRegistry(): ?PiiRegistry
                {
                    return new PiiRegistry([DatabaseConnectionDefaults::PRIMARY_INDEX => [
                        EntityUser::_table => EntityUser::_pii, EntityAccountOperation::_table => EntityAccountOperation::_pii,
                    ]], [DatabaseConnectionDefaults::PRIMARY_INDEX => [
                        EntityUser::_table => EntityUser::_piiNotPersonal,
                    ]]);
                }
            };
            $payload = self::data();
            $payload['refusal'] = ['code' => AccountMergeRefusal::Occupied->value, 'reason' => 'Private account name'];
            $payload['unexpected'] = 'Private';
            $hidden = $browser->hideForViewer($payload, AccountMergeProgress::wireFields());
            foreach (['operationId', 'survivorUserId', 'loserUserId', 'stage', 'outcome', 'choice', 'refusal', 'unexpected'] as $key) {
                self::assertSame(HiddenValue::mark(), $hidden[$key]);
            }
            foreach (['state', 'given', 'received', 'parts', 'waits'] as $key) {
                self::assertSame($payload[$key], $hidden[$key]);
            }
            foreach (['operationId' => 'id', 'survivorUserId' => 'root_user_id', 'loserUserId' => 'loser_user_id',
                'stage' => 'stage', 'outcome' => 'outcome', 'choice' => 'merge_choice'] as $key => $column) {
                self::assertSame(HilosDbContext::accountOperations, AccountMergeProgress::wireFields()[$key]->collection);
                self::assertSame($column, AccountMergeProgress::wireFields()[$key]->field);
            }
            self::assertSame(['merge' => null], $browser->hideForViewer(
                ['merge' => null], ['merge' => WireField::each(AccountMergeProgress::wireFields())],
            ));
        } finally {
            Hilos::$db = $previous;
        }
    }

    public function testUnavailableDatabaseIsRetryableUnknownInsteadOfRememberedAbsence(): void
    {
        $previous = Hilos::$db;
        Hilos::$db = null;
        AccountMergeProgressResolver::forgetAll();
        try {
            foreach ([fn () => AccountMergeProgressResolver::of(7), fn () => AccountMergeProgressResolver::forOperation(3)] as $read) {
                try {
                    $read();
                    self::fail('A missing source must fail observation.');
                } catch (DatabaseException $failure) {
                    self::assertSame('Merge observation requires its database sources.', $failure->getMessage());
                }
            }
        } finally {
            AccountMergeProgressResolver::forgetAll();
            Hilos::$db = $previous;
        }
    }

    public function testOptionalParentDtoRoundtripsAndKeepsTheThreeArgumentConstructor(): void
    {
        $standing = new AccountStanding(AccountStandingKind::NONE, false, false, null, [], [])->toArray();
        $erasure = ['startedAt' => 1, 'parts' => [], 'waits' => [], 'refusals' => []];
        $previous = new AccountStandingStateSignalData(7, $standing, $erasure);
        self::assertNull($previous->accountMerge);
        self::assertSame([
            'userId' => 7, 'accountStanding' => $standing, 'accountErasure' => $erasure, 'accountMerge' => null,
        ], $previous->toArray());
        $oldWire = $previous->toArray();
        unset($oldWire['accountMerge']);
        self::assertSame($previous->toArray(), AccountStandingStateSignalData::fromArray($oldWire)->toArray());
        $full = new AccountStandingStateSignalData(7, $standing, $erasure, self::data());
        self::assertSame($full->toArray(), AccountStandingStateSignalData::fromJson($full->toJson())->toArray());
        self::assertSame(self::data(), $full->toArray()['accountMerge']);
        self::assertEquals(AccountMergeProgress::wireFields(), AccountStandingStateSignalData::wireFields()['accountMerge']->fields);
    }

    public function testOptionalParentDtoRejectsASuppliedNonArrayMerge(): void
    {
        $standing = new AccountStanding(AccountStandingKind::NONE, false, false, null, [], [])->toArray();
        $this->expectException(InvalidFormatException::class);
        AccountStandingStateSignalData::fromArray(['userId' => 7, 'accountStanding' => $standing, 'accountMerge' => 'private']);
    }

    /** @return array<string, mixed> Literal complete wire projection */
    private static function data(): array
    {
        return [
            'operationId' => 3, 'survivorUserId' => 7, 'loserUserId' => 9, 'state' => 'preparing',
            'stage' => 'preparing', 'outcome' => null, 'given' => false, 'received' => false,
            'choice' => ['passwordFate' => null, 'secondFactorFate' => null,
                'expectedSurvivorHasSecondFactor' => null, 'expectedLoserHasSecondFactor' => null],
            'refusal' => null,
            'parts' => [['participant' => 'prepare_survivor', 'owner' => HilosAgentType::HILOS_USER, 'userId' => 7, 'done' => false]],
            'waits' => [['participant' => 'project:notes', 'userId' => 9]],
        ];
    }
}
