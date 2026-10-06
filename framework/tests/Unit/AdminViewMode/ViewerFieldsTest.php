<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\AdminViewMode;

use Closure;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the one walk that hides a fragment from a viewer of the admin view mode (HIL-1250).
 *
 * What is pinned is the rule every point of the wire leans on: nothing opened means hidden. A key the
 * declaration does not name is marked, a column is shown only when its verdict says so, a nested value
 * is walked by its own map, and the walk never adds a key the fragment did not carry.
 */
final class ViewerFieldsTest extends TestCase
{
    private const string USERS = 'users';

    public function testAKeyNobodyDeclaredIsHidden(): void
    {
        $hidden = ViewerFields::hide(['name' => 'Olena', 'age' => 31], [], self::columnsShown());

        $this->assertSame(['name' => HiddenValue::mark(), 'age' => HiddenValue::mark()], $hidden);
    }

    public function testAColumnIsShownOrHiddenByItsVerdict(): void
    {
        $hidden = ViewerFields::hide(
            ['name' => 'Olena', 'lastActivity' => '2026-09-29 10:00:00'],
            [
                'name' => WireField::column(self::USERS, 'name'),
                'lastActivity' => WireField::column(self::USERS, 'lastActivity'),
            ],
            self::columnsShown('lastActivity'),
        );

        $this->assertSame(['name' => HiddenValue::mark(), 'lastActivity' => '2026-09-29 10:00:00'], $hidden);
    }

    public function testTheVerdictIsAskedForTheDeclaredCollectionAndField(): void
    {
        $asked = [];
        ViewerFields::hide(
            ['label' => 'Olena'],
            ['label' => WireField::column(self::USERS, 'name')],
            static function (string $collection, string $field) use (&$asked): bool {
                $asked[] = [$collection, $field];

                return false;
            },
        );

        $this->assertSame([[self::USERS, 'name']], $asked);
    }

    public function testANotPersonalFieldIsShownAsItIs(): void
    {
        $hidden = ViewerFields::hide(
            ['online' => true, 'sessions' => null],
            ['online' => WireField::notPersonal(), 'sessions' => WireField::notPersonal()],
            self::columnsShown(),
        );

        $this->assertSame(['online' => true, 'sessions' => null], $hidden);
    }

    public function testANestedObjectIsWalkedByItsOwnMap(): void
    {
        $hidden = ViewerFields::hide(
            ['owner' => ['name' => 'Olena', 'online' => true, 'phone' => '+380']],
            ['owner' => WireField::each(['name' => WireField::column(self::USERS, 'name'), 'online' => WireField::notPersonal()])],
            self::columnsShown(),
        );

        $this->assertSame(
            ['owner' => ['name' => HiddenValue::mark(), 'online' => true, 'phone' => HiddenValue::mark()]],
            $hidden,
        );
    }

    public function testEachObjectOfANestedListIsWalkedByTheMap(): void
    {
        $hidden = ViewerFields::hide(
            ['members' => [['id' => 1, 'name' => 'Olena'], ['id' => 2, 'name' => 'Taras'], 'stray']],
            ['members' => WireField::each(['id' => WireField::column(self::USERS, 'id')])],
            self::columnsShown('id'),
        );

        $this->assertSame(
            ['members' => [
                ['id' => 1, 'name' => HiddenValue::mark()],
                ['id' => 2, 'name' => HiddenValue::mark()],
                HiddenValue::mark(),
            ]],
            $hidden,
        );
    }

    public function testAScalarWhereANestedValueWasDeclaredIsHidden(): void
    {
        $hidden = ViewerFields::hide(
            ['owner' => 'Olena', 'members' => null],
            ['owner' => WireField::each([]), 'members' => WireField::each([])],
            self::columnsShown(),
        );

        $this->assertSame(['owner' => HiddenValue::mark(), 'members' => HiddenValue::mark()], $hidden);
    }

    public function testANestingInsideANestingIsWalkedToTheBottom(): void
    {
        $hidden = ViewerFields::hide(
            ['room' => ['title' => 'Kyiv', 'owner' => ['name' => 'Olena', 'online' => false]]],
            ['room' => WireField::each([
                'title' => WireField::notPersonal(),
                'owner' => WireField::each(['online' => WireField::notPersonal()]),
            ])],
            self::columnsShown(),
        );

        $this->assertSame(
            ['room' => ['title' => 'Kyiv', 'owner' => ['name' => HiddenValue::mark(), 'online' => false]]],
            $hidden,
        );
    }

    public function testADeclaredKeyTheFragmentDoesNotCarryIsNotAdded(): void
    {
        $hidden = ViewerFields::hide(
            ['online' => true],
            ['online' => WireField::notPersonal(), 'name' => WireField::column(self::USERS, 'name')],
            self::columnsShown('name'),
        );

        $this->assertSame(['online' => true], $hidden);
    }

    public function testShownNamesAreTheTopLevelFieldsTheWalkWouldKeep(): void
    {
        $names = ViewerFields::shownNames(
            [
                'name' => WireField::column(self::USERS, 'name'),
                'lastActivity' => WireField::column(self::USERS, 'lastActivity'),
                'online' => WireField::notPersonal(),
                'members' => WireField::each(['id' => WireField::notPersonal()]),
            ],
            self::columnsShown('lastActivity'),
        );

        $this->assertSame(['lastActivity', 'online'], $names);
    }

    public function testASettingValueUsesItsOwnFragmentKeyAndFailsClosed(): void
    {
        $fields = [
            'key' => WireField::notPersonal(),
            'value' => WireField::settingFrom('key'),
            'defaultValue' => WireField::settingFrom('key'),
        ];
        $shown = static fn(string $key): bool => $key === 'open';

        $this->assertSame(
            ['key' => 'open', 'value' => 'yes', 'defaultValue' => 'default'],
            ViewerFields::hide(
                ['key' => 'open', 'value' => 'yes', 'defaultValue' => 'default'],
                $fields,
                self::columnsShown(),
                $shown,
            ),
        );
        foreach (['closed', 'orphan', ''] as $key) {
            $this->assertSame(
                ['key' => $key, 'value' => HiddenValue::mark()],
                ViewerFields::hide(['key' => $key, 'value' => 'secret'], $fields, self::columnsShown(), $shown),
            );
        }
        $this->assertSame(
            ['value' => HiddenValue::mark()],
            ViewerFields::hide(['value' => 'secret'], $fields, self::columnsShown(), $shown),
        );
    }

    public function testNestedSettingsUseEachElementAndAFailedResolverClosesOnlyItsValue(): void
    {
        $payload = ['rows' => [
            ['key' => 'open', 'value' => 'one'],
            ['key' => 'closed', 'value' => 'two'],
            ['key' => 'open', 'value' => 'three'],
        ]];
        $fields = ['rows' => WireField::each([
            'key' => WireField::notPersonal(),
            'value' => WireField::settingFrom(static function (array $row): string {
                if ($row['value'] === 'three') {
                    throw new RuntimeException('cannot classify');
                }

                return $row['key'];
            }),
        ])];

        $this->assertSame(
            ['rows' => [
                ['key' => 'open', 'value' => 'one'],
                ['key' => 'closed', 'value' => HiddenValue::mark()],
                ['key' => 'open', 'value' => HiddenValue::mark()],
            ]],
            ViewerFields::hide($payload, $fields, self::columnsShown(), static fn(string $key): bool => $key === 'open'),
        );
    }

    public function testACombinedSettingNeedsEveryKeyAndCannotJoinViewerSearchOrSort(): void
    {
        $fields = [
            'key' => WireField::notPersonal(),
            'value' => WireField::settingFrom('key'),
            'summary' => WireField::settings(['open', 'closed']),
            'fixed' => WireField::setting('open'),
        ];
        $payload = ['key' => 'open', 'value' => 'visible', 'summary' => 'mixed', 'fixed' => 'visible'];

        $this->assertSame(
            ['key' => 'open', 'value' => 'visible', 'summary' => HiddenValue::mark(), 'fixed' => 'visible'],
            ViewerFields::hide($payload, $fields, self::columnsShown(), static fn(string $key): bool => $key === 'open'),
        );
        $this->assertSame(['key'], ViewerFields::shownNames($fields, self::columnsShown()));
        $this->assertSame(
            ['key' => 'open', 'value' => HiddenValue::mark(), 'summary' => HiddenValue::mark(), 'fixed' => HiddenValue::mark()],
            ViewerFields::hide($payload, $fields, self::columnsShown()),
        );
    }

    public function testTheMarkIsTellableFromARealValue(): void
    {
        $this->assertSame([HiddenValue::KEY => true], HiddenValue::mark());
        $this->assertTrue(HiddenValue::isMark(HiddenValue::mark()));
        $this->assertFalse(HiddenValue::isMark([HiddenValue::KEY => false]));
        $this->assertFalse(HiddenValue::isMark([HiddenValue::KEY => true, 'name' => 'Olena']));
        $this->assertFalse(HiddenValue::isMark('_hidden'));
    }

    /**
     * @param string ...$shown Fields of the users collection whose column is shown
     * @return Closure(string, string): bool Column verdicts that open exactly the named fields of users
     */
    private static function columnsShown(string ...$shown): Closure
    {
        return static fn(string $collection, string $field): bool => $collection === self::USERS
            && in_array($field, $shown, true);
    }
}
