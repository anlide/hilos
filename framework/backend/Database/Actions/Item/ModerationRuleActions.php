<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\ModerationRule as ObjectModerationRule;
use Hilos\Database\View\Item\ModerationRule;
use Hilos\HilosException;

/**
 * @extends DbActions<ModerationRule, ObjectModerationRule>
 * @property-read ObjectModerationRule $object
 */
class ModerationRuleActions extends DbActions
{
    /**
     * Rewrites the rule's text; its kind stays the one it was created for.
     *
     * @param string $rule Rule text, stored as given
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ValidationException When the text is empty, only whitespace, or longer than the column holds
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function update(string $rule): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Moderation rule not found for update (id is null)');
        }
        ObjectModerationRule::assertValidRule($rule);

        $this->object->rule = $rule;
        $this->object->sync();
    }

    /**
     * Removes the rule; once a kind's last own rule is gone, its built-in rules apply again.
     *
     * @throws ItemNotFoundForDeleteException When the row has no persisted id
     * @throws ObjectCollectionNullException When the item is detached from its collection
     * @throws HilosException When ownership, the database or collection refuses removal
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Moderation rule not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
        $this->item->getCollection()?->forgetCachedItem($idString);
    }
}
