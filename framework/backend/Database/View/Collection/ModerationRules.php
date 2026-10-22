<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\ModerationRulesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ModerationRule as EntityModerationRule;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Collection\ModerationRules as ObjectModerationRules;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Item\ModerationRule;
use Hilos\Hilos;

/**
 * Administrators' own moderation rules, addressed by row id; the rules a check of one kind
 * applies are read by effectiveForKind().
 *
 * @extends DbCollection<ModerationRule, ObjectModerationRules>
 * @method ObjectModerationRules|null getObjectCollection()
 * @method ModerationRule|null first()
 * @method ModerationRule|null last()
 * @method ModerationRule offsetGet(mixed $offset)
 * @property-read ModerationRulesActions $actions Actions for write operations
 */
class ModerationRules extends DbCollection
{
    public const string DB_ITEM_CLASS = ModerationRule::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectModerationRules::class;

    /**
     * The rules a check of one kind applies now: the kind's own rules in the order they were
     * written, or its built-in rules while it has none. Own rules replace the built-in ones
     * whole and are never mixed with them; the list is computed on every call and kept nowhere.
     *
     * The kind is looked up in the published directory whether or not moderation is switched
     * on, so the profile photo kind is unknown while the project takes no profile photos.
     *
     * @param string $kind Moderation kind key
     * @return list<string> Rule texts the check applies
     * @throws InvalidArgumentException When the directory does not publish the kind, the project's directory is
     *     malformed, or a loaded object does not match the collection
     * @throws DatabaseException When the rules cannot be read
     * @throws LogicException When collection class constants are not configured
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    public function effectiveForKind(string $kind): array
    {
        $definition = Hilos::moderationKinds()[$kind]
            ?? throw new InvalidArgumentException("Unknown moderation kind: {$kind}");
        $own = iterator_to_array($this->whereColumnIs(EntityModerationRule::kind, $definition->key), false);
        if ($own === []) {
            return $definition->defaultRules;
        }

        usort($own, static fn (ModerationRule $a, ModerationRule $b): int => $a->id <=> $b->id);
        return array_map(static fn (ModerationRule $rule): string => $rule->rule, $own);
    }
}
