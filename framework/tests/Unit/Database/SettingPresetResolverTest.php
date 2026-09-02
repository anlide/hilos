<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Exception\SettingPresetIncompleteException;
use Hilos\Database\Settings\Exception\SettingPresetUnknownException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\Preset\SettingPreset;
use Hilos\Database\Settings\Preset\SettingPresetGroup;
use Hilos\Database\Settings\Preset\SettingPresetResolver;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the reading half of the preset mechanism (HIL-762).
 *
 * What is locked here is the pair of facts the screen is built on: the preset that was applied is
 * remembered as a name of its own, and the drift away from it is measured member by member. The
 * two are stored apart on purpose — derived from the values instead, the choice would vanish at
 * the first hand edit, which is precisely the state the cards exist to show.
 *
 * The write half is checked where writes are real — the chat demo drives apply() against a
 * database in its integration suite. What can be proven without one is proven here: a preset whose
 * value fails its rule is refused before the first write, and so is a name the group never
 * declared, and so is a member the recipe left without a value at all — the one a rule cannot
 * catch, since a key that declares none accepts anything. All three are shown by the exception
 * that arrives: with no table registered a write would have failed as a TableActionException, so
 * a refusal of any other kind means no write was reached.
 */
final class SettingPresetResolverTest extends TestCase
{
    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSetting = Hilos::$setting;
        SettingPresetTestAccessor::$values = [];
        Hilos::$setting = new SettingPresetTestAccessor(SettingPresetTestCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSetting;
        SettingPresetTestAccessor::$values = [];

        parent::tearDown();
    }

    public function testAStoredNameTheGroupDoesNotDeclareMeansNoPresetIsApplied(): void
    {
        SettingPresetTestAccessor::$values = [SettingPresetTestCatalog::SELECTION => 'retired'];

        $resolver = new SettingPresetResolver($this->group());

        $this->assertNull($resolver->selectedName());
        $this->assertSame([], $resolver->differences());
    }

    public function testTheAppliedPresetIsTheOneTheStoredNameNames(): void
    {
        SettingPresetTestAccessor::$values = [SettingPresetTestCatalog::SELECTION => SettingPresetTestCatalog::LOUD];

        $this->assertSame(SettingPresetTestCatalog::LOUD, new SettingPresetResolver($this->group())->selectedName());
    }

    public function testAPresetWhoseMembersAllMatchHasNoDifferences(): void
    {
        SettingPresetTestAccessor::$values = [
            SettingPresetTestCatalog::SELECTION => SettingPresetTestCatalog::QUIET,
            SettingPresetTestCatalog::LEVEL => 'WARNING',
            SettingPresetTestCatalog::SIZE => '100',
        ];

        $this->assertSame([], new SettingPresetResolver($this->group())->differences());
    }

    public function testOnlyTheDriftedMemberIsReportedAndInTheTypeOfItsKey(): void
    {
        SettingPresetTestAccessor::$values = [
            SettingPresetTestCatalog::SELECTION => SettingPresetTestCatalog::QUIET,
            SettingPresetTestCatalog::LEVEL => 'WARNING',
            SettingPresetTestCatalog::SIZE => '250',
        ];

        $differences = new SettingPresetResolver($this->group())->differences();

        $this->assertCount(1, $differences);
        $this->assertSame(SettingPresetTestCatalog::SIZE, $differences[0]->key);
        $this->assertSame(100, $differences[0]->presetValue);
        $this->assertSame(250, $differences[0]->currentValue);
    }

    public function testAValueItsRuleRefusesStopsTheApplyBeforeTheFirstWrite(): void
    {
        $this->assertNull(Hilos::$table, 'A registered table would make the refusal ambiguous');

        $this->expectException(SettingValueRefusedException::class);

        new SettingPresetResolver($this->group())->apply(SettingPresetTestCatalog::BROKEN);
    }

    public function testAMemberDeclaredWithoutAValueIsRefusedBeforeTheFirstWrite(): void
    {
        $this->assertNull(Hilos::$table, 'A registered table would make the refusal ambiguous');

        $this->expectException(SettingPresetIncompleteException::class);

        new SettingPresetResolver($this->group())->apply(SettingPresetTestCatalog::INCOMPLETE);
    }

    public function testANameTheGroupDoesNotDeclareIsRefusedAsUnknown(): void
    {
        $this->expectException(SettingPresetUnknownException::class);

        new SettingPresetResolver($this->group())->apply('retired');
    }

    public function testAPresetWhoseValuesAllPassReachesTheWritingStep(): void
    {
        $this->assertNull(Hilos::$table, 'The absence of a table is what the writing step reports');

        $this->expectException(TableActionException::class);

        new SettingPresetResolver($this->group())->apply(SettingPresetTestCatalog::LOUD);
    }

    /**
     * Builds the group the tests read: two honest presets and one carrying a refused value.
     *
     * @return SettingPresetGroup Group over the test catalog
     */
    private function group(): SettingPresetGroup
    {
        return new SettingPresetGroup(
            'test',
            SettingPresetTestCatalog::SELECTION,
            [
                new SettingPreset(SettingPresetTestCatalog::QUIET, [
                    SettingPresetTestCatalog::LEVEL => 'WARNING',
                    SettingPresetTestCatalog::SIZE => 100,
                ]),
                new SettingPreset(SettingPresetTestCatalog::LOUD, [
                    SettingPresetTestCatalog::LEVEL => 'DEBUG',
                    SettingPresetTestCatalog::SIZE => 200,
                ]),
                new SettingPreset(SettingPresetTestCatalog::BROKEN, [
                    SettingPresetTestCatalog::LEVEL => 'SHOUT',
                    SettingPresetTestCatalog::SIZE => 300,
                ]),
                new SettingPreset(SettingPresetTestCatalog::INCOMPLETE, [
                    SettingPresetTestCatalog::LEVEL => 'WARNING',
                    SettingPresetTestCatalog::SIZE => null,
                ]),
            ],
        );
    }
}

/**
 * Settings catalog the preset tests declare their keys in.
 */
final class SettingPresetTestCatalog implements CatalogProviderInterface
{
    /** Setting key the applied preset name is stored under. */
    public const string SELECTION = 'test.preset';

    /** Textual member, the one carrying a rule. */
    public const string LEVEL = 'test.level';

    /** Numeric member, the one proving the comparison is typed. */
    public const string SIZE = 'test.size';

    /** Preset every member of which equals the catalog defaults. */
    public const string QUIET = 'quiet';

    /** Preset differing from the defaults in both members. */
    public const string LOUD = 'loud';

    /** Preset naming a level its key's rule refuses. */
    public const string BROKEN = 'broken';

    /** Preset leaving a member without a value, on the key that declares no rule to catch it. */
    public const string INCOMPLETE = 'incomplete';

    /**
     * Returns the three keys the preset tests operate on.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::SELECTION => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::QUIET,
            ],
            self::LEVEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'WARNING',
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => SettingPresetTestLevelRule::class,
            ],
            self::SIZE => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 100,
            ],
        ];
    }
}

/**
 * Rule accepting the three level names the test catalog knows.
 */
final class SettingPresetTestLevelRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value is not one of the three names. */
    private const string REFUSAL = 'Value must be one of DEBUG, INFO, WARNING';

    /**
     * Checks that the value names one of the three levels.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        return in_array($value, ['DEBUG', 'INFO', 'WARNING'], true) ? null : self::REFUSAL;
    }
}

/**
 * Settings accessor answering with scripted persisted values instead of reading a database.
 */
final class SettingPresetTestAccessor extends SettingsAccessor
{
    /** @var array<string, mixed> Persisted value by key; a key without one falls back to the catalog default */
    public static array $values = [];

    /**
     * Returns the scripted value for a key, or the catalog default when none is scripted.
     *
     * @param string $key Setting key
     * @return mixed Scripted persisted value, or the resolved catalog default
     * @throws DatabaseException When the persisted setting lookup of the parent accessor fails
     * @throws SettingException When the key or its default reference is invalid
     */
    public function effectiveValueFor(string $key): mixed
    {
        return self::$values[$key] ?? parent::effectiveValueFor($key);
    }
}
