<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Tables\Moderation\HilosModerationKindTableRow;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use Hilos\Tables\Moderation\HilosModerationRuleTableRow;

/**
 * The chat's own moderation rules in the framework table `hilos_moderation_rule` (HIL-1624): the
 * fresh seed that carries the rules of seeds 002 and 005 over, and the journal of the table through
 * its generated trigger files, written by the one library that owns it.
 */
final class FrameworkModerationRulesSeedTest extends IntegrationTestCase
{
    private const string TABLE = 'hilos_moderation_rule';

    private const string SEED = '006_framework_moderation_rules';

    /** Message rules exactly as seed 002 writes them into the old table, in its order. */
    private const array MESSAGE_RULES = [
        'Allow benign. Block: insults, threats, hate speech, sexual, spam. Don\'t block greetings, names, neutral.',
        'Explicit text only; no inference. Uncertain→allow. Attachments never cause a block.',
        'Reasons: ok, insult, threat, hate_speech, sexual, spam.',
    ];

    /** Name rules exactly as seed 005 writes them into the old table, in its order. */
    private const array NAME_RULES = [
        'Allow benign display names. Block: insults, threats, hate speech, sexual, spam, impersonation.',
        'Explicit text only; no inference. Uncertain→allow.',
        'Reasons: ok, insult, threat, hate_speech, sexual, spam, impersonation.',
    ];

    /** Sections of the old table the two kinds were seeded from. */
    private const string OLD_MESSAGE_SECTION = 'message_rule';
    private const string OLD_NAME_SECTION = 'name_rule';

    /** A rule of a third kind the seed must leave alone. */
    private const string PICTURE_RULE = 'Seed fixture: a picture rule of the administrator.';

    /** Journal tables in the order their rows are removed, children before parents. */
    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    private const array TRIGGERS = [
        'hilos_cl_hilos_moderation_rule_after_delete',
        'hilos_cl_hilos_moderation_rule_after_insert',
        'hilos_cl_hilos_moderation_rule_after_update',
    ];

    private const string ACTION = 'moderation_rule.crud';
    private const int ADMIN_ID = 1624;
    private const int ADMIN_SESSION_ID = 1624;
    private const string FIRST_TEXT = 'Journal fixture: block obvious spam.';
    private const string SECOND_TEXT = 'Journal fixture: block obvious spam and flooding.';

    private ?string $previousSeedPath = null;

    private ModerationRulesLibraryAgent $library;

    /** @var list<array<string, mixed>> Triggers present before the case */
    private array $originalTriggers = [];

    /** @var array<string, int> Journal high water marks before the case */
    private array $journalBaseline = [];

    private ?int $ruleId = null;

    public function testSeedGivesThreeMessageAndThreeNameRulesAndNothingForPictures(): void
    {
        $oldPieces = self::oldPieces();

        Seed::applyOne(self::SEED);

        $this->assertSame(self::seededRows(), self::ruleRows());
        $this->assertSame([ChatModerationKindKey::MESSAGE, ModerationKindKey::PROFILE_NAME], self::kinds());
        // The old table and its readers keep their rows, and the new rows are their texts word for word.
        $this->assertSame($oldPieces, self::oldPieces());
        $this->assertSame([
            self::OLD_MESSAGE_SECTION => self::MESSAGE_RULES,
            self::OLD_NAME_SECTION => self::NAME_RULES,
        ], $oldPieces);

        $expectedRules = [
            ModerationKindKey::PROFILE_NAME => self::NAME_RULES,
            ModerationKindKey::PROFILE_PHOTO => [],
            ChatModerationKindKey::MESSAGE => self::MESSAGE_RULES,
            ChatModerationKindKey::MESSAGE_PICTURE => [],
        ];
        $kindRows = Hilos::$table->hilosModerationKinds->getFullSnapshot()->rows;
        $this->assertSame(
            array_keys($expectedRules),
            array_map(static fn (HilosModerationKindTableRow $row): string => $row->kind, $kindRows),
        );
        foreach ($kindRows as $kindRow) {
            $rules = $expectedRules[$kindRow->kind];
            $this->assertSame(count($rules), $kindRow->ownRuleCount);
            $ruleRows = Hilos::$table->hilosModerationRules->getPage(new TableQueryDTO(
                filter: [HilosModerationRulesTable::FILTER_KIND => $kindRow->kind],
            ))->rows;
            $this->assertSame(
                $rules,
                array_map(static fn (HilosModerationRuleTableRow $row): string => $row->rule, $ruleRows),
            );
        }
    }

    public function testRepeatedSeedNeitherDuplicatesNorErasesAThirdKind(): void
    {
        $oldPieces = self::oldPieces();
        Seed::applyOne(self::SEED);
        Database::sqlRun('INSERT INTO `hilos_moderation_rule` (`kind`, `rule`) VALUES (?, ?)',
            [ChatModerationKindKey::MESSAGE_PICTURE, self::PICTURE_RULE]);
        Database::sql('SELECT LAST_INSERT_ID() AS `id`');
        $pictureId = (int)Database::field('id');

        Seed::applyOne(self::SEED);

        // The repeat writes its six rows anew, after the picture rule that kept its place.
        $this->assertSame([[ChatModerationKindKey::MESSAGE_PICTURE, self::PICTURE_RULE], ...self::seededRows()], self::ruleRows());
        Database::sql('SELECT `kind`, `rule` FROM `hilos_moderation_rule` WHERE `id` = ?', [$pictureId]);
        $this->assertSame(['kind' => ChatModerationKindKey::MESSAGE_PICTURE, 'rule' => self::PICTURE_RULE], Database::row());
        $this->assertSame($oldPieces, self::oldPieces());
    }

    public function testLibraryWritesAreJournaledByTheGeneratedTriggersUnderTheirReceipt(): void
    {
        JournalTriggerInstaller::apply();
        Database::sql('SELECT `TRIGGER_NAME` FROM information_schema.TRIGGERS'
            . ' WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME', [self::TABLE]);
        $this->assertSame(self::TRIGGERS, array_column(Database::rows(), 'TRIGGER_NAME'));

        $receiptId = $this->underAgent($this->library, fn (): ?int => JournalReceiptScope::run(
            JournalReceiptData::web(self::ADMIN_ID, null, self::ADMIN_SESSION_ID, self::ACTION, $this->library->getId()),
            function (): ?int {
                $this->ruleId = (int)Hilos::$db->moderationRules->actions->create(ChatModerationKindKey::MESSAGE, self::FIRST_TEXT)->id;
                Hilos::$db->moderationRules[$this->ruleId]->actions->update(self::SECOND_TEXT);
                Hilos::$db->moderationRules[$this->ruleId]->actions->delete();

                return JournalReceiptScope::currentId();
            },
        ));

        $this->assertNotNull($receiptId);
        $history = Hilos::$db->changeLogEntries->historyWindow(self::TABLE, new ChangeLogHistoryFilter(recordKey: [$this->ruleId]),
            false, null, TableAnchorDirection::After, 10);
        $this->assertSame(['create', 'update', 'delete'], array_map(static fn ($row): string => $row->entry->mutation, $history->toArray()));
        foreach ($history as $row) {
            $this->assertSame($receiptId, $row->entry->receiptId);
            $this->assertSame('web', $row->receipt->channel);
            $this->assertSame(self::ACTION, $row->receipt->action);
            $this->assertSame([$this->ruleId], $row->entry->recordKey);
        }
        $this->assertSame([], $history->get(0)->entry->changes);

        $update = Hilos::$db->changeLogEntries->sectionEntry($history->get(1)->entry->id);
        $this->assertNotNull($update);
        $this->assertCount(1, $update->changes);
        $changed = $update->changes[0];
        $this->assertSame('rule', $changed->field);
        $this->assertTrue($changed->oldPresent);
        $this->assertTrue($changed->newPresent);
        $this->assertSame(self::FIRST_TEXT, $changed->oldValue);
        $this->assertSame(self::SECOND_TEXT, $changed->newValue);

        $delete = Hilos::$db->changeLogEntries->sectionEntry($history->get(2)->entry->id);
        $this->assertNotNull($delete);
        $snapshot = [];
        foreach ($delete->changes as $change) {
            $this->assertTrue($change->oldPresent);
            $this->assertFalse($change->newPresent);
            $this->assertNull($change->newValue);
            $snapshot[$change->field] = $change->oldValue;
        }
        $this->assertSame(['kind' => ChatModerationKindKey::MESSAGE, 'rule' => self::SECOND_TEXT], $snapshot);
    }

    /**
     * Points the seed and the journal at this project's files, remembers what the schema and the journal held,
     * and starts the library that owns the table the way a node does.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->previousSeedPath = Seed::getSeedPath();
        Seed::setSeedPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Seed');
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        Database::sql('SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
        $this->originalTriggers = Database::rows();
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$journal}.`{$table}`");
            $this->journalBaseline[$table] = (int)Database::field('id');
        }
        $this->library = new ModerationRulesLibraryAgent();
        $this->startAgent($this->library);
    }

    /**
     * Gives the triggers and the journal back as the case found them, leaves the table in its seeded
     * state, and releases the library.
     */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        Database::sql('SELECT `TRIGGER_NAME` FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
        foreach (Database::rows() as $trigger) {
            Database::sql('DROP TRIGGER `' . $trigger['TRIGGER_NAME'] . '`');
        }
        if ($this->ruleId !== null && isset(Hilos::$db->moderationRules[$this->ruleId])) {
            $this->underAgent($this->library, fn () => Hilos::$db->moderationRules[$this->ruleId]->actions->delete());
        }
        Database::sqlRun('DELETE FROM `hilos_moderation_rule` WHERE `kind` = ?', [ChatModerationKindKey::MESSAGE_PICTURE]);
        Seed::applyOne(self::SEED);
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sqlRun("DELETE FROM {$journal}.`{$table}` WHERE `id` > ?", [$this->journalBaseline[$table]]);
        }
        foreach ($this->originalTriggers as $trigger) {
            Database::sql('CREATE TRIGGER `' . $trigger['TRIGGER_NAME'] . '` ' . $trigger['ACTION_TIMING']
                . ' ' . $trigger['EVENT_MANIPULATION'] . ' ON `' . $trigger['EVENT_OBJECT_TABLE']
                . '` FOR EACH ROW ' . $trigger['ACTION_STATEMENT']);
        }
        if ($this->previousSeedPath !== null) {
            Seed::setSeedPath($this->previousSeedPath);
        }
        $this->library->onStop();
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
        parent::tearDown();
    }

    /** @return list<array{string, string}> Kind and text of every row the seed writes, in its order */
    private static function seededRows(): array
    {
        return [
            ...array_map(static fn (string $rule): array => [ChatModerationKindKey::MESSAGE, $rule], self::MESSAGE_RULES),
            ...array_map(static fn (string $rule): array => [ModerationKindKey::PROFILE_NAME, $rule], self::NAME_RULES),
        ];
    }

    /** @return list<array{string, string}> Kind and text of every rule in the table, by id */
    private static function ruleRows(): array
    {
        Database::sql('SELECT `kind`, `rule` FROM `hilos_moderation_rule` ORDER BY `id`');

        return array_map(static fn (array $row): array => [$row['kind'], $row['rule']], Database::rows());
    }

    /** @return list<string> Kinds the table holds rules of, in key order */
    private static function kinds(): array
    {
        Database::sql('SELECT DISTINCT `kind` FROM `hilos_moderation_rule` ORDER BY `kind`');

        return array_column(Database::rows(), 'kind');
    }

    /** @return array<string, list<string>> Texts of the old table's two rule sections, by id */
    private static function oldPieces(): array
    {
        $pieces = [self::OLD_MESSAGE_SECTION => [], self::OLD_NAME_SECTION => []];
        Database::sql('SELECT `section`, `prompt_piece` FROM `moderator_prompt_piece` WHERE `section` IN (?, ?) ORDER BY `id`',
            [self::OLD_MESSAGE_SECTION, self::OLD_NAME_SECTION]);
        foreach (Database::rows() as $row) {
            $pieces[$row['section']][] = $row['prompt_piece'];
        }

        return $pieces;
    }
}
