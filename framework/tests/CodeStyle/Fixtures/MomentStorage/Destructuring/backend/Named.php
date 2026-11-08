<?php

declare(strict_types=1);

namespace MomentFixtures;

use Hilos\Database\Database;
use Hilos\Utils\Helpers\TimeHelper;
use MomentFixtures\EventCollection;
use MomentFixtures\EventEntity;
use MomentFixtures\EventObject;
use stdClass;

class NamedDestructuringWriter
{
    public function bracketsFirst(EventEntity $entity): void
    {
        $when = time();
        in_array(haystack: [$when] = [TimeHelper::nowMs()], needle: 0);
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
        $when = time();
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
        $entity->at = time();
        Database::sql('UPDATE event SET at = ?', [time()]);
    }

    public function listsLater(EventEntity $entity): void
    {
        $when = time();
        in_array(needle: 0, haystack: list($when) = [TimeHelper::nowMs()]);
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
        $when = time();
        $entity->at = $when;
    }

    public function bracketsLater(EventEntity $entity): void
    {
        $when = time();
        in_array(needle: 0, haystack: [$when] = [TimeHelper::nowMs()]);
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
    }

    public function listsFirst(EventEntity $entity): void
    {
        $when = time();
        in_array(haystack: list($when) = [TimeHelper::nowMs()], needle: 0);
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
    }

    public function nestedKeyed(EventEntity $entity): void
    {
        $key = time();
        $first = time();
        $second = time();
        in_array(haystack: [$key => $first, 'nested' => ['at' => $second]] = [0 => 0, 'nested' => ['at' => 0]], needle: 0);
        $entity->at = $first;
        Database::sql('UPDATE event SET at = ?', [$second]);
        $entity->created_at = $key;
    }

    public function nestedLists(EventEntity $entity): void
    {
        $first = time();
        $second = time();
        in_array(needle: 0, haystack: list($first, list('at' => $second)) = [0, ['at' => 0]]);
        $entity->at = $first;
        Database::sql('UPDATE event SET created_at = ?', [$second]);
    }

    public function firstBindings(EventEntity $entity): void
    {
        in_array(haystack: [$first] = [0], needle: 0);
        $first = time();
        $entity->at = $first;
        in_array(needle: 0, haystack: list($second) = [0]);
        $second = time();
        Database::sql('UPDATE event SET created_at = ?', [$second]);
    }

    public function readsAndUnrelated(EventEntity $entity): void
    {
        $when = time();
        $other = time();
        in_array(needle: $when, haystack: [$when]);
        in_array(needle: 0, haystack: [$other] = [TimeHelper::nowMs()]);
        in_array(haystack: [$row[$when], $entity->duration] = [0, 0], needle: 0);
        $entity->at = $when;
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
    }

    public function directRebinding(EventEntity $entity): void
    {
        $first = time();
        $second = time();
        in_array(needle: $first = TimeHelper::nowMs(), haystack: []);
        in_array(haystack: [], needle: $second = TimeHelper::nowMs());
        $entity->at = $first;
        Database::sql('UPDATE event SET at = ?', [$second]);
        $first = time();
        $entity->at = $first;
    }

    public function directSinks(EventEntity $entity, EventObject $object, $unknown): void
    {
        in_array(needle: $entity->at = time(), haystack: []);
        in_array(haystack: [], needle: $entity->created_at = time());
        in_array(needle: $object->happenedAt = time(), haystack: []);
        in_array(haystack: [], needle: $entity['at'] = time());
        in_array(needle: $unknown->happenedAt = time(), haystack: []);
    }

    public function receivers(EventEntity $entity, EventObject $object, $unknown): void
    {
        in_array(needle: 0, haystack: [$entity] = [$unknown]);
        $entity->at = time();
        in_array(haystack: list($object) = [$unknown], needle: 0);
        $object->happenedAt = time();
        $objectClass = EventCollection::OBJECT_CLASS;
        in_array(needle: 0, haystack: [$objectClass] = [$unknown]);
        $created = $objectClass::create();
        $created->happenedAt = time();
        in_array(haystack: list($nextClass) = [$unknown], needle: 0);
        $nextClass = EventCollection::OBJECT_CLASS;
        $next = $nextClass::create();
        $next->happenedAt = time();
    }

    public function keywordBracketsFirst(EventEntity $entity, stdClass $replacement): void
    {
        $this->accept(default: [$entity] = [$replacement]);
        $entity->at = time();
    }

    public function keywordListsLater(EventEntity $entity, stdClass $replacement): void
    {
        $this->accept(first: 0, default: list($entity) = [$replacement]);
        $entity->at = time();
    }

    public function keywordBracketsLater(EventEntity $entity, stdClass $replacement): void
    {
        $this->accept(first: 0, default: [$entity] = [$replacement]);
        $entity->at = time();
    }

    public function keywordListsFirst(EventEntity $entity, stdClass $replacement): void
    {
        $this->accept(default: list($entity) = [$replacement]);
        $entity->at = time();
    }

    public function keywordNativeRebindings(
        EventEntity $first, EventEntity $second, EventEntity $third, EventEntity $fourth, stdClass $replacement,
    ): void {
        $this->accept(return: [$first] = [$replacement]);
        $first->at = time();
        $this->accept(first: 0, match: list($second) = [$replacement]);
        $second->at = time();
        $this->accept(function: [$third] = [$replacement]);
        $third->at = time();
        $this->accept(first: 0, class: list($fourth) = [$replacement]);
        $fourth->at = time();
    }

    public function keywordDirectSinks(EventEntity $entity, $unknown): void
    {
        $this->accept(default: $entity->at = time());
        $this->accept(first: 0, default: $entity->created_at = TimeHelper::nowMs());
        $this->accept(return: $entity->at = time());
        $this->accept(first: 0, match: $entity->at = time());
        $this->accept(function: $entity->at = time());
        $this->accept(first: 0, class: $entity->at = time());
        $this->accept(fn: $entity->at = time());
        $this->accept(first: 0, if: $entity->at = time());
        $this->accept(catch: $entity->at = time());
        $this->accept(first: 0, foreach: $entity->at = time());
        $this->accept(case: $entity->at = time());
        $this->accept(first: 0, throw: $entity->at = time());
        $this->accept(namespace: $entity->at = time());
        $this->accept(first: 0, été: $entity->at = time());
        $this->accept(default: $unknown->at = time());
    }

    public function keywordProofReads(EventEntity $entity): void
    {
        $when = date(format: 'Y-m-d H:i:s');
        $other = gmdate(format: 'Y-m-d H:i:s');
        $read = time();
        $local = new EventEntity();
        $objectClass = EventCollection::OBJECT_CLASS;
        $object = $objectClass::create();
        $this->accept(default: [$read], return: $when, match: $other, function: $local, class: $objectClass, fn: $object);
        $entity->at = $when;
        $entity->at = $when;
        $entity->created_at = $other;
        $local->at = $read;
        $next = $objectClass::create();
        $next->happenedAt = $read;
        $object->happenedAt = $read;
    }

    public function keywordSingleAssignments(EventEntity $entity, stdClass $replacement): void
    {
        $this->accept(default: $first = time());
        $entity->at = $first;
        $first = time();
        $entity->at = $first;
        $this->accept(fn: [$second] = [0]);
        $second = time();
        $entity->created_at = $second;
        $this->accept(return: $entity = $replacement);
        $entity->at = time();
    }

    public function keywordCallForms(EventEntity $entity, $callable, array $callbacks): void
    {
        $callable(default: $entity->at = time());
        ($callable)(match: $entity->at = time());
        $callbacks[0](fn: $entity->at = time());
        new stdClass(class: $entity->at = time());
        $this->accept(first: $this->accept(default: $entity->at = time()), return: $entity->at = time());
    }

    public function realBoundaries(EventEntity $entity, bool $condition): void
    {
        $branch = time();
        if ($condition) {
            $entity->at = $branch;
        }
        $label = time();
        switch ($condition) {
            case false:
                $entity->at = $label;
                break;
            default:
                $entity->created_at = TimeHelper::nowMs();
        }
        $ternary = time();
        $this->accept($condition ? 0 : $entity->at = $ternary);
        $arrow = time();
        $this->accept(fn(EventEntity $entity) => $entity->at = time());
        $entity->at = $arrow;
        $closure = time();
        $this->accept(function (EventEntity $entity): void { $entity->at = time(); });
        $entity->at = $closure;
        $anonymous = time();
        $this->accept(new class {
            public function write(EventEntity $entity): void { $entity->at = time(); }
        });
        $entity->at = $anonymous;
    }

    public function arrayLookalikes(EventEntity $entity): void
    {
        $when = time();
        $this->accept(['default' => $when, 'function' => [$when]], first: ['return' => $when]);
        $entity->at = $when;
    }

    public function stringCallForms(EventEntity $entity, string $name): void
    {
        'callback'(value: $entity->at = time());
        'callback'(default: $entity->at = time());
        "{$name}"(value: $entity->at = time());
        "{$name}"(class: $entity->at = time());
    }

    public function docNativeRebindings(
        EventEntity $first, EventEntity $second, EventEntity $third, EventEntity $fourth, stdClass $replacement,
    ): void {
        $this->accept /** The replacement argument. */ (value: [$first] = [$replacement]);
        $first->at = time();
        $this->accept /** The later replacement. */ (first: 0, value: list($second) = [$replacement]);
        $second->at = time();
        $this->accept(/** The argument name. */ value /** The separator. */ : [$third] = [$replacement]);
        $third->at = time();
        $this->accept(first: 0, /** The argument name. */ value /** The separator. */ : list($fourth) = [$replacement]);
        $fourth->at = time();
    }

    public function docKeywordRebindings(
        EventEntity $first, EventEntity $second, EventEntity $third, EventEntity $fourth, stdClass $replacement,
    ): void {
        $this->accept /** The callable. */ (/** The name. */ function /** The colon. */ : [$first] = [$replacement]);
        $first->at = time();
        $this->accept(first: 0, /** The name. */ fn /** The colon. */ : list($second) = [$replacement]);
        $second->at = time();
        $this->accept(/** The name. */ class /** The colon. */ : /** The target. */ [$third] = [$replacement]);
        $third->at = time();
        $this->accept(first: 0, /** The name. */ if /** The colon. */ : /** The target. */ list($fourth) = [$replacement]);
        $fourth->at = time();
    }

    public function docDirectSinks(EventEntity $entity, EventObject $object, $unknown): void
    {
        $this->accept /** The moment argument. */ (value: $entity->at = time());
        $this->accept(/** The name. */ value: $entity->at = time());
        $this->accept(first: 0, value /** The colon. */ : $entity->at = time());
        $this->accept(value: /** The target. */ $entity->at = time());
        $this->accept(first: 0, /** The name. */ value /** The colon. */ : /** The target. */ $entity->at = time());
        $this->accept /** The callable. */ (value: $object->happenedAt = time());
        $this->accept(value /** The colon. */ : $entity['at'] = time());
        $this->accept(value: /** The target. */ $entity->created_at = TimeHelper::nowMs());
        $this->accept /** The callable. */ (value: $unknown->at = time());
    }

    public function docKeywordSinks(EventEntity $entity): void
    {
        $this->accept /** The callable. */ (/** The name. */ function /** The colon. */ : $entity->at = time());
        $this->accept(first: 0, /** The name. */ fn /** The colon. */ : $entity->at = time());
        $this->accept(/** The name. */ class /** The colon. */ : /** The target. */ $entity->at = time());
        $this->accept(first: 0, /** The name. */ if /** The colon. */ : /** The target. */ $entity->at = time());
        $this->accept /** The callable. */ (default /** The colon. */ : $entity->at = time());
        $this->accept(first: 0, /** The name. */ return: $entity->at = time());
        $this->accept(/** The name. */ match /** The colon. */ : $entity->at = time());
        $this->accept(first: 0, catch /** The colon. */ : $entity->at = time());
        $this->accept(/** The name. */ foreach: $entity->at = time());
        $this->accept(first: 0, case /** The colon. */ : $entity->at = time());
    }

    public function docProofReadsAndRebindings(EventEntity $entity, stdClass $replacement): void
    {
        $when = date(format: 'Y-m-d H:i:s');
        $read = time();
        $local = new EventEntity();
        $objectClass = EventCollection::OBJECT_CLASS;
        $object = $objectClass::create();
        $this->accept /** The callable. */ (/** The name. */ function /** The colon. */ : $when, fn: $local);
        $this->accept(first: 0, /** The name. */ class /** The colon. */ : $objectClass, if: $object);
        $entity->at = $when;
        $entity->at = $when;
        $local->at = $read;
        $next = $objectClass::create();
        $next->happenedAt = $read;
        $object->happenedAt = $read;
        $this->accept(/** The name. */ function /** The colon. */ : [$when] = [0]);
        $this->accept(first: 0, /** The name. */ fn /** The colon. */ : list($local) = [$replacement]);
        $this->accept(/** The name. */ class /** The colon. */ : [$objectClass] = [stdClass::class]);
        $this->accept(first: 0, /** The name. */ if /** The colon. */ : list($object) = [$replacement]);
        $entity->at = $when;
        $local->at = time();
        $later = $objectClass::create();
        $later->happenedAt = time();
        $object->happenedAt = time();
    }

    public function docSingleAssignments(EventEntity $entity): void
    {
        $this->accept(value: /** The nested value. */ $when = time());
        $entity->at = $when;
        $this->accept(/** The name. */ function /** The colon. */ : /** The target. */ $local = new EventEntity());
        $local->at = time();
    }

    public function docAnnotations($unknown): void
    {
        /** @var EventEntity $local */
        $local = $unknown;
        $this->accept /** The callable. */ (/** The name. */ function /** The colon. */ : $local);
        $local->at = time();
        /** @var EventEntity */
        $next = $unknown;
        $this->accept(first: 0, fn /** The colon. */ : $next);
        $next->at = time();
        [$local] = [$unknown];
        $local->at = time();
        /** @var EventEntity $bounded */
        $bounded = $unknown;
        if ($unknown) {
            $bounded->at = time();
        }
    }

    public function docLookalikes(EventEntity $entity, bool $condition): void
    {
        $branch = time();
        if /** The real condition. */ ($condition) {
            $entity->at = $branch;
        }
        $label = time();
        switch ($condition) {
            case /** The case expression. */ false /** The label. */ :
                $entity->at = $label;
                break;
            default /** The label. */ :
                $entity->created_at = time();
        }
        $ternary = time();
        $this->accept($condition ? 0 : /** The arm. */ $entity->at = $ternary);
        $arrow = time();
        $this->accept(fn /** The callback. */ (EventEntity $entity) => $entity->at = time());
        $entity->at = $arrow;
        $closure = time();
        $this->accept(function /** The callback. */ (EventEntity $entity): void { $entity->at = time(); });
        $entity->at = $closure;
        $when = time();
        $this->accept(/** The array. */ ['function' => [$when], 'if' => $when]);
        $entity->at = $when;
    }
}
