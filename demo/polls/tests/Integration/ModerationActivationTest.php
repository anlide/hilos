<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Database\Database;
use Demo\Polls\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;

/** Mounts polls before the managed rollback scope and executes actions under their declared framework owner. */
abstract class ModerationActivationEnvironment extends RollbackIntegrationTestCase
{
    private ?ModerationRulesLibraryAgent $owner = null;
    private ?int $executionToken = null;

    /** Uses the migrated test database without taking over an already mounted project context. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        $this->owner = new ModerationRulesLibraryAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->owner->getId()));
    }

    /** Releases the real owner's claim and restores the caller after the harness rolls back. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->owner !== null) {
            TruthSourceRegistry::unregisterAgent($this->owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
            $this->owner = null;
        }
        if ($this->executionToken !== null) {
            ExecutionContext::pop($this->executionToken);
            $this->executionToken = null;
        }
    }
}

/** The real polls schema and framework actions; the rollback scope proves writes, not transport or commit. */
#[IntegrationTest]
final class ModerationActivationTest extends ModerationActivationEnvironment
{
    /** The selected framework unit is present in the migrated project database. */
    public function testSelectedSchemaIncludesTheActualRulesTable(): void
    {
        $units = array_column((new FrameworkSchemaCatalog())->selectedUnits(Hilos::class), null, 'key');
        self::assertSame(['hilos_moderation_rule'], $units['moderation_rule']->tables);
        Database::sql('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', ['hilos_moderation_rule']);
        self::assertSame(['id', 'kind', 'rule'], array_column(Database::rows(), 'COLUMN_NAME'));
    }

    /** Both published kinds use the real owner-bound actions and fall back to built-in rules after deletion. */
    public function testFrameworkOwnerCanCreateReadUpdateAndDeleteRulesForBothKinds(): void
    {
        foreach ([ModerationKindKey::PROFILE_NAME, ModerationKindKey::PROFILE_PHOTO] as $kind) {
            $defaults = Hilos::moderationKinds()[$kind]->defaultRules;
            self::assertSame($defaults, Hilos::$db->moderationRules->effectiveForKind($kind));
            $text = "  Allow ordinary content.\nKeep the original whitespace.  ";
            $rule = Hilos::$db->moderationRules->actions->create($kind, $text);
            $id = $rule->id;
            self::assertGreaterThan(0, $id);
            self::assertSame($kind, Hilos::$db->moderationRules[$id]->kind);
            self::assertSame([$text], Hilos::$db->moderationRules->effectiveForKind($kind));
            Database::sql('SELECT kind, rule FROM hilos_moderation_rule WHERE id = ?', [$id]);
            self::assertSame([['kind' => $kind, 'rule' => $text]], Database::rows());

            $updated = 'Allow ordinary content and refuse threats.';
            Hilos::$db->moderationRules[$id]->actions->update($updated);
            self::assertSame([$updated], Hilos::$db->moderationRules->effectiveForKind($kind));
            Database::sql('SELECT kind, rule FROM hilos_moderation_rule WHERE id = ?', [$id]);
            self::assertSame([['kind' => $kind, 'rule' => $updated]], Database::rows());

            Hilos::$db->moderationRules[$id]->actions->delete();
            Database::sql('SELECT id FROM hilos_moderation_rule WHERE id = ?', [$id]);
            self::assertSame([], Database::rows());
            self::assertFalse(isset(Hilos::$db->moderationRules[$id]));
            self::assertSame($defaults, Hilos::$db->moderationRules->effectiveForKind($kind));
        }
    }
}
