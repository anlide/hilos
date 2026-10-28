<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalTriggerColumnTypes;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\ModerationRule;
use Hilos\Database\Schema\JournalColumnPolicy;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Moderation\DTO\ModerationRuleDeleteSignalData;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Pages\Moderation\AbstractHilosModerationKindRulesPage;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

/**
 * Production page → worker → single writer → page, including one real web receipt and raw SQL.
 * Uses the commit-capable lifecycle: rollback-scoped tests cannot release sync or prove commit.
 */
final class ModerationRuleDeleteIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_user', 'hilos_user_merge', 'hilos_account_deletion', 'hilos_moderation_rule'];
    private const array JOURNAL_TABLES = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];
    private const string ACCEPT = 'rule-admin';
    private const string RAW_RULE = "  <b>Не менять текст</b>\n ";

    private ?HilosDbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?SignalRouter $previousRouter = null;
    private ?SettingsAccessor $previousSettings = null;
    private ?BrowserContext $previousBrowser = null;
    private string $previousApp;
    /** @var array<string, mixed> Source bus restored after the isolated fixture */
    private array $previousBus = [];
    /** @var array<string, mixed> Account-standing memory of the previous context */
    private array $previousStanding = [];
    private ModerationRulesLibraryAgent $owner;
    private ModerationRuleDeleteWorker $worker;
    private PageSignalRouter $gatekeeper;

    /** Builds only existing table stubs and real journal triggers for the rules table. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousSettings = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        foreach (['mirrors', 'reactions', 'provenance'] as $property) {
            $this->previousBus[$property] = new ReflectionProperty(SourceChangeBus::class, $property)->getValue();
        }
        foreach (['standings', 'lapsedUserIds', 'epoch'] as $property) {
            $this->previousStanding[$property] = new ReflectionProperty(AccountStandingResolver::class, $property)->getValue();
        }
        new ReflectionProperty(AccountStandingResolver::class, 'standings')->setValue(null, []);
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ModerationRuleDeleteHilos::class);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        Hilos::$rt = null;
        Hilos::$setting = null;
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin) VALUES (7, 'Admin', 1), (5, 'Visitor', 0)");
        Database::sqlRun('INSERT INTO hilos_moderation_rule (id, kind, rule) VALUES (1, ?, ?), (2, ?, ?), (3, ?, ?)', [
            ModerationKindKey::PROFILE_NAME, self::RAW_RULE,
            ModerationKindKey::PROFILE_NAME, 'Second own rule.',
            ModerationKindKey::PROFILE_PHOTO, 'Photo policy.',
        ]);
        $primary = Database::getConnectionConfig(0);
        Database::configure(1, $primary->host, $primary->user, $primary->password,
            ChangeLogDatabase::configuredName(), $primary->port, $primary->charset);
        $this->dropJournalTables();
        Database::sqlRun(str_replace(JournalTriggerRenderer::DATABASE_TOKEN,
            ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName()),
            file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql')));
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new ModerationRuleDeleteDbContext();
        Hilos::$db->configure();
        $schema = LiveSchemaReader::read(0)['hilos_moderation_rule'];
        foreach (JournalTriggerRenderer::render($schema,
            JournalColumnPolicy::forTable(ModerationRule::class, $schema, PiiRegistry::collect()),
            JournalTriggerColumnTypes::read(0), 1) as $trigger) {
            Database::sqlRun($trigger->sql(ChangeLogDatabase::configuredName()));
        }
        ModerationRuleDeleteHilos::initBrowser(new IdentityTestBrowser(7, true));
        $this->owner = new ModerationRulesLibraryAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->worker = new ModerationRuleDeleteWorker($this->owner);
        $this->gatekeeper = ModerationRuleDeleteWorker::pageRouter(new ModerationRuleDeleteGatekeeperAgent());
        $this->drain();
    }

    /** Releases storage, source interests and process-global fixture state. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::setJournalReceiptId(null);
            foreach (array_reverse(self::TABLES) as $table) {
                $this->stub($table, true);
            }
            $this->dropJournalTables();
        } finally {
            TruthSourceRegistry::unregisterAgent($this->owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
            foreach ($this->previousBus as $property => $value) {
                new ReflectionProperty(SourceChangeBus::class, $property)->setValue(null, $value);
            }
            foreach ($this->previousStanding as $property => $value) {
                new ReflectionProperty(AccountStandingResolver::class, $property)->setValue(null, $value);
            }
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
            Hilos::$db = $this->previousDb;
            Hilos::$rt = $this->previousRt;
            Hilos::$sr = $this->previousRouter;
            Hilos::$setting = $this->previousSettings;
            Hilos::$browser = $this->previousBrowser;
            parent::tearDown();
        }
    }

    public function testCommittedDeleteKeepsTheWebReceiptOriginAndDeferredCorrelation(): void
    {
        $before = $this->rows();
        $ask = $this->forward(self::target());
        self::assertSame($before, $this->rows());
        self::assertInstanceOf(ModerationRuleDeleteSignalData::class, $ask->data->data);
        self::assertSame(1, $ask->data->data->id);
        self::assertSame(self::RAW_RULE, $ask->data->data->expectedRule);
        self::assertSame('Rule removed from "Profile name".', $ask->data->data->successMessage);
        $receiptId = $ask->data->receiptId;
        self::assertNotNull($receiptId);
        $this->worker->deliver($ask);
        $frames = $this->drain();
        $done = $this->doneFrame($frames);
        self::assertNull($done->data->data->error);
        self::assertSame($receiptId, $done->data->receiptId);
        self::assertSame(array_slice($before, 1), $this->rows());
        self::assertArrayNotHasKey(1, Hilos::$db->moderationRules);
        $deleted = array_values(array_filter($frames, static fn (SignalDTO $frame): bool => $frame->data instanceof DbSyncDeletedSignalData));
        self::assertCount(1, $deleted);
        self::assertSame(HilosDbContext::moderationRules, $deleted[0]->data->collectionKey);
        self::assertSame('1', $deleted[0]->data->idString);
        self::assertSame(self::RAW_RULE, $deleted[0]->data->row['rule']);
        self::assertSame(self::ACCEPT, $deleted[0]->data->origin);
        self::assertSame('rule-request', $deleted[0]->data->originRequestId);
        self::assertSame($receiptId, $deleted[0]->data->receiptId);
        self::assertSame($this->owner->getId(), $deleted[0]->data->writerAgentId);
        self::assertNull(ExecutionContext::currentAcceptKey());
        self::assertNull(ExecutionContext::currentRequestId());
        self::assertNull(JournalReceiptScope::currentId());
        self::assertFalse(Database::hasOpenTransactions());
        $this->worker->deliver($done);
        $acks = $this->drain();
        $this->assertAck($acks, true, 'rule-request');
        self::assertSame('Rule removed from "Profile name".', $acks[0]->data->data->message);
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $receipts = Database::sql("SELECT actor_user_id, channel, action, open_handovers FROM {$database}.hilos_change_log_receipt")->rows();
        self::assertSame([[
            'actor_user_id' => '7', 'channel' => 'web', 'action' => HilosSignalConstants::HILOS_MODERATION_RULE_DELETE, 'open_handovers' => '0',
        ]], $receipts);
        $entries = Database::sql("SELECT receipt_id FROM {$database}.hilos_change_log")->rows();
        self::assertSame([['receipt_id' => (string)$receiptId]], $entries);
    }

    public function testRemovingOneOwnRuleKeepsTheSecondAndOnlyTheLastRemovalRestoresDefaults(): void
    {
        $name = ModerationKindKey::PROFILE_NAME;
        $photo = ModerationKindKey::PROFILE_PHOTO;
        self::assertSame([self::RAW_RULE, 'Second own rule.'], Hilos::$db->moderationRules->effectiveForKind($name));
        $this->submit(self::target(), 'first');
        self::assertSame(['Second own rule.'], Hilos::$db->moderationRules->effectiveForKind($name));
        self::assertSame(['Photo policy.'], Hilos::$db->moderationRules->effectiveForKind($photo));
        $this->submit(['id' => 2, 'kind' => $name, 'expectedRule' => 'Second own rule.'], 'last');
        self::assertSame(Hilos::moderationKinds()[$name]->defaultRules, Hilos::$db->moderationRules->effectiveForKind($name));
        self::assertSame(['Photo policy.'], Hilos::$db->moderationRules->effectiveForKind($photo));
        self::assertSame([['id' => '3', 'kind' => $photo, 'rule' => 'Photo policy.']], $this->rows());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> Stale or mismatched captured targets */
    public static function refusedTargets(): iterable
    {
        yield 'unknown id' => [array_replace(self::target(), ['id' => 999999]), 'The rule no longer exists.'];
        yield 'mismatched kind' => [array_replace(self::target(), ['kind' => ModerationKindKey::PROFILE_PHOTO]),
            'The rule does not belong to this kind.'];
        yield 'changed expected text' => [array_replace(self::target(), ['expectedRule' => 'A former rule.']),
            'The rule changed. Close this window and confirm again.'];
        yield 'trimmed confirmation' => [array_replace(self::target(), ['expectedRule' => trim(self::RAW_RULE)]),
            'The rule changed. Close this window and confirm again.'];
    }

    /**
     * @param array<string, mixed> $payload Captured target that cannot be deleted
     * @param string $reason Domain refusal sentence
     */
    #[DataProvider('refusedTargets')]
    public function testWriterRefusesAStaleTargetWithoutSuccessOrMutation(array $payload, string $reason): void
    {
        $before = $this->rows();
        $this->worker->deliver($this->forward($payload));
        $frames = $this->drain();
        self::assertCount(1, $frames, 'No source deletion on a domain refusal');
        $done = $this->doneFrame($frames);
        self::assertSame($reason, $done->data->data->error);
        self::assertNull($done->data->data->errorType);
        self::assertNull($done->data->data->errorDetail);
        $this->worker->deliver($done);
        $acks = $this->drain();
        $this->assertAck($acks, false, 'rule-request');
        self::assertSame($reason, $acks[0]->data->data->reason);
        self::assertSame($before, $this->rows());
    }

    public function testUnknownKindIsRefusedAtBothEntrancesWithoutAWrite(): void
    {
        $before = $this->rows();
        $this->dispatch(array_replace(self::target(), ['kind' => 'unknown']));
        $acks = $this->drain();
        $this->assertAck($acks, false, 'rule-request');
        self::assertSame('Unknown moderation kind: unknown', $acks[0]->data->data->reason);
        $ask = $this->forward(self::target());
        $payload = array_replace($ask->data->data->toArray(), ['kind' => 'unknown']);
        $altered = new SignalDTO($ask->signalSource, $ask->signalType, $ask->signalName,
            new AgentSignalData(ModerationRuleDeleteSignalData::fromArray($payload), receiptId: $ask->data->receiptId));
        $this->worker->deliver(SignalDTO::fromArray($altered->toArray()));
        $frames = $this->drain();
        self::assertCount(1, $frames);
        $done = $this->doneFrame($frames);
        self::assertSame('Unknown moderation kind: unknown', $done->data->data->error);
        $this->worker->deliver($done);
        $this->assertAck($this->drain(), false, 'rule-request');
        self::assertSame($before, $this->rows());
    }

    public function testRepeatedDeleteRefusesRatherThanAcknowledgingAnotherSuccess(): void
    {
        $this->submit(self::target(), 'first');
        $before = $this->rows();
        $this->worker->deliver($this->forward(self::target(), 'repeat'));
        $frames = $this->drain();
        self::assertCount(1, $frames);
        $done = $this->doneFrame($frames);
        self::assertSame('The rule no longer exists.', $done->data->data->error);
        $this->worker->deliver($done);
        $this->assertAck($this->drain(), false, 'repeat');
        self::assertSame($before, $this->rows());
    }

    public function testDeleteRecreateWithTheSameKindAndTextDoesNotRetargetTheOldRequest(): void
    {
        $old = self::target();
        $this->submit($old, 'remove-original');
        $replacementId = ExecutionContext::run(new ExecutionFrame(agentId: $this->owner->getId()),
            fn () => JournalReceiptScope::run(JournalReceiptData::agent('fixture-create', $this->owner->getId()),
                fn () => Hilos::$db->moderationRules->actions->create($old['kind'], $old['expectedRule'])->id));
        self::assertNotSame($old['id'], $replacementId);
        $this->drain();
        $before = $this->rows();
        $this->worker->deliver($this->forward($old, 'stale'));
        $frames = $this->drain();
        self::assertCount(1, $frames);
        $done = $this->doneFrame($frames);
        self::assertSame('The rule no longer exists.', $done->data->data->error);
        $this->worker->deliver($done);
        $this->assertAck($this->drain(), false, 'stale');
        self::assertSame($before, $this->rows());
        self::assertArrayHasKey($replacementId, Hilos::$db->moderationRules);
        $this->submit(array_replace($old, ['id' => $replacementId]), 'fresh');
        self::assertSame(array_slice($before, 0, 2), $this->rows());
        self::assertArrayNotHasKey($replacementId, Hilos::$db->moderationRules);
    }

    public function testAnEditAfterConfirmationIsRefusedAndKeepsTheNewText(): void
    {
        $ask = $this->forward(self::target());
        ExecutionContext::run(new ExecutionFrame(agentId: $this->owner->getId()),
            fn () => JournalReceiptScope::run(JournalReceiptData::agent('fixture-update', $this->owner->getId()),
                fn () => Hilos::$db->moderationRules[1]->actions->update('Changed after confirmation.')));
        $this->drain();
        $before = $this->rows();
        $this->worker->deliver($ask);
        $frames = $this->drain();
        self::assertCount(1, $frames);
        $done = $this->doneFrame($frames);
        self::assertSame('The rule changed. Close this window and confirm again.', $done->data->data->error);
        $this->worker->deliver($done);
        $this->assertAck($this->drain(), false, 'rule-request');
        self::assertSame($before, $this->rows());
        self::assertSame('Changed after confirmation.', $before[0]['rule']);
    }

    /** @return iterable<string, array{bool}> Infrastructure and ownership refusals */
    public static function failedWrites(): iterable
    {
        yield 'database' => [true];
        yield 'ownership' => [false];
    }

    /** @param bool $databaseFailure Whether to refuse SQL rather than remove the writer's claim */
    #[DataProvider('failedWrites')]
    public function testWriteFailureReturnsDiagnosticsThroughTheGatekeeperWithoutFalseSuccess(bool $databaseFailure): void
    {
        $before = $this->rows();
        $ask = $this->forward(self::target());
        if ($databaseFailure) {
            Database::sqlRun("CREATE TRIGGER refuse_test_rule_delete BEFORE DELETE ON hilos_moderation_rule
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Private storage failure'");
        } else {
            TruthSourceRegistry::unregisterAgent($this->owner->getId());
        }
        $this->worker->deliver($ask);
        $frames = $this->drain();
        self::assertCount(1, $frames, 'No business source change after the failed write');
        $done = $this->doneFrame($frames);
        self::assertNotNull($done->data->data->error);
        self::assertNotNull($done->data->data->errorType);
        self::assertNotNull($done->data->data->errorDetail);
        self::assertNotSame($done->data->data->errorDetail, $done->data->data->error);
        $this->worker->deliver($done);
        $frames = $this->drain();
        $this->assertAck($frames, false, 'rule-request');
        self::assertSame($done->data->data->errorType, $frames[0]->data->data->errorType);
        self::assertSame($done->data->data->errorDetail, $frames[0]->data->data->errorDetail);
        self::assertSame($before, $this->rows());
        if ($databaseFailure) {
            Database::sqlRun('DROP TRIGGER refuse_test_rule_delete');
        }
    }

    public function testAnswerTransportFailureDoesNotReportTheCommittedWriteAsRefusedOrRepeatDelete(): void
    {
        $ask = $this->forward(self::target());
        $before = $this->rows();
        $router = Hilos::$sr;
        self::assertInstanceOf(ModerationRuleDeleteRouter::class, $router);
        $router->rejectAnswer = true;
        try {
            $this->worker->deliver($ask);
            self::fail('Transport failure must leave the write handler');
        } catch (RuntimeException $failure) {
            self::assertSame('Answer transport failed', $failure->getMessage());
        }
        self::assertSame(1, $router->answerAttempts);
        self::assertSame(array_slice($before, 1), $this->rows());
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertInstanceOf(DbSyncDeletedSignalData::class, $frames[0]->data);
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        self::assertSame(1, (int)Database::sql("SELECT COUNT(*) AS total FROM {$database}.hilos_change_log")->rows()[0]['total']);
    }

    public function testAnonymousAndNonAdminCannotForwardToTheWriter(): void
    {
        $before = $this->rows();
        foreach ([null, 5] as $userId) {
            ModerationRuleDeleteHilos::initBrowser(new IdentityTestBrowser($userId, false));
            $this->dispatch(self::target());
            $frames = $this->drain();
            $this->assertAck($frames, false, 'rule-request');
            self::assertSame($userId === null ? 'unauthorized' : 'forbidden', $frames[0]->data->data->errorCode);
        }
        self::assertSame($before, $this->rows());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_MODERATION_RULE_DELETE, ModerationRulesLibraryAgent::AGENT_ACTIONS);
        self::assertArrayNotHasKey(HilosSignalConstants::MODERATION_RULE_DELETE, ModerationRuleDeleteHilos::getActionAgentRoutes());
    }

    /** @return array{id: int, kind: string, expectedRule: string} Captured first own rule */
    private static function target(): array
    {
        return ['id' => 1, 'kind' => ModerationKindKey::PROFILE_NAME, 'expectedRule' => self::RAW_RULE];
    }

    /**
     * @param array<string, mixed> $payload Browser body
     * @param ?string $requestId Correlation, or null for an untracked action
     */
    private function dispatch(array $payload, ?string $requestId = 'rule-request'): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_INDEX),
            fn () => $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO(self::ACCEPT,
                HilosSignalConstants::HILOS_MODERATION_RULE_DELETE, $payload, $requestId), 'websocket'));
    }

    /**
     * @param array<string, mixed> $payload Browser body
     * @param string $requestId Original correlation
     * @return SignalDTO Single internal ask; no early browser ack
     */
    private function forward(array $payload, string $requestId = 'rule-request'): SignalDTO
    {
        $this->dispatch($payload, $requestId);
        $frames = $this->drain();
        self::assertCount(1, $frames, 'No success before the writer runs');
        self::assertSame(HilosSignalConstants::MODERATION_RULE_DELETE, $frames[0]->signalName->getName());
        $frame = SignalDTO::fromArray($frames[0]->toArray());
        $destinations = Hilos::$sr->getDestinations($frame);
        self::assertCount(1, $destinations);
        self::assertInstanceOf(AgentDestination::class, $destinations[0]);
        self::assertSame(HilosAgentType::HILOS_MODERATION_RULES_LIBRARY, $destinations[0]->agentType);
        self::assertNull($destinations[0]->agentIndex);
        return $frame;
    }

    /**
     * @param array<string, mixed> $payload Valid browser body
     * @param string $requestId Original correlation
     */
    private function submit(array $payload, string $requestId): void
    {
        $this->worker->deliver($this->forward($payload, $requestId));
        $done = $this->doneFrame($this->drain());
        self::assertNull($done->data->data->error);
        $this->worker->deliver($done);
        $this->assertAck($this->drain(), true, $requestId);
    }

    /**
     * @param list<SignalDTO> $frames Writer output
     * @return SignalDTO Page-owned answer after all sync announcements
     */
    private function doneFrame(array $frames): SignalDTO
    {
        $answers = array_values(array_filter($frames,
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE));
        self::assertCount(1, $answers);
        self::assertSame($answers[0], $frames[array_key_last($frames)]);
        self::assertInstanceOf(AgentSignalData::class, $answers[0]->data);
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answers[0]->data->data);
        foreach ($frames as $frame) {
            self::assertNotSame(SignalConstants::ACTION_SUCCESS, $frame->signalName->getName());
        }
        return SignalDTO::fromArray($answers[0]->toArray());
    }

    /**
     * @param list<SignalDTO> $frames Gatekeeper output
     * @param bool $success Expected result
     * @param string $requestId Original correlation
     */
    private function assertAck(array $frames, bool $success, string $requestId): void
    {
        self::assertCount(1, $frames);
        self::assertSame($success ? SignalConstants::ACTION_SUCCESS : SignalConstants::ACTION_ERROR, $frames[0]->signalName->getName());
        self::assertSame($requestId, $frames[0]->data->data->requestId);
        self::assertSame(HilosSignalConstants::HILOS_MODERATION_RULE_DELETE, $frames[0]->data->data->action);
    }

    /** @return list<array<string, ?string>> Independently read committed rows */
    private function rows(): array
    {
        return Database::sql('SELECT id, kind, rule FROM hilos_moderation_rule ORDER BY id')->rows();
    }

    /** @return list<SignalDTO> Business sync and replies, excluding the receipt's own bookkeeping sync */
    private function drain(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof DbSyncSignalDataInterface && $frame->data->collectionKey === HilosDbContext::changeLogReceipts) {
                continue;
            }
            $frames[] = $frame;
        }
        return $frames;
    }

    /**
     * @param string $table Existing stub table
     * @param bool $down Whether to drop it
     */
    private function stub(string $table, bool $down): void
    {
        $name = 'create_' . $table;
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }

    /** Drops only the isolated fixture's journal tables, after its source table and triggers. */
    private function dropJournalTables(): void
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sqlRun("DROP TABLE IF EXISTS {$database}.`{$table}`");
        }
    }
}

/** Real production declarations, with the existing framework name and photo kinds. */
abstract class ModerationRuleDeleteHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_MODERATION_RULES_LIBRARY => [AgentRegistryKey::WORKER => ModerationRulesLibraryAgent::class],
        HilosAgentType::HILOS_INDEX => [AgentRegistryKey::WORKER => ModerationRuleDeleteGatekeeperAgent::class],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => ModerationRuleDeleteGatekeeperAgent::class],
    ];
    public const array PAGES = [ModerationRuleDeletePage::PAGE => ModerationRuleDeletePage::class];
    protected const array FEATURES = [HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO, HilosFeature::CHANGE_LOG];
}

final class ModerationRuleDeleteDbContext extends HilosDbContext
{
}

/** No project forwarding implementation; the production base owns the action and DONE. */
final class ModerationRuleDeletePage extends AbstractHilosModerationKindRulesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class ModerationRuleDeleteGatekeeperAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /** No background fixture work. */
    public function onStop(): void
    {
    }
}

/** Runs the production worker dispatch, hydration, origin scope and receipt join in this process. */
final class ModerationRuleDeleteWorker extends WorkerManager
{
    /** @param ModerationRulesLibraryAgent $owner Real rules writer */
    public function __construct(ModerationRulesLibraryAgent $owner)
    {
        parent::__construct(1);
        $this->agentManager->addAgent($owner->getId(), $owner);
    }

    /** @param SignalDTO $frame Serialized ask or page-owned answer */
    public function deliver(SignalDTO $frame): void
    {
        $agentId = $frame->signalName->getName() === HilosSignalConstants::MODERATION_RULE_DELETE
            ? HilosAgentType::HILOS_MODERATION_RULES_LIBRARY : HilosAgentType::HILOS_INDEX;
        $dispatch = Closure::bind(static function (self $worker, string $agentId, SignalDTO $frame): void {
            $worker->handleAgentMessage(new DaemonAgentMessageDTO($agentId, $frame));
        }, null, WorkerManager::class);
        ExecutionContext::run(new ExecutionFrame(agentId: $agentId), fn () => $dispatch($this, $agentId, $frame));
    }

    /**
     * @param AgentInterface $agent Page-serving agent
     * @return PageSignalRouter Normal topology-driven gatekeeper
     */
    public static function pageRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new HilosPageFactory($agent, ModerationRuleDeleteHilos::class),
            new ActionRouteConfig(ModerationRuleDeleteHilos::getPageActionRoutes()),
            new SignalRouteConfig(ModerationRuleDeleteHilos::getPageSignalRoutes()));
    }

    /** @return SignalRouter Fixture router with production signal declarations */
    protected function createSignalRouter(): SignalRouter
    {
        return new ModerationRuleDeleteRouter();
    }

    /** @return AgentManager Registry of the page's serving agent */
    protected function createAgentManager(): AgentManager
    {
        $manager = new ModerationRuleDeleteAgentManager();
        $agent = new ModerationRuleDeleteGatekeeperAgent();
        $manager->addAgent($agent->getId(), $agent);
        return $manager;
    }

    /**
     * @param AgentInterface $agent Page-serving agent
     * @return PageSignalRouter Normal fixture page router
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return self::pageRouter($agent);
    }
}

final class ModerationRuleDeleteAgentManager extends AgentManager
{
    /**
     * @param string $agentType Requested fixture type
     * @param ?string $agentIndex Unused singleton index
     * @return AgentInterface Real rules owner
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new ModerationRulesLibraryAgent();
    }
}

final class ModerationRuleDeleteRouter extends SignalRouter
{
    public bool $rejectAnswer = false;
    public int $answerAttempts = 0;

    /**
     * @param SignalSourceInterface $signalSource Original source
     * @param SignalTypeInterface $signalType Original type
     * @param SignalNameInterface $signalName Original name
     * @param SignalDataInterface $signalData Original payload
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        if ($this->rejectAnswer && $signalName->getName() === HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE) {
            $this->answerAttempts++;
            throw new RuntimeException('Answer transport failed');
        }
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }

    /** @return class-string<Hilos> Isolated production declarations */
    protected function hilosClass(): string
    {
        return ModerationRuleDeleteHilos::class;
    }
}
