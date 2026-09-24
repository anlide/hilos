<?php

namespace Hilos\Core\TruthSource;

/**
 * Abstract Truth Source Registry.
 *
 * Base class for tracking which agents are sources of truth for specific collections.
 * Provides common logic for registration, unregistration, and permission checking.
 *
 * Child classes must:
 *   1. Define their own static $sources array
 *   2. Implement getSources() to return reference to their storage
 *   3. Declare their own collection-wide write check, with their own exception type and their own
 *      arguments - the base fixes no signature for it, because the two halves ask it differently
 *
 * Usage pattern:
 *   - Agent registers as truth source on start:
 *     Registry::register($collection, TruthSourceKeys::all(), $agentId).
 *   - WorkerManager unregisters the agent after its onStop hook: Registry::unregisterAgent($agentId).
 *   - Actions ask the registry before they write.
 *
 * One registration is a {@see TruthSourceGrant}: the rows it covers and the operations it
 * allows on them. A registration that names no operations gets every one of them, which is what
 * every source held before the operation axis existed.
 */
abstract class AbstractTruthSourceRegistry
{
    /**
     * Get sources storage reference.
     *
     * Each child class must override this to return reference to its own static $sources array.
     * This allows proper static inheritance with separate storage per class.
     *
     * @return array<string, array<string, TruthSourceGrant>> Reference to [collection => [agentId => grant]]
     */
    abstract protected static function &getSources(): array;

    /**
     * Register agent as truth source for collection.
     *
     * A repeated registration replaces the agent's grant for that collection rather than
     * widening it: the last claim is the whole claim.
     *
     * The width has no default: a claim says out loud whether it runs over the whole collection
     * or over rows it names, because the pair this replaced could not tell the two apart. The
     * operations do have one, and it is written out case by case rather than unpacked from
     * {@see TruthSourceOperation::ALL}, because PHP accepts only a `new` expression in the
     * default value of a parameter and refuses unpacking a constant there.
     *
     * @param string $collection Collection/table name
     * @param TruthSourceKeys $keys Rows the agent claims - the whole collection, or those it names
     * @param string $agentId Agent ID from agent->getId()
     * @param TruthSourceOperations $operations Operations the agent may perform on those rows
     */
    public static function register(
        string $collection,
        TruthSourceKeys $keys,
        string $agentId,
        TruthSourceOperations $operations = new TruthSourceOperations(
            TruthSourceOperation::Add,
            TruthSourceOperation::Update,
            TruthSourceOperation::Remove,
        ),
    ): void {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            $sources[$collection] = [];
        }
        $sources[$collection][$agentId] = new TruthSourceGrant($keys, $operations);
    }

    /**
     * Unregister agent as truth source for specific collection.
     *
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID
     */
    public static function unregister(string $collection, string $agentId): void
    {
        $sources = &static::getSources();
        if (isset($sources[$collection][$agentId])) {
            unset($sources[$collection][$agentId]);
            // PhpStorm keeps the non-empty narrowing from the isset above and
            // does not model the unset, so it wrongly flags this as always false.
            /** @noinspection PhpConditionAlreadyCheckedInspection */
            if (empty($sources[$collection])) {
                /** @noinspection PhpConditionAlreadyCheckedInspection */
                unset($sources[$collection]);
            }
        }
    }

    /**
     * Unregister agent from all collections.
     *
     * @param string $agentId Agent ID
     */
    public static function unregisterAgent(string $agentId): void
    {
        $sources = &static::getSources();
        foreach ($sources as $collection => $agents) {
            if (isset($agents[$agentId])) {
                unset($sources[$collection][$agentId]);
                // PhpStorm keeps the non-empty narrowing from the isset above and
                // does not model the unset, so it wrongly flags this as always false.
                /** @noinspection PhpConditionAlreadyCheckedInspection */
                if (empty($sources[$collection])) {
                    /** @noinspection PhpConditionAlreadyCheckedInspection */
                    unset($sources[$collection]);
                }
            }
        }
    }

    /**
     * Check if collection has any registered truth source.
     *
     * @param string $collection Collection/table name
     * @return bool True if collection has at least one registered truth source
     */
    public static function hasTruthSource(string $collection): bool
    {
        $sources = &static::getSources();
        return !empty($sources[$collection]);
    }

    /**
     * Check if keys are truth source (don't need external load).
     *
     * @param string $collection Collection/table name
     * @param list<string> $keys Keys to check
     * @return bool True if all given keys are covered by a truth source
     */
    public static function isTruthSource(string $collection, array $keys): bool
    {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            return false;
        }

        foreach ($sources[$collection] as $grant) {
            $allKeysPresent = true;
            foreach ($keys as $key) {
                if (!$grant->keys->covers($key)) {
                    $allKeysPresent = false;
                    break;
                }
            }
            if ($allKeysPresent) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get truth source keys for collection.
     *
     * Null still means "no cover here", and it is also the answer when the collection has grants
     * but every one of them owns no row: a mint-only claim covers nothing to read.
     *
     * A claim over a set names no keys and adds nothing to the answer, so a collection held only
     * by such claims answers null too, as one with no cover. Which rows a set holds is a question
     * about their set column, and this answer is a list of row keys.
     *
     * @param string $collection Collection/table name
     * @return ?TruthSourceKeys Width of the cover over this collection, or null when it has none
     */
    public static function getTruthSourceKeys(string $collection): ?TruthSourceKeys
    {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            return null;
        }

        $allKeys = [];
        foreach ($sources[$collection] as $grant) {
            if ($grant->keys->coversEveryKey()) {
                return TruthSourceKeys::all();
            }
            $allKeys = array_merge($allKeys, $grant->keys->listedKeys());
        }

        return $allKeys === [] ? null : TruthSourceKeys::listed(...array_values(array_unique($allKeys)));
    }

    /**
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID
     * @return ?TruthSourceGrant The agent's grant for this collection, or null when it holds none
     */
    protected static function grantOf(string $collection, string $agentId): ?TruthSourceGrant
    {
        $sources = &static::getSources();

        return $sources[$collection][$agentId] ?? null;
    }

    /**
     * Operations allowed by the grants that cover one key, whoever holds them.
     *
     * The union, not one grant's set: the agent-less write path is judged by the collection as
     * a whole, exactly as its width check already is.
     *
     * @param string $collection Collection/table name
     * @param string $key Row key about to be written
     * @return TruthSourceOperations Operations any covering grant allows, each named once
     */
    protected static function operationsCovering(string $collection, string $key): TruthSourceOperations
    {
        $sources = &static::getSources();
        $operations = new TruthSourceOperations();
        foreach ($sources[$collection] ?? [] as $grant) {
            if (!$grant->keys->covers($key)) {
                continue;
            }
            $operations = $operations->merge($grant->operations);
        }

        return $operations;
    }

    /**
     * Operations allowed by the grants that cover the whole collection, whoever holds them.
     *
     * The union, not one grant's set: the agent-less collection-wide write is judged by the
     * collection as a whole, exactly as its width check already is. A grant over named rows adds
     * nothing here - it does not reach the rows a collection-wide write touches.
     *
     * @param string $collection Collection/table name
     * @return TruthSourceOperations Operations any collection-wide grant allows, each named once
     */
    protected static function operationsCoveringEveryKey(string $collection): TruthSourceOperations
    {
        $sources = &static::getSources();
        $operations = new TruthSourceOperations();
        foreach ($sources[$collection] ?? [] as $grant) {
            if (!$grant->keys->coversEveryKey()) {
                continue;
            }
            $operations = $operations->merge($grant->operations);
        }

        return $operations;
    }
}
