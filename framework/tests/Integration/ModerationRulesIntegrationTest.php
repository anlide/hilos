<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\ModerationRule as EntityModerationRule;
use Hilos\Database\Object\Item\ModerationRule as ObjectModerationRule;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use ReflectionProperty;

/**
 * Administrators' own moderation rules against the live framework table: the reader that says
 * which rules a check applies, and the three write doors under their owner (HIL-1624).
 */
final class ModerationRulesIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string TABLE = 'hilos_moderation_rule';
    private const string OWNER_AGENT_ID = 'test-agent:moderation-rules';
    private const string OUTSIDER_AGENT_ID = 'test-agent:outsider';

    private ?DbContext $previousDb = null;
    private ?SignalRouter $previousSignalRouter = null;
    private string $boundAppClass = Hilos::class;

    protected function setUp(): void
    {
        parent::setUp();
        self::runStub(down: true);
        self::runStub(down: false);

        $this->boundAppClass = Hilos::appClass();
        self::bindAppClass(ModerationRulesTestHilos::class);
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousDb = Hilos::$db;
        Hilos::$db = self::configuredContext();

        TruthSourceRegistry::register(HilosDbContext::moderationRules, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;
        self::bindAppClass($this->boundAppClass);
        self::runStub(down: true);
        parent::tearDown();
    }

    public function testWithoutOwnRulesEveryKindReadsItsBuiltInRules(): void
    {
        foreach (Hilos::moderationKinds() as $key => $kind) {
            $this->assertSame($kind->defaultRules, Hilos::$db->moderationRules->effectiveForKind($key), $key);
        }
        $this->assertSame([], self::rows());
    }

    public function testOwnRulesReplaceTheBuiltInRulesWholeInIdOrder(): void
    {
        $first = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Only first names.');
        $this->assertSame(['Only first names.'], Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));

        $second = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'No digits.');
        $third = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'No brand names.');

        $this->assertLessThan($second->id, $first->id);
        $this->assertLessThan($third->id, $second->id);
        $this->assertSame(
            ['Only first names.', 'No digits.', 'No brand names.'],
            Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME),
        );
        $this->assertSame(ModerationKindKey::PROFILE_NAME, $first->kind);
        $this->assertSame($first->id, Hilos::$db->moderationRules[$first->id]->id);
        $this->assertSame([
            [EntityModerationRule::id => $first->id, EntityModerationRule::kind => ModerationKindKey::PROFILE_NAME,
                EntityModerationRule::rule => 'Only first names.'],
            [EntityModerationRule::id => $second->id, EntityModerationRule::kind => ModerationKindKey::PROFILE_NAME,
                EntityModerationRule::rule => 'No digits.'],
            [EntityModerationRule::id => $third->id, EntityModerationRule::kind => ModerationKindKey::PROFILE_NAME,
                EntityModerationRule::rule => 'No brand names.'],
        ], self::rows());
    }

    public function testKindsKeepTheirOwnRulesApart(): void
    {
        Hilos::$db->moderationRules->actions->create(ModerationRulesTestDirectory::NOTE, 'Notes stay polite.');
        Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Names stay short.');
        Hilos::$db->moderationRules->actions->create(ModerationRulesTestDirectory::NOTE, 'Notes carry no links.');

        $this->assertSame(
            ['Notes stay polite.', 'Notes carry no links.'],
            Hilos::$db->moderationRules->effectiveForKind(ModerationRulesTestDirectory::NOTE),
        );
        $this->assertSame(['Names stay short.'], Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));
        $this->assertSame(
            Hilos::moderationKinds()[ModerationKindKey::PROFILE_PHOTO]->defaultRules,
            Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_PHOTO),
        );
    }

    public function testUpdateChangesTheNextReadAndKeepsTheKind(): void
    {
        $rule = Hilos::$db->moderationRules->actions->create(ModerationRulesTestDirectory::NOTE, 'Old text.');
        Hilos::$db->moderationRules->actions->create(ModerationRulesTestDirectory::NOTE, 'Second rule.');

        $rule->actions->update('New text.');

        $this->assertSame(['New text.', 'Second rule.'], Hilos::$db->moderationRules->effectiveForKind(ModerationRulesTestDirectory::NOTE));
        $this->assertSame(ModerationRulesTestDirectory::NOTE, $rule->kind);
        $this->assertSame('New text.', self::rows()[0][EntityModerationRule::rule]);
        $this->assertSame(ModerationRulesTestDirectory::NOTE, self::rows()[0][EntityModerationRule::kind]);
    }

    public function testDeletingTheLastOwnRuleBringsTheBuiltInRulesBack(): void
    {
        $first = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'First.');
        $second = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Second.');

        $first->actions->delete();
        $this->assertSame(['Second.'], Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));
        $this->assertArrayNotHasKey($first->id, Hilos::$db->moderationRules);

        $second->actions->delete();
        $this->assertSame(
            Hilos::moderationKinds()[ModerationKindKey::PROFILE_NAME]->defaultRules,
            Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME),
        );
        $this->assertArrayNotHasKey($second->id, Hilos::$db->moderationRules);
        $this->assertSame([], self::rows());
    }

    public function testUnknownKindIsRefusedByTheReaderAndTheCreateDoor(): void
    {
        try {
            Hilos::$db->moderationRules->effectiveForKind('no_such_kind');
            $this->fail('An unknown kind must not read as any rules');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Unknown moderation kind: no_such_kind', $error->getMessage());
        }

        try {
            Hilos::$db->moderationRules->actions->create('no_such_kind', 'A rule.');
            $this->fail('An unknown kind must not take a rule');
        } catch (ValidationException $error) {
            $this->assertSame('Unknown moderation kind: no_such_kind', $error->getMessage());
        }
        $this->assertSame([], self::rows());
    }

    public function testPhotoKindIsUnknownWhileTheProjectTakesNoProfilePhotos(): void
    {
        self::bindAppClass(ModerationRulesNoPhotoTestHilos::class);

        try {
            Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_PHOTO);
            $this->fail('A photo kind the directory does not publish must not read as any rules');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Unknown moderation kind: ' . ModerationKindKey::PROFILE_PHOTO, $error->getMessage());
        }
        try {
            Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_PHOTO, 'No selfies.');
            $this->fail('A photo kind the directory does not publish must not take a rule');
        } catch (ValidationException $error) {
            $this->assertSame('Unknown moderation kind: ' . ModerationKindKey::PROFILE_PHOTO, $error->getMessage());
        }
        $this->assertSame([], self::rows());
        $this->assertSame(
            Hilos::moderationKinds()[ModerationKindKey::PROFILE_NAME]->defaultRules,
            Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME),
        );
    }

    public function testRuleTextIsRefusedWhenBlankOrWiderThanTheColumn(): void
    {
        $rule = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Kept.');
        $tooWide = str_repeat('ж', intdiv(ObjectModerationRule::MAX_RULE_BYTES, 2)) . 'ab';
        $this->assertSame(ObjectModerationRule::MAX_RULE_BYTES + 1, strlen($tooWide));

        foreach (['', '   ', "\n\t ", $tooWide] as $text) {
            try {
                Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, $text);
                $this->fail('A blank or too wide rule must not be created');
            } catch (ValidationException) {
                $this->assertCount(1, self::rows());
            }
            try {
                $rule->actions->update($text);
                $this->fail('A blank or too wide rule must not replace the text');
            } catch (ValidationException) {
                $this->assertSame('Kept.', self::rows()[0][EntityModerationRule::rule]);
            }
        }
        $this->assertSame(['Kept.'], Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));
    }

    public function testUnicodeTextUpToTheColumnIsStoredAsGiven(): void
    {
        $widest = str_repeat('ж', intdiv(ObjectModerationRule::MAX_RULE_BYTES, 2)) . 'a';
        $this->assertSame(ObjectModerationRule::MAX_RULE_BYTES, strlen($widest));
        $spaced = "  Без оскорблений — 🚫 \n";

        $rule = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, $spaced);
        Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, $widest);

        $this->assertSame([$spaced, $widest], Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));
        $this->assertSame([$spaced, $widest], array_column(self::rows(), EntityModerationRule::rule));

        $rule->actions->update('Ünïcödé ✓');
        $this->assertSame('Ünïcödé ✓', self::rows()[0][EntityModerationRule::rule]);
    }

    public function testAnotherAgentCannotCreateUpdateOrDelete(): void
    {
        $rule = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Owned.');

        ExecutionContext::setCurrentAgentId(self::OUTSIDER_AGENT_ID);
        try {
            try {
                Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Foreign.');
                $this->fail('A foreign agent must not create a rule');
            } catch (CreateNotAllowedException) {
                $this->assertCount(1, self::rows());
            }
            try {
                $rule->actions->update('Foreign.');
                $this->fail('A foreign agent must not edit a rule');
            } catch (WriteNotAllowedException) {
                $this->assertSame('Owned.', self::rows()[0][EntityModerationRule::rule]);
            }
            try {
                $rule->actions->delete();
                $this->fail('A foreign agent must not delete a rule');
            } catch (WriteNotAllowedException) {
                $this->assertCount(1, self::rows());
            }
        } finally {
            ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        }
        $this->assertSame(['Owned.'], Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));
    }

    public function testSchemaAuditAndPersonalDataVerdictAcceptEveryColumn(): void
    {
        $this->assertSame([], EntitySchemaAudit::audit([EntityModerationRule::class]));

        $registry = PiiRegistry::collect();
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $schemas = LiveSchemaReader::read($index);
        AnonymizationCoverageValidator::validateLiveSchema($registry, [$index => [self::TABLE => $schemas[self::TABLE]]]);
        $this->assertSame([], $registry->strategiesFor($index, self::TABLE));
        $this->assertEqualsCanonicalizing(
            [EntityModerationRule::id, EntityModerationRule::kind, EntityModerationRule::rule],
            $registry->notPersonalColumns($index, self::TABLE),
        );
    }

    /**
     * Each door announces its write the ordinary way, and a reader in another context that applies
     * those announcements reads the change on its next call - no forget, no channel of its own.
     * The reader has a router of its own, as another process does, so its frames are not taken
     * for this process's own echo.
     */
    public function testAnotherContextFollowsTheAnnouncedWrites(): void
    {
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $writer = [Hilos::$db, Hilos::$sr];
        $reader = [self::configuredContext(), new SignalRouter()];
        $defaults = Hilos::moderationKinds()[ModerationKindKey::PROFILE_NAME]->defaultRules;
        [Hilos::$db, Hilos::$sr] = $reader;
        $this->assertSame($defaults, Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));

        [Hilos::$db, Hilos::$sr] = $writer;
        $first = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'First.');
        $second = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Second.');
        $created = self::drainDbFrames(SignalConstants::DB_SYNC_CREATED);
        $this->assertSame([(string)$first->id, (string)$second->id], array_map(
            static fn (DbSyncCreatedSignalData $frame): string => $frame->idString,
            $created,
        ));

        [Hilos::$db, Hilos::$sr] = $reader;
        // Taken in the opposite order, so the reader's own order cannot pass for the id order.
        foreach (array_reverse($created) as $frame) {
            DbSyncApplicator::applyCreated($frame, originNodeId: 'other-node');
        }
        $this->assertSame(['First.', 'Second.'], Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));

        [Hilos::$db, Hilos::$sr] = $writer;
        $first->actions->update('First, edited.');
        $updated = self::drainDbFrames(SignalConstants::DB_SYNC_UPDATED);
        $this->assertCount(1, $updated);
        [Hilos::$db, Hilos::$sr] = $reader;
        DbSyncApplicator::applyUpdated($updated[0], originNodeId: 'other-node');
        $this->assertSame(
            ['First, edited.', 'Second.'],
            Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME),
        );

        [Hilos::$db, Hilos::$sr] = $writer;
        $first->actions->delete();
        $second->actions->delete();
        $deleted = self::drainDbFrames(SignalConstants::DB_SYNC_DELETED);
        $this->assertCount(2, $deleted);
        [Hilos::$db, Hilos::$sr] = $reader;
        foreach ($deleted as $frame) {
            DbSyncApplicator::applyDeleted($frame, originNodeId: 'other-node');
        }
        $this->assertSame($defaults, Hilos::$db->moderationRules->effectiveForKind(ModerationKindKey::PROFILE_NAME));
        [Hilos::$db, Hilos::$sr] = $writer;
    }

    /**
     * Drains the signal queue and returns the DB-sync frames in it, each of the one expected type.
     *
     * @param string $type DB-sync signal every frame must carry
     * @return list<DbSyncCreatedSignalData|DbSyncUpdatedSignalData|DbSyncDeletedSignalData> Frames in queue order
     */
    private static function drainDbFrames(string $type): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalSource->getSource() !== SignalSource::DB) {
                continue;
            }
            self::assertSame($type, $signal->signalType->getType());
            self::assertSame(HilosDbContext::moderationRules, $signal->data->collectionKey);
            $frames[] = $signal->data;
        }

        return $frames;
    }

    /** @return list<array<string, mixed>> Persisted rules by id, read past the ORM */
    private static function rows(): array
    {
        Database::sql('SELECT `id`, `kind`, `rule` FROM `' . self::TABLE . '` ORDER BY `id`');

        return array_map(static fn (array $row): array => [
            EntityModerationRule::id => (int)$row['id'],
            EntityModerationRule::kind => $row['kind'],
            EntityModerationRule::rule => $row['rule'],
        ], Database::rows());
    }

    /** @return ModerationRulesTestDbContext A freshly configured framework context */
    private static function configuredContext(): ModerationRulesTestDbContext
    {
        $context = new ModerationRulesTestDbContext();
        $context->configure();

        return $context;
    }

    /** @param bool $down Drop the table when true, create it from the stub when false */
    private static function runStub(bool $down): void
    {
        // external-boundary: the up stub carries no suffix.
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . self::TABLE . "{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
    }

    /** @param class-string<Hilos> $hilosClass Facade class to bind as the app class */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/** The framework's collections with no project extension. */
final class ModerationRulesTestDbContext extends HilosDbContext
{
}

/** A project directory appending one text kind of its own after the framework's. */
final class ModerationRulesTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'test_note';

    /** @return list<ModerationKind> Framework kinds followed by the project's note */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Note', ModerationInput::TEXT, ['rude'], ['Notes are allowed.'], 'test_note_owner'),
        ];
    }
}

/**
 * A project that takes profile photos and declares its own kind; moderation itself stays off,
 * since the kinds are read whether or not it is on.
 */
abstract class ModerationRulesTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::PROFILE_PHOTO];

    protected const string MODERATION_KIND_DIRECTORY = ModerationRulesTestDirectory::class;
}

/** The same project without profile photos. */
abstract class ModerationRulesNoPhotoTestHilos extends Hilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationRulesTestDirectory::class;
}
